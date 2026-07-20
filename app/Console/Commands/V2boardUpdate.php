<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply pending database updates (database/update.sql)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('config:cache');
        DB::connection()->getPdo();
        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            abort(500, 'database/update.sql is missing.');
        }
        $sql = $this->splitStatements($file);
        if (!is_array($sql)) {
            abort(500, 'database/update.sql could not be parsed.');
        }
        $this->info('Applying database updates, please wait...');
        $applied = 0;
        $skipped = 0;
        $problems = [];
        foreach ($sql as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            try {
                // catch \Throwable: on PHP 8 an invalid query raises ValueError,
                // which is an Error rather than an \Exception and would escape.
                DB::select(DB::raw($item));
                $applied++;
            } catch (\Throwable $e) {
                // Re-running this file is normal, so "already exists" is the
                // expected outcome for most statements and stays quiet.
                // Anything else is a real failure and has to be seen: a silent
                // catch here once let a broken statement report success while
                // the column it should have added was never created.
                if ($this->isAlreadyApplied($e)) {
                    $skipped++;
                    continue;
                }
                $problems[] = substr(preg_replace('/\s+/', ' ', $item), 0, 110)
                    . ' -- ' . $e->getMessage();
            }
        }
        \Artisan::call('horizon:terminate');
        if ($problems) {
            $this->error(count($problems) . ' statement(s) failed:');
            foreach ($problems as $p) {
                $this->error('  ' . $p);
            }
            $this->warn("Applied {$applied}, already present {$skipped}. Fix the above and run this again.");
            return 1;
        }
        $this->info("Database is up to date (applied {$applied}, already present {$skipped}). The queue workers were restarted; nothing else is needed.");
        return 0;
    }

    /**
     * Split the file on statement boundaries only.
     *
     * A plain explode on ";" cuts a statement in half as soon as a semicolon
     * appears inside a quoted string - a COLUMN COMMENT containing one is
     * enough - and because failures used to be swallowed, the update simply
     * reported success while the change never happened. Quotes and comments
     * are tracked so a semicolon inside them is treated as text.
     *
     * @return array<int,string>
     */
    private function splitStatements(string $file): array
    {
        $statements = [];
        $current = '';
        $quote = null;          // ' or " while inside a string
        $lineComment = false;   // -- or # to end of line
        $blockComment = false;  /* ... */
        $len = strlen($file);

        for ($i = 0; $i < $len; $i++) {
            $c = $file[$i];
            $next = $i + 1 < $len ? $file[$i + 1] : '';

            if ($lineComment) {
                if ($c === "\n") $lineComment = false;
                continue;
            }
            if ($blockComment) {
                if ($c === '*' && $next === '/') { $blockComment = false; $i++; }
                continue;
            }
            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $next !== '') { $current .= $next; $i++; continue; }
                if ($c === $quote) $quote = null;
                continue;
            }
            if ($c === '-' && $next === '-') { $lineComment = true; $i++; continue; }
            if ($c === '#') { $lineComment = true; continue; }
            if ($c === '/' && $next === '*') { $blockComment = true; $i++; continue; }
            if ($c === "'" || $c === '"') { $quote = $c; $current .= $c; continue; }
            if ($c === ';') { $statements[] = $current; $current = ''; continue; }
            $current .= $c;
        }
        $statements[] = $current;

        return $statements;
    }

    /** Whether the failure just means this statement had already been applied. */
    private function isAlreadyApplied(\Throwable $e): bool
    {
        $m = $e->getMessage();
        foreach (['Duplicate column', 'already exists', 'Duplicate key name', 'Duplicate entry'] as $known) {
            if (stripos($m, $known) !== false) return true;
        }
        return false;
    }
}

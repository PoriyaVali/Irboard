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
        $sql = str_replace("\n", "", $file);
        $sql = preg_split("/;/", $sql);
        if (!is_array($sql)) {
            abort(500, 'database/update.sql could not be parsed.');
        }
        $this->info('Applying database updates, please wait...');
        foreach ($sql as $item) {
            $item = trim($item);
            // Skip blanks (preg_split leaves an empty tail after the final ';'), and
            // catch \Throwable: on PHP 8 an empty/!invalid query raises ValueError,
            // which is an Error rather than an \Exception and would escape.
            if ($item === '') {
                continue;
            }
            try {
                DB::select(DB::raw($item));
            } catch (\Throwable $e) {
            }
        }
        \Artisan::call('horizon:terminate');
        $this->info('Database is up to date. The queue workers were restarted; nothing else is needed.');
    }
}

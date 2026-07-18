<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class V2boardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'IrBoard installer';

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
        try {
            $this->info('==============================================');
            $this->info('               IrBoard  Installer');
            $this->info('==============================================');

            if (\File::exists(base_path() . '/.env')) {
                $securePath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("Admin panel: http(s)://<your-domain>/{$securePath}");
                abort(500, 'Already installed. To reinstall, delete the .env file in this directory.');
            }

            if (!copy(base_path() . '/.env.example', base_path() . '/.env')) {
                abort(500, 'Failed to copy the environment file — check directory permissions.');
            }

            // Database credentials. On aaPanel they are auto-detected from the
            // panel (it stores each site's MySQL name/user/password), so the only
            // thing to enter is the admin email. Anywhere else, ask for them.
            $domain = null;
            $db = $this->detectAaPanelDb($domain);
            if ($db) {
                $this->info('[✓] aaPanel detected — using the database it created for this site: ' . $db['DB_DATABASE']);
            } else {
                $this->info('[i] aaPanel not detected — please enter the database details.');
                $db = [
                    'DB_HOST' => $this->ask('Database host', 'localhost'),
                    'DB_DATABASE' => $this->ask('Database name'),
                    'DB_USERNAME' => $this->ask('Database username'),
                    'DB_PASSWORD' => $this->ask('Database password'),
                ];
            }

            $env = ['APP_KEY' => 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'))] + $db;
            if ($domain) {
                $env['APP_URL'] = 'https://' . $domain;
            }
            $this->saveToEnv($env);

            \Artisan::call('config:clear');
            \Artisan::call('config:cache');
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                abort(500, 'Database connection failed. Check the credentials and that MySQL is running.');
            }
            $file = \File::get(base_path() . '/database/install.sql');
            if (!$file) {
                abort(500, 'database/install.sql not found.');
            }
            $sql = str_replace("\n", "", $file);
            $sql = preg_split("/;/", $sql);
            if (!is_array($sql)) {
                abort(500, 'install.sql has an invalid format.');
            }
            $this->info('Importing the database, please wait...');
            foreach ($sql as $item) {
                $item = trim($item);
                // preg_split leaves an empty tail after the final ';'. An empty query
                // makes PDO::prepare() throw a ValueError, which on PHP 8 is an Error
                // and NOT an \Exception — it escaped the catch below and crashed the
                // whole installer, so a fresh install could never finish.
                if ($item === '') {
                    continue;
                }
                try {
                    DB::select(DB::raw($item));
                } catch (\Throwable $e) {
                }
            }
            $this->info('Database import complete.');
            $email = '';
            while (!$email) {
                $email = $this->ask('Admin email');
            }
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                abort(500, 'Failed to create the admin account, please try again.');
            }

            // IrBoard keeps every panel setting in config/v2board.php, which is
            // gitignored and absent on a fresh clone. Create it with a stable,
            // unguessable admin path (and the site URL when known), then rebuild the
            // config cache in a fresh app (which re-reads the .env we just wrote) so
            // it takes effect. Without this the admin page and the admin API fall
            // back to different default paths and the panel would not open.
            $securePath = substr(bin2hex(random_bytes(12)), 0, 16);
            $configFile = base_path() . '/config/v2board.php';
            if (!\File::exists($configFile)) {
                $lines = "    'app_name' => 'IrBoard',\n    'secure_path' => '{$securePath}',\n";
                if ($domain) {
                    $lines .= "    'app_url' => 'https://{$domain}',\n";
                }
                \File::put($configFile, "<?php\n\nreturn [\n{$lines}];\n");
            } else {
                $securePath = config('v2board.secure_path', $securePath);
            }
            \Artisan::call('config:clear');
            \Artisan::call('config:cache');

            $this->info('All set.');
            $this->info("Admin email: {$email}");
            $this->info("Admin password: {$password}");

            $site = $domain ? ('https://' . $domain) : 'http(s)://<your-domain>';
            $this->info("Open the admin panel at: {$site}/{$securePath}  (you can change the password in the user center).");
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * On aaPanel, read the MySQL credentials the panel created for this site from
     * its own SQLite database (creds are stored there in plaintext). Returns the
     * DB_* values ready for .env, or null when this is not an aaPanel box / no
     * matching site. $domain is filled with the site's domain when found.
     */
    private function detectAaPanelDb(&$domain = null)
    {
        $sqlite = '/www/server/panel/data/default.db';
        if (!@is_readable($sqlite) || !extension_loaded('pdo_sqlite')) {
            return null;
        }
        try {
            $pdo = new \PDO('sqlite:' . $sqlite);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $path = base_path();
            // Reliable path: the aaPanel site whose folder IS this install dir,
            // then the database linked to that site (databases.pid = sites.id).
            $st = $pdo->prepare(
                'SELECT d.name, d.username, d.password, s.name AS domain
                 FROM databases d JOIN sites s ON s.id = d.pid
                 WHERE s.path = ? LIMIT 1'
            );
            $st->execute([$path]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                // Fallback: match the database description (ps) to the dir name.
                $dir = basename($path);
                $st = $pdo->prepare('SELECT name, username, password, ps AS domain FROM databases WHERE ps = ? LIMIT 1');
                $st->execute([$dir]);
                $row = $st->fetch(\PDO::FETCH_ASSOC);
            }
            if (!$row || empty($row['name'])) {
                return null;
            }
            $domain = !empty($row['domain']) ? $row['domain'] : basename($path);
            // aaPanel MySQL listens on both the socket and 127.0.0.1:3306; use TCP
            // so this is portable (and testable off an aaPanel box).
            return [
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3306',
                'DB_DATABASE' => $row['name'],
                'DB_USERNAME' => $row['username'],
                'DB_PASSWORD' => $row['password'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function registerAdmin($email, $password)
    {
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, 'Admin password must be at least 8 characters.');
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        return $user->save();
    }

    private function saveToEnv($data = [])
    {
        function set_env_var($key, $value)
        {
            if (! is_bool(strpos($value, ' '))) {
                $value = '"' . $value . '"';
            }
            $key = strtoupper($key);

            $envPath = app()->environmentFilePath();
            $contents = file_get_contents($envPath);

            preg_match("/^{$key}=[^\r\n]*/m", $contents, $matches);

            $oldValue = count($matches) ? $matches[0] : '';

            if ($oldValue) {
                $contents = str_replace("{$oldValue}", "{$key}={$value}", $contents);
            } else {
                $contents = $contents . "\n{$key}={$value}\n";
            }

            $file = fopen($envPath, 'w');
            fwrite($file, $contents);
            return fclose($file);
        }
        foreach($data as $key => $value) {
            set_env_var($key, $value);
        }
        return true;
    }
}

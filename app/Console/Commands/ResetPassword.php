<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetPassword extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:password {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue a new random password for a user';

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
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) abort(500, 'No user exists with that email address.');
        $password = Helper::guid(false);
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        // Clear the legacy algo/salt pair, otherwise multiPasswordVerify() would
        // still check the old scheme and reject the new bcrypt hash.
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) abort(500, 'Could not save the new password.');
        $this->info("Password reset for {$user->email}");
        $this->info("New password: {$password}");
    }
}

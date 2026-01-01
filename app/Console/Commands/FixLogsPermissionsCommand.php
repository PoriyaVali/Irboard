<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixLogsPermissionsCommand extends Command
{
    protected $signature = 'system:fix-logs-permissions';
    protected $description = 'Fix storage/logs directory permissions';

    public function handle(): int
    {
        $directory = base_path('storage/logs');
        
        exec("sudo chown -R www:www " . escapeshellarg($directory), $output, $code1);
        exec("sudo chmod -R 775 " . escapeshellarg($directory), $output, $code2);
        
        if ($code1 !== 0 || $code2 !== 0) {
            Log::error('✗ Fix logs permissions failed');
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
}

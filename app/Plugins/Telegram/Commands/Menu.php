<?php
namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;

class Menu extends Telegram {
    public $command = '/menu';
    public $description = 'نمایش منوی دکمه‌ای';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;

        $commands = $this->getAllCommands();
        $keyboard = $this->buildKeyboard($commands);
        
        $this->telegramService->sendMessageWithKeyboard(
            $message->chat_id,
            '📱 منوی اصلی - دکمه مورد نظر را انتخاب کنید:',
            $keyboard
        );
    }

    private function getAllCommands(): array
    {
        $commands = [];
        
        foreach (glob(base_path('app/Plugins/Telegram/Commands') . '/*.php') as $file) {
            $className = 'App\\Plugins\\Telegram\\Commands\\' . basename($file, '.php');
            
            if (!class_exists($className)) continue;
            
            try {
                $ref = new \ReflectionClass($className);
                
                if ($ref->hasProperty('command') && $ref->hasProperty('description')) {
                    $instance = $ref->newInstanceWithoutConstructor();
                    
                    $commands[] = [
                        'command' => $instance->command,
                        'description' => $instance->description
                    ];
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }
        
        return $commands;
    }

    private function buildKeyboard(array $commands): array
    {
        $keyboard = [];
        $row = [];
        
        foreach ($commands as $index => $cmd) {
            $row[] = ['text' => $cmd['command']];
            
            // هر ردیف 2 دکمه
            if (($index + 1) % 2 === 0) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        
        // دکمه‌های باقی‌مانده
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }
}
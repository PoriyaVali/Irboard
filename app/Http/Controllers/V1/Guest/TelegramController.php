<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        Log::info('TelegramController instantiated.');

        if (!app()->runningInConsole()) {
            if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
                Log::warning('Unauthorized access attempt detected.', ['ip' => $request->ip()]);
                abort(401);
            }
        }

        $this->telegramService = new TelegramService();
        Log::info('TelegramService initialized successfully.');
    }

    public function webhook(Request $request)
    {
        Log::info('Webhook received with payload.', ['payload' => $request->input()]);

        try {
            $this->formatMessage($request->input());
            $this->formatChatJoinRequest($request->input());
            $this->handle();
            Log::info('Webhook handled successfully.');
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['exception' => $e]);
        }

        return response()->json(['ok' => true], 200);
    }

    public function handle()
    {
        if (!$this->msg) {
            Log::info('No message found in the webhook payload.');
            return;
        }

        $msg = $this->msg;
        $commandName = explode('@', $msg->command);

        Log::info('Processing command.', ['command' => $msg->command]);

        if (count($commandName) == 2) {
            $botName = $this->getBotName();
            if ($commandName[1] === $botName) {
                $msg->command = $commandName[0];
            }
        }

        try {
            $commandHandled = false;
            
            foreach (glob(base_path('app/Plugins/Telegram/Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();

                Log::info('Command class found.', ['command_class' => $class]);

                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    
                    try {
                        $instance->handle($msg);
                        Log::info('Command handled.', ['command' => $msg->command]);
                    } catch (HttpException $e) {
                        // خطاهای abort() را بگیر و به کاربر بفرست
                        Log::error('Command error: ' . $e->getMessage());
                        $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
                    }
                    
                    $commandHandled = true;
                    break;
                }

                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    
                    try {
                        $instance->handle($msg, $match);
                        Log::info('Reply message handled.', ['command' => $msg->command]);
                    } catch (HttpException $e) {
                        // خطاهای abort() را بگیر و به کاربر بفرست
                        Log::error('Reply command error: ' . $e->getMessage());
                        $this->telegramService->sendMessage($msg->chat_id, $e->getMessage());
                    }
                    
                    $commandHandled = true;
                    break;
                }
            }
            
            if (!$commandHandled) {
                Log::warning('Command not handled.', ['command' => $msg->command]);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage(), ['exception' => $e]);
            $this->telegramService->sendMessage($msg->chat_id, 'خطایی رخ داد. لطفا دوباره تلاش کنید.');
        }
    }

    public function getBotName()
    {
        Log::info('Fetching bot name from Telegram API.');

        $response = $this->telegramService->getMe();
        Log::info('Bot name retrieved successfully.', ['bot_name' => $response->result->username]);

        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;

        Log::info('Formatting incoming message.', ['message_data' => $data['message']]);

        $obj = new \StdClass();
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';

        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }

        $this->msg = $obj;

        Log::info('Message formatted successfully.', ['formatted_message' => $this->msg]);
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;

        Log::info('Processing chat join request.', ['request_data' => $data['chat_join_request']]);

        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );

            Log::info('Chat join request declined. User not found.', ['user_id' => $data['chat_join_request']['from']['id']]);
            return;
        }

        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );

            Log::info('Chat join request declined. User service unavailable.', ['user_id' => $user->id]);
            return;
        }

        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );

        Log::info('Chat join request approved.', ['user_id' => $user->id]);
    }
}

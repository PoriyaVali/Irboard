<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** One editable message and a bounded history of views, never a history of actions. */
class TelegramMenu
{
    private const TTL = 14400;
    private string $key;
    private string $api;
    private int $chatId;
    private array $state;
    private bool $stale = false;

    public function __construct(User $user, string $token, ?array $source = null)
    {
        $this->chatId = (int) $user->telegram_id;
        $this->api = "https://api.telegram.org/bot{$token}/";
        $this->key = 'telegram:menu:v1:' . hash('sha256', $token . '|' . $user->id . '|' . $user->telegram_id);
        $this->state = Cache::get($this->key, ['current' => ['name' => 'home', 'args' => []], 'history' => []]);
        // Photo/document notifications cannot be edited as a text menu.
        if (isset($source['text'], $source['message_id'])) {
            $sourceId = (int) $source['message_id'];
            $this->stale = isset($this->state['message_id']) && $this->state['message_id'] !== $sourceId;
            if (!isset($this->state['message_id'])) $this->state['message_id'] = $sourceId;
        }
    }

    public function isStale(): bool
    {
        return $this->stale;
    }

    public function claimAction(string $data): bool
    {
        // Legacy messages without a session still pass through the business validators.
        if (!isset($this->state['actions'], $this->state['revision'])) return true;
        if (!in_array($data, $this->state['actions'], true)) return false;
        // Reserve before any money operation, including the gap before the UI is updated.
        return Cache::add($this->key . ':action:' . hash('sha256', $this->state['revision'] . '|' . $data), true, self::TTL);
    }

    public function visit(string $name, array $args = [], bool $replace = false): void
    {
        $next = ['name' => $name, 'args' => $args];
        if ($name === 'home') {
            $this->state['history'] = [];
        } elseif ($next !== $this->state['current']) {
            $current = $this->state['current'];
            $sameList = $current['name'] === $name && in_array($name, ['plans', 'orders', 'tickets'], true);
            $sameThread = $name === 'ticket' && $current['name'] === 'ticket'
                && ($args['id'] ?? null) === ($current['args']['id'] ?? null);
            if (!$replace && !$sameList && !$sameThread) {
                $this->state['history'][] = $current;
                $this->state['history'] = array_slice($this->state['history'], -16);
            }
        }
        $this->state['current'] = $next;
    }

    public function back(): array
    {
        $target = array_pop($this->state['history']) ?? ['name' => 'home', 'args' => []];
        $this->state['current'] = $target;
        return $target;
    }

    public function render(string $text, array $buttons, string $mode = ''): void
    {
        // Menu content is paged in-place, including very long ticket messages.
        if (mb_strlen($text, 'UTF-8') > 1800) {
            if ($mode === 'HTML') $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
            $mode = '';
        }
        $pages = mb_str_split($text, 1800, 'UTF-8');
        $this->state['content'] = count($pages) > 1 ? ['pages' => $pages, 'buttons' => $buttons] : null;
        $this->write($pages[0] ?? ' ', $this->buttons($buttons, 1, count($pages)), $mode);
    }

    public function page(int $page): bool
    {
        $content = $this->state['content'] ?? null;
        if (!$content) return false;
        $pages = $content['pages'];
        $page = max(1, min($page, count($pages)));
        $this->write($pages[$page - 1], $this->buttons($content['buttons'], $page, count($pages)), '');
        return true;
    }

    private function buttons(array $buttons, int $page, int $pages): array
    {
        $rows = [];
        foreach ($buttons as $row) {
            $row = array_values(array_filter($row, function ($button) {
                if (in_array($button['callback_data'] ?? '', ['main_menu', 'cancel_input', 'menu_back'], true)) return false;
                return !preg_match('/^[^\p{L}\p{N}]*(?:بازگشت|برگشت)(?:\s|$)/u', $button['text'] ?? '');
            }));
            if ($row) $rows[] = $row;
        }
        if ($pages > 1) {
            $row = [];
            if ($page > 1) $row[] = ['text' => '→ متن قبلی', 'callback_data' => 'menu_page_' . ($page - 1)];
            $row[] = ['text' => "متن {$page} از {$pages}", 'callback_data' => 'menu_page_' . $page];
            if ($page < $pages) $row[] = ['text' => 'ادامهٔ متن ←', 'callback_data' => 'menu_page_' . ($page + 1)];
            $rows[] = $row;
        }
        if ($this->state['current']['name'] !== 'home') {
            $rows[] = [
                ['text' => '‹ برگشت', 'callback_data' => 'menu_back'],
                ['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu'],
            ];
        } else {
            $rows[] = [['text' => '🔃 به‌روزرسانی', 'callback_data' => 'main_menu']];
        }
        return $rows;
    }

    private function write(string $text, array $buttons, string $mode): void
    {
        $this->state['actions'] = array_column(array_merge(...$buttons), 'callback_data');
        $this->state['revision'] = bin2hex(random_bytes(12));
        $data = ['chat_id' => $this->chatId, 'text' => $text, 'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_UNICODE)];
        if ($mode) $data['parse_mode'] = $mode;
        $oldId = $this->state['message_id'] ?? null;
        $method = $oldId ? 'editMessageText' : 'sendMessage';
        if ($oldId) $data['message_id'] = $oldId;
        try {
            $response = Http::timeout(15)->post($this->api . $method, $data);
            $description = (string) $response->json('description');
            if ($response->status() === 400 && $mode && stripos($description, 'parse entities') !== false) {
                unset($data['parse_mode']);
                if ($mode === 'HTML') $data['text'] = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
                $response = Http::timeout(15)->post($this->api . $method, $data);
                $description = (string) $response->json('description');
            }
            $unchanged = $method === 'editMessageText' && $response->status() === 400
                && stripos($description, 'message is not modified') !== false;
            $missing = $response->status() === 400 && preg_match("/message (?:to edit not found|can't be edited|can not be edited)|message_id_invalid/i", $description);
            if ($method === 'editMessageText' && $missing) {
                unset($data['message_id']);
                $response = Http::timeout(15)->post($this->api . 'sendMessage', $data);
            }
            if ($response->json('ok') || $unchanged) {
                $newId = $response->json('result.message_id');
                if ($newId) $this->state['message_id'] = (int) $newId;
                Cache::put($this->key, $this->state, self::TTL);
                if ($oldId && $newId && $oldId !== (int) $newId) {
                    Http::timeout(8)->post($this->api . 'editMessageReplyMarkup', [
                        'chat_id' => $this->chatId, 'message_id' => $oldId,
                        'reply_markup' => json_encode(['inline_keyboard' => []]),
                    ]);
                }
            } else {
                // Rate limits and transient errors must not create duplicate menu messages.
                Log::warning('Telegram menu update rejected', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram menu unavailable', ['exception' => get_class($e)]);
        }
    }
}

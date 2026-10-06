<?php

namespace App\Console\Commands;

use App\Services\ExchangeService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells the admins on Telegram when the dollar rate stops updating.
 *
 * ## Why this exists
 *
 * On 2026-10-05 two of the relay's three sources changed their pages. The
 * relay rightly refused to publish a one-source "median", and the price every
 * USD-priced plan is multiplied by froze. Nothing said so - the panel's log
 * even read "✓ Rate updated" every fifteen minutes while it re-read the same
 * number - and it was found a day later by someone looking. The dollar had
 * risen 16% in the two weeks before; a silent freeze in weeks like those sells
 * every plan below its price.
 *
 * ## Two alarms, because they mean different things
 *
 * - frozen: no fresh reading from ANY source for exchange.alert_after. Plan
 *   prices have stopped following the market - act now.
 * - relay: the relay's own reading is that old. The panel is covering by
 *   reading the market itself, so prices are still right, but it is down to
 *   its own scrapers and the relay needs fixing.
 *
 * Each is sent when it starts, repeated every REPEAT seconds while it lasts,
 * and followed by one all-clear when it ends - never on every run.
 */
class ExchangeWatch extends Command
{
    protected $signature = 'exchange:watch {--test : send one test alert to the admins and exit}';

    protected $description = 'Alert the admins on Telegram when the dollar rate stops updating';

    private const STATE = 'exchange_watch_state';

    private const REPEAT = 6 * 3600;

    public function handle(): int
    {
        if ($this->option('test')) {
            $this->send("🔔 آزمایش هشدار نرخ دلار\nاگر این پیام رسیده، هشدار تلگرامی نرخ دلار کار می‌کند.");
            $this->info('test alert queued');
            return self::SUCCESS;
        }

        // Makes sure a reading was attempted recently. Inside the rate's
        // fifteen-minute window this is a cache hit and asks nobody.
        ExchangeService::getCurrentRate();

        $now = time();
        $limit = (int) config('exchange.alert_after', 7200);
        $f = ExchangeService::freshness();
        $state = Cache::get(self::STATE, []);

        // A panel that has never recorded a reading - just deployed, or never
        // able to reach anything - counts from the first time this ran, so a
        // source that never answers is still reported.
        $since = $state['since'] ?? ($state['since'] = $now);

        $ages = [
            'frozen' => $now - ($f['fresh_at'] ?? $since),
            'relay' => config('exchange.relay_url') ? $now - ($f['relay_read_at'] ?? $since) : 0,
        ];

        foreach ($ages as $name => $age) {
            $alerting = $state[$name] ?? null;
            if ($age > $limit) {
                if (!$alerting || $now - $alerting['sent_at'] >= self::REPEAT) {
                    $this->send($this->problem($name, $age, $ages['frozen'] > $limit, $f));
                    $state[$name] = ['sent_at' => $now];
                }
            } elseif ($alerting) {
                $this->send($this->allClear($name, $f));
                unset($state[$name]);
            }
        }

        Cache::forever(self::STATE, $state);
        $this->line(sprintf('frozen %ds, relay %ds, limit %ds', $ages['frozen'], $ages['relay'], $limit));

        return self::SUCCESS;
    }

    private function problem(string $name, int $age, bool $frozen, array $f): string
    {
        $hours = sprintf('%d ساعت', intdiv($age, 3600));

        if ($name === 'frozen') {
            // The rate plans are priced at right now, whatever it came from.
            $cached = Cache::get('exchange_rate');
            $inUse = is_array($cached) ? ($cached['rate'] ?? null) : null;
            return "🔴 نرخ دلار {$hours} است از هیچ منبعی تازه نشده.\n"
                . 'قیمت پلن‌ها روی نرخ ' . $this->toman($inUse ?? ($f['last_good']['rate'] ?? null)) . ' تومان مانده است.';
        }

        $text = "⚠️ سرور ایران (رله) {$hours} است نرخ دلار تازه نداده.";
        if (!$frozen) {
            $source = $f['last_good']['source'] ?? '';
            $text .= "\nپنل فعلاً نرخ را مستقیم از {$source} می‌خواند: " . $this->toman($f['last_good']['rate'] ?? null) . ' تومان.';
        }
        return $text;
    }

    private function allClear(string $name, array $f): string
    {
        return $name === 'frozen'
            ? 'نرخ دلار دوباره به‌روز می‌شود: ' . $this->toman($f['last_good']['rate'] ?? null) . ' تومان. ✅'
            : 'سرور ایران (رله) دوباره نرخ دلار تازه می‌دهد. ✅';
    }

    private function toman($rate): string
    {
        return $rate ? number_format((int) $rate) : '?';
    }

    private function send(string $text): void
    {
        Log::warning('exchange:watch alert', ['text' => $text]);
        (new TelegramService())->sendMessageToAdminsBySwitch($text);
    }
}

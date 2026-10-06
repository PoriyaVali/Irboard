<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramJob;
use App\Services\ExchangeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\PanelFixtures;
use Tests\TestCase;

/**
 * The dollar rate every USD-priced plan is multiplied by, and the alarm that
 * says when it stops moving.
 *
 * The case that started this (2026-10-05): the relay froze its price and this
 * panel kept taking the frozen number for a day while its own tgju reader
 * worked. No network is reached - every source and Telegram are faked.
 */
class ExchangeRateTest extends TestCase
{
    use PanelFixtures;

    private const RELAY = 'https://relay.test/get-dollar-price.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        // setUpPanel seeds a rate so order tests never fetch one; these tests
        // are about fetching it.
        Cache::forget('exchange_rate');
        config([
            'exchange.relay_url' => self::RELAY,
            'exchange.relay_max_age' => 7200,
            'exchange.alert_after' => 7200,
            'v2board.telegram_bot_enable' => 1,
        ]);
        Queue::fake();
    }

    /** @var array{0: mixed, 1: int} body and status the fake relay answers with */
    private $relay;

    /** @var string the fake tgju page */
    private $tgju;

    private $faked = false;

    /**
     * Sets what each source answers. Can be called again mid-test: the fake is
     * registered once and reads these properties on every request, because on
     * this Laravel a second Http::fake() adds stubs AFTER the first ones and
     * the first match wins.
     *
     * @param int|null $ageSeconds age of the relay's reading; $relayRate null = the relay is down
     */
    private function fakeSources(?int $relayRate, ?int $ageSeconds, ?int $tgjuToman): void
    {
        $this->relay = $relayRate === null
            ? ['', 502]
            : [['success' => true, 'price' => $relayRate, 'timestamp' => time() - (int)$ageSeconds], 200];

        $this->tgju = $tgjuToman === null
            ? str_repeat('<p>no dollar column here</p>', 100)
            : str_repeat('<p>filler</p>', 100)
                . '<td data-col="info.last_trade.PDrCotVal">' . number_format($tgjuToman * 10) . '</td>';

        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'relay.test/*' => function () {
                return Http::response($this->relay[0], $this->relay[1]);
            },
            'www.tgju.org/*' => function () {
                return Http::response($this->tgju);
            },
            // alanchand no longer carries the row this panel reads.
            'alanchand.com/*' => function () {
                return Http::response(str_repeat('<p>no row</p>', 200));
            },
        ]);
    }

    private function admin(): void
    {
        $this->makeUser(['is_admin' => 1, 'telegram_id' => 424242]);
    }

    /** @return string[] the texts of the Telegram messages queued so far */
    private function alerts(): array
    {
        $texts = [];
        Queue::assertPushed(SendTelegramJob::class, function (SendTelegramJob $job) use (&$texts) {
            $texts[] = \Closure::bind(function () { return $this->text; }, $job, SendTelegramJob::class)();
            return true;
        });
        return $texts;
    }

    public function testAFreshRelayRateIsUsed()
    {
        $this->fakeSources(269000, 300, 268000);

        $this->assertSame(269000, ExchangeService::getCurrentRate());
        $this->assertSame('relay', Cache::get(ExchangeService::LAST_GOOD)['source']);
    }

    public function testAStaleRelayRateLosesToTheMarketReadDirectly()
    {
        // The 2026-10-05 freeze: relay a day old, tgju live.
        $this->fakeSources(269275, 86400, 269000);

        $this->assertSame(269000, ExchangeService::getCurrentRate());
        $this->assertSame('tgju', Cache::get(ExchangeService::LAST_GOOD)['source']);
        $this->assertEqualsWithDelta(time() - 86400, Cache::get(ExchangeService::RELAY_READ_AT), 5);
    }

    public function testAStaleRelayRateIsStillBetterThanNothing()
    {
        $this->fakeSources(269275, 86400, null);

        $this->assertSame(269275, ExchangeService::getCurrentRate());
        $this->assertNull(Cache::get(ExchangeService::FRESH_AT), 'a stale reading is not a fresh one');
    }

    public function testAColdStartPrefersTheLastRealReadingToTheConfiguredFallback()
    {
        config(['exchange.fallback_rate' => 107000]);
        Cache::forever(ExchangeService::LAST_GOOD, ['rate' => 268500, 'source' => 'tgju', 'at' => time() - 3 * 86400]);
        $this->fakeSources(null, null, null);

        $this->assertSame(268500, ExchangeService::getCurrentRate());
    }

    public function testNothingIsSentWhileTheRateIsFresh()
    {
        $this->admin();
        $this->fakeSources(269000, 300, 268000);

        $this->artisan('exchange:watch')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function testAStaleRelayIsReportedOnceWhileThePanelCovers()
    {
        $this->admin();
        $this->fakeSources(269275, 3 * 3600, 269000);

        $this->artisan('exchange:watch')->assertExitCode(0);
        $this->artisan('exchange:watch')->assertExitCode(0);

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'one alert, not one per run');
        $this->assertStringContainsString('رله', $alerts[0]);
        $this->assertStringContainsString('tgju', $alerts[0]);
        $this->assertStringContainsString('269,000', $alerts[0]);
    }

    public function testAFrozenRateIsReportedAndThenClearedOnce()
    {
        $this->admin();
        // Every source down; the last fresh reading was three hours ago.
        Cache::forever(ExchangeService::FRESH_AT, time() - 3 * 3600);
        Cache::forever(ExchangeService::RELAY_READ_AT, time() - 3 * 3600);
        Cache::forever(ExchangeService::LAST_GOOD, ['rate' => 269275, 'source' => 'relay', 'at' => time() - 3 * 3600]);
        Cache::put('exchange_rate', ['rate' => 269275, 'time' => time() - 3600, 'date' => ''], 3600);
        $this->fakeSources(null, null, null);

        $this->artisan('exchange:watch')->assertExitCode(0);

        $alerts = $this->alerts();
        $this->assertCount(2, $alerts, 'frozen, and the relay among the reasons');
        $this->assertStringContainsString('🔴', $alerts[0]);
        $this->assertStringContainsString('269,275', $alerts[0]);

        // The relay comes back.
        Cache::forget('exchange_rate');
        $this->fakeSources(268990, 120, 268000);
        $this->artisan('exchange:watch')->assertExitCode(0);
        $this->artisan('exchange:watch')->assertExitCode(0);

        $alerts = $this->alerts();
        $this->assertCount(4, $alerts, 'one all-clear each, once');
        $this->assertStringContainsString('268,990', $alerts[2]);
        $this->assertStringContainsString('✅', $alerts[3]);
    }

    public function testATestAlertReachesTheAdmin()
    {
        $this->admin();

        $this->artisan('exchange:watch', ['--test' => true])->assertExitCode(0);

        Queue::assertPushed(SendTelegramJob::class, 1);
    }

    public function testAPanelWithoutARelayNeverReportsOne()
    {
        $this->admin();
        config(['exchange.relay_url' => '']);
        // Watching for a day already, and never a relay reading - there is no relay.
        Cache::forever('exchange_watch_state', ['since' => time() - 86400]);
        $this->fakeSources(null, null, 269000);

        $this->artisan('exchange:watch')->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}

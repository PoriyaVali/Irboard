<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeService
{
    /** When this panel last got a FRESH reading, from any source. */
    public const FRESH_AT = 'exchange_fresh_at';

    /** When the relay's last valid reading was taken, by the relay's own clock. */
    public const RELAY_READ_AT = 'exchange_relay_read_at';

    /** The last fresh reading itself: ['rate' => int, 'source' => string, 'at' => int]. */
    public const LAST_GOOD = 'exchange_last_good';

    public static function getCurrentRate(): int
    {
        $cached = Cache::get('exchange_rate');
        
        /*
         * 🔑 Fifteen minutes, not an hour.
         *
         * The relay publishes a new median every five, so an hour of cache here
         * threw most of that away: a rate could be three cycles old before this
         * even looked. The chain from a market move to a customer seeing a new
         * price used to be up to three hours of stacked hourly steps, none of
         * them aligned with each other.
         *
         * The cost of asking more often is one HTTP request to a box that
         * answers in a third of a second.
         */
        if ($cached && is_array($cached)) {
            $age = time() - $cached['time'];
            if ($age < 900) {
                return $cached['rate'];
            }
        }
        
        $rate = self::fetchRate();
        
        if ($rate) {
            Cache::put('exchange_rate', [
                'rate' => $rate,
                'time' => time(),
                'date' => date('Y-m-d H:i:s')
            ], 3600);   // kept longer than the read window on purpose: a stale
                        // entry is what plausible() compares against and what
                        // getCurrentRate() falls back to when every source fails.
            
            Log::info('✓ Rate updated', ['rate' => $rate]);
            return $rate;
        }
        
        if ($cached && isset($cached['rate'])) {
            Log::warning('Using old cache', ['rate' => $cached['rate']]);
            return $cached['rate'];
        }

        // The cache entry above expires after an hour; the last real reading
        // does not. However old, it is a dollar price - the configured
        // fallback below is a number someone typed in and forgot.
        $last = Cache::get(self::LAST_GOOD);
        if (is_array($last) && !empty($last['rate'])) {
            Log::warning('Using the last good rate', ['rate' => $last['rate'], 'read_at' => date('Y-m-d H:i:s', $last['at'])]);
            return (int) $last['rate'];
        }

        $fallback = config('exchange.fallback_rate', 107000);
        Log::warning('Using fallback', ['rate' => $fallback]);
        return $fallback;
    }
    
    private static function fetchRate(): ?int
    {
        /*
         * 🔑 The relay first when one is configured - and nothing breaks when
         * none is.
         *
         * An operator running this panel on their own has no relay, and must
         * still get a real free-market rate. So the two scrapers below are not
         * decoration: they are the whole answer for a standalone install, and
         * both were verified to answer from outside Iran.
         *
         * ⚠️ The comment that used to sit here said alanchand does not serve
         * the دلار آمریکا row to a non-Iranian address. Measured again on
         * 2026-08-31 from this panel in Germany, it does - the row and its
         * price are both present. What actually broke that source was a span
         * the site added inside the cell; see fetchFromAlanchand.
         *
         * Two scrapers rather than one because a markup change kills a scraper
         * silently, and a single source means the price simply stops moving
         * with nothing to notice it.
         *
         * ❌ fetchFromExchangeRateAPI is deliberately NOT in this list. It
         * answers with a different rate entirely - 152,320 against a real
         * 203,500 when it was last measured - and it is not the free-market
         * dollar this panel prices in. It is worse than an absent source: a
         * readable, confident, wrong number. At that distance plausible() does
         * not save us either, since a 27% gap sits inside its 35% band. The
         * method is kept for manual diagnosis only.
         *
         * 🔴 A STALE relay reading no longer wins. Until 2026-10-06 any answer
         * from the relay ended the search, so when two of the relay's sources
         * died and it froze its price, this panel took the frozen number every
         * fifteen minutes for a day - while fetchFromTgju, a few lines down,
         * read the live market from here without trouble and was never asked.
         * Past exchange.relay_max_age the relay's number is only kept as the
         * last resort, below every source that can say what the dollar costs
         * now.
         */
        $staleRelay = null;

        if (config('exchange.relay_url')) {
            try {
                $relay = self::fetchFromRelay();
                // Two gates, and they ask different questions. isValidRate asks
                // whether this could be a dollar price at all; plausible() asks
                // whether it is the same currency we read an hour ago. The
                // second is the one that catches a parse landing on the euro.
                if ($relay && self::isValidRate($relay['rate']) && self::plausible($relay['rate'])) {
                    Cache::forever(self::RELAY_READ_AT, time() - $relay['age']);
                    if ($relay['age'] <= (int) config('exchange.relay_max_age', 7200)) {
                        Log::info('✓ fetchFromRelay', ['rate' => $relay['rate']]);
                        return self::fresh($relay['rate'], 'relay');
                    }
                    $staleRelay = $relay['rate'];
                    Log::warning('Relay rate is stale - reading the market directly', [
                        'rate' => $relay['rate'],
                        'age_minutes' => intdiv($relay['age'], 60),
                    ]);
                }
            } catch (\Exception $e) {
                Log::debug('✗ fetchFromRelay', ['error' => $e->getMessage()]);
            }
        }

        $scrapers = ['fetchFromAlanchand' => 'alanchand', 'fetchFromTgju' => 'tgju'];
        foreach ($scrapers as $method => $source) {
            try {
                $rate = self::$method();
                if (self::isValidRate($rate) && self::plausible($rate)) {
                    Log::info("✓ {$method}", ['rate' => $rate]);
                    return self::fresh($rate, $source);
                }
            } catch (\Exception $e) {
                Log::debug("✗ {$method}", ['error' => $e->getMessage()]);
            }
        }

        // Still a real dollar price, only an old one - better than the cache
        // or the configured fallback, and exchange:watch reports the freeze.
        if ($staleRelay !== null) {
            Log::warning('No fresh source answered - keeping the stale relay rate', ['rate' => $staleRelay]);
            return $staleRelay;
        }

        return null;
    }

    /** Record a fresh reading - what exchange:watch and the cold-start fallback rely on. */
    private static function fresh(int $rate, string $source): int
    {
        Cache::forever(self::FRESH_AT, time());
        Cache::forever(self::LAST_GOOD, ['rate' => $rate, 'source' => $source, 'at' => time()]);
        return $rate;
    }

    /**
     * How fresh the rate is, for exchange:watch.
     *
     * @return array{fresh_at: ?int, relay_read_at: ?int, last_good: ?array}
     */
    public static function freshness(): array
    {
        return [
            'fresh_at' => Cache::get(self::FRESH_AT),
            'relay_read_at' => Cache::get(self::RELAY_READ_AT),
            'last_good' => Cache::get(self::LAST_GOOD),
        ];
    }
    
    /**
     * Is this plausibly a dollar price at all?
     *
     * 🔴 The ceiling used to be 200,000 and the market went past it. Every
     * correct reading was then rejected, Pattern 1 and 2 fell through, and a
     * page-wide number scrape returned some other currency instead - 146,700
     * against a real 203,200. Plans priced in USD were sold at 72% of their
     * intended price, hourly, for as long as that lasted.
     *
     * So this band is now wide enough to be about catching a *parse* error - a
     * three-digit fragment, a gold price in the millions - and not about
     * having an opinion on the exchange rate. Deciding what the dollar is
     * allowed to cost is not this function's job, and the last time it tried,
     * it was wrong in the expensive direction.
     *
     * The check that actually catches a wrong number is plausible() below.
     */
    private static function isValidRate(?int $rate): bool
    {
        return $rate && $rate >= 50000 && $rate <= 2000000;
    }

    /**
     * Is this the same currency we read last time?
     *
     * 🔑 The real defence, and the one that would have caught today's bug on
     * its own. An absolute band cannot tell a dollar from a euro; a rate that
     * moved 38% in an hour can only be a different number, not a different
     * price. Real moves are percent-a-day, not tens-of-percent-an-hour.
     *
     * Rejecting means falling back to the cached rate, which is a real dollar
     * price from an hour ago. That is always better than a confident reading of
     * something else entirely.
     */
    private static function plausible(int $rate): bool
    {
        $cached = Cache::get('exchange_rate');
        if (!is_array($cached) || empty($cached['rate'])) {
            return true;    // nothing to compare against
        }

        $drift = abs($rate - $cached['rate']) / $cached['rate'];
        if ($drift > 0.35) {
            Log::warning('Rate rejected: implausible jump', [
                'previous' => $cached['rate'],
                'candidate' => $rate,
                'drift' => round($drift * 100) . '%',
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * The rate as read from inside Iran, by the relay.
     *
     * The relay reads several Iranian sources every five minutes and publishes
     * their median. This just asks it, and reports how old that reading is -
     * by the relay's own `timestamp`, not by when we asked - so fetchRate can
     * tell a live price from one the relay has been unable to refresh.
     *
     * A relay that sends no timestamp is taken as fresh, as before.
     *
     * @return array{rate: int, age: int}|null age in seconds
     */
    private static function fetchFromRelay(): ?array
    {
        $url = config('exchange.relay_url');
        if (!$url) {
            return null;
        }

        // ⚠️ connect_timeout as a Guzzle option, not ->connectTimeout(): that
        // helper does not exist on this panel's Laravel, and the call died
        // inside the try/catch as "Method ... does not exist" - a silent
        // fallthrough to the wrong source.
        $res = Http::timeout(12)->withOptions(['connect_timeout' => 6])->get($url);
        if (!$res->successful()) {
            return null;
        }

        $data = $res->json();
        if (!is_array($data) || !isset($data['price']) || !is_numeric($data['price'])) {
            return null;
        }

        // The relay says so itself when it has fallen back to its built-in
        // default rather than a real reading. That is not a price, it is a
        // placeholder, and it must not become ours.
        if (!empty($data['fallback'])) {
            Log::warning('Relay is serving its own fallback price, not a real reading');
            return null;
        }

        $readAt = isset($data['timestamp']) && is_numeric($data['timestamp']) ? (int) $data['timestamp'] : time();

        return ['rate' => (int) $data['price'], 'age' => max(0, time() - $readAt)];
    }

    /**
     * تبدیل اعداد فارسی به انگلیسی
     */
    private static function persianToEnglish(string $string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($persian, $english, $string);
    }
    
    /**
     * Scraping: Alanchand
     */
/**
 * Scraping: Alanchand
 */
	private static function fetchFromAlanchand(): ?int
	{
		$html = Http::withoutVerifying()
			->timeout(15)
			->withHeaders([
				'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'fa-IR,fa;q=0.9',
			])
			->get('https://alanchand.com/')
			->body();
    
		if (strlen($html) < 1000) {
			return null;
		}
    
		// تبدیل اعداد فارسی به انگلیسی
		$html = self::persianToEnglish($html);
    
		/*
		 * Anchored on the row's own title and its sellPrice cell, then simply
		 * the digits that follow.
		 *
		 * 🔑 This used to exist as two patterns and both required `</td>`
		 * immediately after the captured number. The site now places a span
		 * between the figure and the end of the cell:
		 *
		 *     <td class="sellPrice text-center">210,600<span ...
		 *
		 * so both matched nothing and this source went silently dead - no
		 * error, just a rate that stopped moving. What makes a pattern safe
		 * here is the label it starts from, never how tightly it grips
		 * whatever markup happens to follow the value.
		 */
		if (preg_match('/<tr[^>]*title="قیمت دلار آمریکا"[^>]*>.*?sellPrice[^>]*>\s*([0-9][0-9,]*)/su', $html, $match)) {
			$price = (int) str_replace([',', '،', ' '], '', $match[1]);

			if (self::isValidRate($price)) {
				Log::debug('Alanchand sellPrice', ['raw' => $match[1], 'price' => $price]);
				return $price;
			}
		}
    
		/*
		 * 🔴 There was a Pattern 3 here and it was the whole bug.
		 *
		 * It scraped every NN,NNN number from the entire page - euro, pound,
		 * gold, crypto, anything - kept the ones inside the valid band and
		 * returned the *second* of them. When the two patterns above failed,
		 * which they did the moment the dollar went past the old 200,000
		 * ceiling, it confidently returned a different currency: 146,700
		 * against a real 203,200, hourly, and every plan priced in USD was sold
		 * at 72% of its intended price for as long as that lasted.
		 *
		 * Nothing downstream could tell. It is a number, it is in range, it
		 * passes every check - and it is the price of something else.
		 *
		 * Returning null is strictly better. The caller falls back to the
		 * cached rate, which is a real dollar price from an hour ago; failing
		 * that, to the configured fallback. Both are honest about being old.
		 * A wrong number is not.
		 */
		Log::warning('Alanchand: the دلار آمریکا row did not parse', [
			'html_bytes' => strlen($html),
		]);
		return null;
	}

    /**
     * Scraping: TGJU - a second source that does not need the relay.
     *
     * 🔑 Why this exists at all: without it a panel with no relay configured
     * has exactly one way to learn the rate, so the day alanchand changes its
     * markup - which is the failure that has already happened twice - the
     * price silently stops moving and every USD plan is sold at yesterday's
     * number. Two independent sources is the difference between a bad day and
     * a bad month.
     *
     * ⚠️ This page quotes the dollar in RIAL - it is `price_dollar_rl` - so it
     * is a factor of ten from everything else here. Getting that wrong would
     * not look like an error: 21,000 passes every plausibility check that only
     * asks whether a number could be a price, and would sell every plan for a
     * tenth of its value.
     */
    private static function fetchFromTgju(): ?int
    {
        $html = Http::withoutVerifying()
            ->timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept-Language' => 'fa-IR,fa;q=0.9',
            ])
            ->get('https://www.tgju.org/profile/price_dollar_rl')
            ->body();

        if (strlen($html) < 1000) {
            return null;
        }

        $html = self::persianToEnglish($html);

        // Anchored on the named data column, never on position in the page.
        if (preg_match('/data-col="info\.last_trade\.PDrCotVal"[^>]*>\s*([0-9][0-9,]*)/su', $html, $match)) {
            $rial = (int) str_replace([',', '،', ' '], '', $match[1]);
            $toman = intdiv($rial, 10);

            if (self::isValidRate($toman)) {
                Log::debug('TGJU dollar', ['rial' => $rial, 'toman' => $toman]);
                return $toman;
            }
        }

        Log::warning('TGJU: the dollar column did not parse', ['html_bytes' => strlen($html)]);
        return null;
    }

    /**
     * API: ExchangeRate
     */
    private static function fetchFromExchangeRateAPI(): ?int
    {
        $response = Http::timeout(10)->get('https://open.er-api.com/v6/latest/USD');
        
        if (!$response->successful()) {
            return null;
        }
        
        $data = $response->json();
        
        if (isset($data['rates']['IRR'])) {
            $irrRate = (int) $data['rates']['IRR'];
            $tomanRate = (int) round($irrRate / 10);
            
            Log::debug('ExchangeRate-API', [
                'irr' => $irrRate,
                'toman' => $tomanRate
            ]);
            
            return $tomanRate;
        }
        
        return null;
    }
    
    public static function getRateData(): array
    {
        $cached = Cache::get('exchange_rate');
        return [
            'rate' => $cached['rate'] ?? self::getCurrentRate(),
            'updated_at' => $cached['time'] ?? time(),
            'date' => $cached['date'] ?? date('Y-m-d H:i:s'),
        ];
    }
    
    public static function usdToIrr(?float $usdAmount): int
    {
        if (!$usdAmount || $usdAmount <= 0) return 0;
        return (int) round($usdAmount * self::getCurrentRate());
    }
    
    public static function irrToUsd(int $irrAmount): float
    {
        if ($irrAmount <= 0) return 0;
        $rate = self::getCurrentRate();
        return $rate > 0 ? round($irrAmount / $rate, 2) : 0;
    }
    
    public static function roundDown(int $irrAmount): int
    {
        if ($irrAmount <= 0) return 0;
        return $irrAmount >= 1000 
            ? (int) floor($irrAmount / 1000) * 1000
            : (int) floor($irrAmount / 100) * 100;
    }
    
    public static function clearCache(): void
    {
        Cache::forget('exchange_rate');
        Log::info('Cache cleared');
    }
    
    public static function testAll(): array
    {
        $results = [];
        
        $sources = [
            'Alanchand' => 'fetchFromAlanchand',
            'TGJU' => 'fetchFromTgju',
            // Listed here on purpose even though it is not a pricing source:
            // seeing how far off it is, next to the two that are trusted, is
            // the point of a diagnostic.
            'ExchangeRate-API' => 'fetchFromExchangeRateAPI',
        ];
        
        foreach ($sources as $name => $method) {
            $start = microtime(true);
            try {
                $rate = self::$method();
                $time = round((microtime(true) - $start) * 1000);
                
                $results[$name] = [
                    'success' => $rate !== null,
                    'rate' => $rate,
                    'time_ms' => $time,
                    'valid' => self::isValidRate($rate)
                ];
            } catch (\Exception $e) {
                $results[$name] = [
                    'success' => false,
                    'error' => substr($e->getMessage(), 0, 80)
                ];
            }
        }
        
        return $results;
    }
}
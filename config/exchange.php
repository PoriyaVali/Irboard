<?php

return [
    /*
     * 🔑 Where the rate really comes from.
     *
     * The relay is in Iran, and that is the entire point: alanchand.com does
     * not serve the دلار آمریکا row to an address outside the country, so
     * scraping it from this panel in Germany never read the dollar at all. The
     * relay reads it hourly and answers this in about a third of a second.
     */
    'relay_url' => env('EXCHANGE_RELAY_URL', 'https://drmobjay.com/get-dollar-price.php'),

    /*
     * ⚠️ Only ever reached when every source failed AND there is no cached
     * rate - a cold start during an outage. It is a placeholder, not a price,
     * and it has been left far below the market long enough to be dangerous if
     * anything ever priced from it. Keep it roughly current.
     */
    'fallback_rate' => env('EXCHANGE_FALLBACK_RATE', 107000),
    'cache_ttl' => env('EXCHANGE_CACHE_TTL', 3600),
];
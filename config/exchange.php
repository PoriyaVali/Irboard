<?php

return [
    /*
     * An OPTIONAL private relay that has already read the rate.
     *
     * 🔴 This used to default to `https://drmobjay.com/get-dollar-price.php`,
     * which is one specific deployment's own server. Every panel installed
     * from this repository therefore called that host on a schedule, without
     * its operator ever choosing to, and inherited whatever it happened to be
     * serving. A default belongs to whoever installs the software, not to
     * whoever wrote it.
     *
     * Empty is now the default, and ExchangeService simply leaves the relay
     * out of its source list when nothing is set - it is not an error, and
     * nothing has to be reachable for a standalone panel to price correctly.
     *
     * Set it only if you run such a service yourself. It must answer JSON with
     * a `price` in TOMAN. It is worth having when the panel is hosted outside
     * Iran and you would rather read the rate from inside it, but it is an
     * optimisation, not a requirement.
     */
    'relay_url' => env('EXCHANGE_RELAY_URL', ''),

    /*
     * Seconds the relay's own reading may age before this panel stops taking
     * it and reads the market itself (the relay's number is then only the last
     * resort). A relay publishes every few minutes, so two hours means it has
     * missed a couple of dozen runs: its sources are broken, not slow.
     */
    'relay_max_age' => env('EXCHANGE_RELAY_MAX_AGE', 7200),

    /*
     * Seconds without a fresh reading before exchange:watch tells the admins on
     * Telegram - for the rate as a whole, and for the relay on its own.
     */
    'alert_after' => env('EXCHANGE_ALERT_AFTER', 7200),

    /*
     * ⚠️ Reached only on a cold start during an outage: every source failed
     * AND no cached rate exists. It is a placeholder, not a price.
     *
     * 🔑 Keep it roughly current, and understand why. This sat at 107,000
     * while the market was near 210,000, so the one moment it could ever be
     * used was the moment it would have sold every USD plan at half price.
     * A stale fallback is not a safe fallback - it is a discount waiting for
     * an outage.
     */
    'fallback_rate' => env('EXCHANGE_FALLBACK_RATE', 210000),
    'cache_ttl' => env('EXCHANGE_CACHE_TTL', 3600),
];

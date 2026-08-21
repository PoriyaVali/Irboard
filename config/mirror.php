<?php

/*
 * What the Iranian relay is allowed to take a copy of.
 *
 * drmobjay.com is the first host the app talks to, and it reaches this panel
 * through an SSH tunnel out of Iran. When that tunnel is down - which happens,
 * and would be permanent under a national internet - nginx there answers 502
 * and a subscriber who has already paid cannot fetch the configuration they
 * paid for. The relay keeps a copy so it can still answer that.
 *
 * The secret lives in .env rather than in the v2board settings table on
 * purpose: it is not a preference, nobody should be able to read or rotate it
 * from the admin UI, and it has no business being in a database backup that
 * gets copied around.
 */

return [
    /*
     * Shared with the relay's `mirror:sync`. Empty means the export endpoint
     * refuses every request - the safe direction, since an export with no
     * secret is an export anyone can read.
     */
    'sync_secret' => env('MIRROR_SYNC_SECRET', ''),

    /*
     * Users per page. Each page carries whole rendered subscriptions, so the
     * bodies dominate: fifty users is a few megabytes, which is a reasonable
     * thing to push through a tunnel that is slow long before it is dead.
     */
    'page_size' => (int) env('MIRROR_PAGE_SIZE', 50),

    /*
     * 🔑 The client flags worth rendering, and why each one is here.
     *
     * `client/subscribe` does not have one answer per user - it has one per
     * client, because the flag picks the renderer. So the mirror is keyed by
     * (token, bucket) and this map decides which buckets exist.
     *
     * The key is the bucket name, which both sides hash into the subject. The
     * value is the flag string to render with, which is literally what the app
     * sends:
     *
     *   hiddify  the sing-box core sends User-Agent "HiddifyNext/x.y.z (...)",
     *            and ClientController matches on 'hiddify'.
     *   meta     the mihomo core appends ?flag=meta itself
     *            (mihomo_service.dart), which selects App\Protocols\ClashMeta.
     *   general  the mdns and trusttunnel cores send User-Agent "DoctorMobile"
     *            and strip any flag, so nothing in the protocol loop matches
     *            and ClientController falls through to App\Protocols\General.
     *            The value here is that same string, not 'general', because
     *            what has to be reproduced is the request the app makes.
     *
     * A flag the app has never sent is deliberately absent: rendering
     * subscriptions nobody will ask for costs the panel real time. If a client
     * asks for one anyway, the relay finds no entry and returns its honest
     * 503 - it never guesses with a body meant for a different client.
     */
    'flags' => [
        'hiddify' => 'hiddify',
        'meta' => 'meta',
        'general' => 'doctormobile',
    ],
];

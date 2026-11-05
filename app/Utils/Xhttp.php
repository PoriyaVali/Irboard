<?php

namespace App\Utils;

/**
 * XHTTP network settings, as Xray reads them, for the node and for each client
 * format a subscription is served in.
 *
 * The admin form posts application/x-www-form-urlencoded: every value reaches
 * the panel as a string, and an empty object is left out. Xray decodes those
 * strings into its own types and rejects a boolean or a number given as a
 * string, so the node never came up. PHP also stores any empty object it is
 * given as an empty array, which goes out as [] where Xray wants an object.
 *
 * Xray reads the node's settings as follows: when "extra" is present, the
 * settings are extra's, and only path, host and mode come from the top level.
 * A share link carries path, host, mode and extra, so whatever a client must
 * agree on with the node has to travel in extra, and in the matching options
 * of each client format.
 */
final class Xhttp
{
    // SplitHTTPConfig fields that are booleans or plain integers. The others
    // are strings, or ranges, which Xray also takes as strings.
    private const BOOLS = ['noGRPCHeader', 'noSSEHeader', 'xPaddingObfsMode'];
    private const INTS = ['scMaxBufferedPosts', 'serverMaxHeaderBytes'];
    // Fields Xray decodes into an object or a map. An empty one means nothing,
    // and as [] it fails the node.
    private const OBJECTS = ['headers', 'xmux', 'downloadSettings'];

    // Xray's name for each option and mihomo's, for xhttp-opts.
    private const MIHOMO = [
        'headers' => 'headers',
        'noGRPCHeader' => 'no-grpc-header',
        'xPaddingBytes' => 'x-padding-bytes',
        'xPaddingObfsMode' => 'x-padding-obfs-mode',
        'xPaddingKey' => 'x-padding-key',
        'xPaddingHeader' => 'x-padding-header',
        'xPaddingPlacement' => 'x-padding-placement',
        'xPaddingMethod' => 'x-padding-method',
        'uplinkHTTPMethod' => 'uplink-http-method',
        'sessionIDPlacement' => 'session-placement',
        'sessionPlacement' => 'session-placement',
        'sessionIDKey' => 'session-key',
        'sessionKey' => 'session-key',
        'sessionIDTable' => 'session-table',
        'sessionIDLength' => 'session-length',
        'seqPlacement' => 'seq-placement',
        'seqKey' => 'seq-key',
        'uplinkDataPlacement' => 'uplink-data-placement',
        'uplinkDataKey' => 'uplink-data-key',
        'uplinkChunkSize' => 'uplink-chunk-size',
        'scMaxEachPostBytes' => 'sc-max-each-post-bytes',
        'scMinPostsIntervalMs' => 'sc-min-posts-interval-ms',
    ];
    private const MIHOMO_XMUX = [
        'maxConcurrency' => 'max-concurrency',
        'maxConnections' => 'max-connections',
        'cMaxReuseTimes' => 'c-max-reuse-times',
        'hMaxRequestTimes' => 'h-max-request-times',
        'hMaxReusableSecs' => 'h-max-reusable-secs',
    ];
    // The ones Stash documents for its xhttp-opts.
    private const STASH = [
        'headers', 'no-grpc-header', 'x-padding-bytes', 'x-padding-obfs-mode', 'x-padding-key',
        'x-padding-header', 'x-padding-placement', 'x-padding-method', 'sc-max-each-post-bytes',
        'sc-min-posts-interval-ms',
    ];
    /**
     * The settings as saved: values in Xray's types, and no empty object
     * written as an empty array. Applied again when the settings are served,
     * which also mends what was saved before.
     */
    public static function normalize($settings)
    {
        if (!is_array($settings)) {
            return $settings;
        }
        $settings = self::normalizeLevel($settings);
        if (array_key_exists('extra', $settings)) {
            $extra = $settings['extra'];
            if (is_string($extra)) {
                $decoded = json_decode($extra, true);
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            if (is_array($extra)) {
                $settings['extra'] = self::normalizeLevel($extra);
            } elseif ($extra === null) {
                unset($settings['extra']);
            }
        }
        return $settings;
    }

    /**
     * The settings for the node. An empty extra is an object: present, it
     * still means "the settings are extra's", which a client sees as well.
     */
    public static function forNode($settings)
    {
        $settings = self::normalize($settings);
        if (!is_array($settings)) {
            return $settings;
        }
        if (isset($settings['extra']) && is_array($settings['extra'])) {
            $settings['extra'] = self::asObject($settings['extra']);
        }
        return $settings === [] ? new \stdClass() : $settings;
    }

    /**
     * Everything but path, host and mode that the node is configured with, as
     * Xray takes it: extra when present, the rest of the settings otherwise.
     */
    public static function clientSettings($settings): array
    {
        $settings = self::normalize($settings);
        if (!is_array($settings)) {
            return [];
        }
        if (array_key_exists('extra', $settings)) {
            return is_array($settings['extra']) ? $settings['extra'] : [];
        }
        unset($settings['path'], $settings['host'], $settings['mode']);
        return $settings;
    }

    /** A share link's extra parameter, or null when there is nothing to say. */
    public static function linkExtra($settings): ?string
    {
        $extra = self::clientSettings($settings);
        if ($extra === []) {
            return null;
        }
        return json_encode(self::asObject($extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * mihomo's xhttp-opts for the node, or null when the client cannot reach
     * it. $client is 'mihomo' or 'stash'.
     */
    public static function clashOpts($settings, string $client = 'mihomo'): ?array
    {
        $settings = self::normalize($settings);
        $settings = is_array($settings) ? $settings : [];
        $opts = [];
        foreach (['path', 'host', 'mode'] as $key) {
            if (isset($settings[$key]) && $settings[$key] !== '') {
                $opts[$key] = (string)$settings[$key];
            }
        }
        $extra = self::clientSettings($settings);
        foreach (self::MIHOMO as $from => $to) {
            if (!array_key_exists($from, $extra) || isset($opts[$to])) {
                continue;
            }
            $value = $extra[$from];
            if ($to === 'headers') {
                if (is_array($value) && $value !== []) {
                    $opts[$to] = array_map('strval', $value);
                }
            } elseif (is_bool($value)) {
                $opts[$to] = $value;
            } elseif (is_scalar($value) && (string)$value !== '') {
                $opts[$to] = (string)$value;
            }
        }
        if (isset($extra['xmux']) && is_array($extra['xmux'])) {
            $reuse = self::clashReuse($extra['xmux']);
            if ($reuse !== []) {
                $opts['reuse-settings'] = $reuse;
            }
        }
        if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
            $download = self::clashDownload($extra['downloadSettings'], $client);
            if ($download === null) {
                return null;
            }
            $opts['download-settings'] = $download;
        }
        if ($client === 'stash') {
            if (self::needsUnsupportedByStash($opts)) {
                return null;
            }
            foreach (array_keys($opts) as $key) {
                if (!in_array($key, ['path', 'host', 'mode', 'download-settings'], true) && !in_array($key, self::STASH, true)) {
                    unset($opts[$key]);
                }
            }
        }
        return $opts;
    }

    /**
     * Whether the node makes its clients do something Stash has no option
     * for: send the uplink with another method, or put the session, the
     * sequence number or the uplink data somewhere else than by default. A
     * Stash client would be refused, so the node is left out of its profile.
     */
    private static function needsUnsupportedByStash(array $opts): bool
    {
        return strtoupper($opts['uplink-http-method'] ?? 'POST') !== 'POST'
            || ($opts['session-placement'] ?? 'path') !== 'path'
            || ($opts['seq-placement'] ?? 'path') !== 'path'
            || in_array($opts['uplink-data-placement'] ?? 'auto', ['cookie', 'header'], true);
    }

    private static function clashReuse(array $xmux): array
    {
        $reuse = [];
        foreach (self::MIHOMO_XMUX as $from => $to) {
            if (isset($xmux[$from]) && is_scalar($xmux[$from]) && (string)$xmux[$from] !== '') {
                $reuse[$to] = (string)$xmux[$from];
            }
        }
        if (isset($xmux['hKeepAlivePeriod']) && is_numeric($xmux['hKeepAlivePeriod'])) {
            $reuse['h-keep-alive-period'] = (int)$xmux['hKeepAlivePeriod'];
        }
        return $reuse;
    }

    /**
     * download-settings from Xray's downloadSettings, a stream setting of its
     * own; null when it uses a security the profile cannot carry.
     */
    private static function clashDownload(array $download, string $client): ?array
    {
        $out = [];
        $xhttp = isset($download['xhttpSettings']) && is_array($download['xhttpSettings']) ? $download['xhttpSettings'] : [];
        foreach (['path', 'host'] as $key) {
            if (isset($xhttp[$key]) && $xhttp[$key] !== '') {
                $out[$key] = (string)$xhttp[$key];
            }
        }
        if (isset($xhttp['headers']) && is_array($xhttp['headers']) && $xhttp['headers'] !== []) {
            $out['headers'] = array_map('strval', $xhttp['headers']);
        }
        if (isset($xhttp['xmux']) && is_array($xhttp['xmux']) && $client !== 'stash') {
            $reuse = self::clashReuse($xhttp['xmux']);
            if ($reuse !== []) {
                $out['reuse-settings'] = $reuse;
            }
        }
        if (isset($download['address']) && $download['address'] !== '') {
            $out['server'] = (string)$download['address'];
        }
        if (isset($download['port']) && is_numeric($download['port'])) {
            $out['port'] = (int)$download['port'];
        }
        $sni = $client === 'stash' ? 'sni' : 'servername';
        $security = $download['security'] ?? 'none';
        if ($security === 'tls') {
            $tls = isset($download['tlsSettings']) && is_array($download['tlsSettings']) ? $download['tlsSettings'] : [];
            $out['tls'] = true;
            if (!empty($tls['serverName'])) $out[$sni] = (string)$tls['serverName'];
            if (!empty($tls['fingerprint'])) $out['client-fingerprint'] = (string)$tls['fingerprint'];
            if (!empty($tls['alpn'])) $out['alpn'] = array_values(array_map('strval', (array)$tls['alpn']));
            if (!empty($tls['allowInsecure'])) $out['skip-cert-verify'] = true;
        } elseif ($security === 'reality') {
            $reality = isset($download['realitySettings']) && is_array($download['realitySettings']) ? $download['realitySettings'] : [];
            $out['tls'] = true;
            if (!empty($reality['serverName'])) $out[$sni] = (string)$reality['serverName'];
            $out['client-fingerprint'] = !empty($reality['fingerprint']) ? (string)$reality['fingerprint'] : 'chrome';
            $out['reality-opts'] = [
                'public-key' => (string)($reality['password'] ?? ($reality['publicKey'] ?? '')),
                'short-id' => (string)($reality['shortId'] ?? ''),
            ];
        } elseif ($security !== 'none' && $security !== '') {
            return null;
        } elseif (array_key_exists('security', $download)) {
            $out['tls'] = false;
        }
        return $out;
    }

    private static function normalizeLevel(array $level): array
    {
        foreach (self::BOOLS as $key) {
            if (isset($level[$key]) && !is_bool($level[$key])) {
                $bool = filter_var($level[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) {
                    $level[$key] = $bool;
                }
            }
        }
        foreach (self::INTS as $key) {
            if (isset($level[$key]) && is_string($level[$key]) && preg_match('/^-?\d+$/', trim($level[$key]))) {
                $level[$key] = (int)$level[$key];
            }
        }
        if (isset($level['xmux']) && is_array($level['xmux'])
            && isset($level['xmux']['hKeepAlivePeriod']) && is_string($level['xmux']['hKeepAlivePeriod'])
            && preg_match('/^-?\d+$/', trim($level['xmux']['hKeepAlivePeriod']))) {
            $level['xmux']['hKeepAlivePeriod'] = (int)$level['xmux']['hKeepAlivePeriod'];
        }
        if (isset($level['downloadSettings']) && is_array($level['downloadSettings'])) {
            $level['downloadSettings'] = self::normalizeDownload($level['downloadSettings']);
        }
        foreach (self::OBJECTS as $key) {
            if (array_key_exists($key, $level) && ($level[$key] === [] || $level[$key] === null)) {
                unset($level[$key]);
            }
        }
        return $level;
    }

    // downloadSettings is a stream setting: what the form sends there is typed
    // as Xray's StreamConfig takes it.
    private static function normalizeDownload(array $download): array
    {
        if (isset($download['port']) && is_string($download['port']) && preg_match('/^\d+$/', trim($download['port']))) {
            $download['port'] = (int)$download['port'];
        }
        if (isset($download['xhttpSettings']) && is_array($download['xhttpSettings'])) {
            $download['xhttpSettings'] = self::normalize($download['xhttpSettings']);
            if (isset($download['xhttpSettings']['extra']) && is_array($download['xhttpSettings']['extra'])) {
                $download['xhttpSettings']['extra'] = self::asObject($download['xhttpSettings']['extra']);
            }
        }
        $typed = [
            'tlsSettings' => ['bools' => ['allowInsecure', 'disableSystemRoot', 'enableSessionResumption'], 'ints' => []],
            'realitySettings' => ['bools' => ['show'], 'ints' => []],
            'sockopt' => [
                'bools' => ['acceptProxyProtocol', 'tcpMptcp', 'penetrate', 'v6only'],
                'ints' => ['mark', 'tcpKeepAliveInterval', 'tcpKeepAliveIdle', 'tcpUserTimeout', 'tcpMaxSeg', 'tcpWindowClamp'],
            ],
        ];
        foreach ($typed as $section => $types) {
            if (!isset($download[$section]) || !is_array($download[$section])) {
                continue;
            }
            foreach ($types['bools'] as $key) {
                if (isset($download[$section][$key]) && !is_bool($download[$section][$key])) {
                    $bool = filter_var($download[$section][$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool !== null) {
                        $download[$section][$key] = $bool;
                    }
                }
            }
            foreach ($types['ints'] as $key) {
                if (isset($download[$section][$key]) && is_string($download[$section][$key]) && preg_match('/^-?\d+$/', trim($download[$section][$key]))) {
                    $download[$section][$key] = (int)$download[$section][$key];
                }
            }
        }
        // tcpFastOpen is a boolean, or a number of pending connections.
        if (isset($download['sockopt']['tcpFastOpen']) && is_string($download['sockopt']['tcpFastOpen'])) {
            $value = trim($download['sockopt']['tcpFastOpen']);
            if (preg_match('/^-?\d+$/', $value)) {
                $download['sockopt']['tcpFastOpen'] = (int)$value;
            } elseif (($bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) !== null) {
                $download['sockopt']['tcpFastOpen'] = $bool;
            }
        }
        foreach (['tlsSettings', 'realitySettings', 'sockopt', 'xhttpSettings'] as $section) {
            if (array_key_exists($section, $download) && ($download[$section] === [] || $download[$section] === null)) {
                unset($download[$section]);
            }
        }
        return $download;
    }

    // A map for json_encode, with every nested map an object: an empty one
    // would otherwise be written as [].
    private static function asObject(array $map)
    {
        $object = new \stdClass();
        foreach ($map as $key => $value) {
            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $value = self::asObject($value);
            } elseif ($value === [] && in_array($key, array_merge(self::OBJECTS, ['extra']), true)) {
                $value = new \stdClass();
            }
            $object->{$key} = $value;
        }
        return $object;
    }
}

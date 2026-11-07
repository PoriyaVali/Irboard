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
        return is_array($settings) ? self::asObject($settings) : $settings;
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
     * it. $client is 'mihomo' or 'stash'; $reality says whether the node's
     * own connection is REALITY.
     */
    public static function clashOpts($settings, string $client = 'mihomo', bool $reality = false): ?array
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
        // In obfuscation mode Xray looks for the padding where its defaults
        // put it: as repeat-x, under the x_padding key of a URL in the
        // X-Padding header. mihomo has no defaults; told only to obfuscate,
        // it sent no padding at all and the node refused every request (400).
        if (($opts['x-padding-obfs-mode'] ?? false) === true) {
            $opts += [
                'x-padding-key' => 'x_padding',
                'x-padding-header' => 'X-Padding',
                'x-padding-placement' => 'queryInHeader',
                'x-padding-method' => 'repeat-x',
            ];
        }
        if (isset($extra['xmux']) && is_array($extra['xmux'])) {
            $reuse = self::clashReuse($extra['xmux']);
            if ($reuse !== []) {
                $opts['reuse-settings'] = $reuse;
            }
        }
        if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
            $download = self::clashDownload($extra['downloadSettings'], $client, $reality);
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
     * own; null when the profile cannot carry it.
     *
     * Whatever download-settings leaves out, mihomo takes from the node's own
     * connection: its path, host and headers, its server name and certificate
     * check, its REALITY and its ECH. Xray builds the download leg from
     * downloadSettings alone, so each of these is said as Xray decides it.
     */
    private static function clashDownload(array $download, string $client, bool $reality): ?array
    {
        $xhttp = isset($download['xhttpSettings']) && is_array($download['xhttpSettings']) ? $download['xhttpSettings'] : [];
        // Path and host come from the leg's top level, the rest from its
        // extra when present, as for the node's own settings.
        $effective = self::clientSettings($xhttp);
        $security = $download['security'] ?? 'none';
        $secure = $download[$security === 'reality' ? 'realitySettings' : 'tlsSettings'] ?? [];
        $secure = is_array($secure) ? $secure : [];
        $address = isset($download['address']) && $download['address'] !== '' ? (string)$download['address'] : null;
        $encrypted = $security === 'tls' || $security === 'reality';
        // The leg's server name: its own, else its address.
        $serverName = $encrypted && !empty($secure['serverName']) ? (string)$secure['serverName'] : $address;

        $out = ['path' => isset($xhttp['path']) && $xhttp['path'] !== '' ? (string)$xhttp['path'] : '/'];
        // Host: the leg's own, else its server name, else its address.
        $host = isset($xhttp['host']) && $xhttp['host'] !== '' ? (string)$xhttp['host'] : $serverName;
        if ($host !== null) {
            $out['host'] = $host;
        }
        if (isset($effective['headers']) && is_array($effective['headers']) && $effective['headers'] !== []) {
            $out['headers'] = array_map('strval', $effective['headers']);
        }
        if (isset($effective['xmux']) && is_array($effective['xmux']) && $client !== 'stash') {
            $reuse = self::clashReuse($effective['xmux']);
            if ($reuse !== []) {
                $out['reuse-settings'] = $reuse;
            }
        }
        if ($address !== null) {
            $out['server'] = $address;
        }
        if (isset($download['port']) && is_numeric($download['port'])) {
            $out['port'] = (int)$download['port'];
        }
        if (!$encrypted && $security !== 'none' && $security !== '') {
            return null;
        }
        $out['tls'] = $encrypted;
        if ($encrypted && $serverName !== null) {
            $out[$client === 'stash' ? 'sni' : 'servername'] = $serverName;
        }
        if ($security === 'reality') {
            $out['client-fingerprint'] = !empty($secure['fingerprint']) ? (string)$secure['fingerprint'] : 'chrome';
            $out['reality-opts'] = [
                'public-key' => (string)($secure['password'] ?? ($secure['publicKey'] ?? '')),
                'short-id' => (string)($secure['shortId'] ?? ''),
            ];
        } else {
            if ($security === 'tls') {
                if (!empty($secure['fingerprint'])) $out['client-fingerprint'] = (string)$secure['fingerprint'];
                if (!empty($secure['alpn'])) $out['alpn'] = array_values(array_map('strval', (array)$secure['alpn']));
                $out['skip-cert-verify'] = !empty($secure['allowInsecure']);
            }
            if ($reality) {
                // Not the node's REALITY: an empty key turns it off in
                // mihomo. Stash documents no such override.
                if ($client === 'stash') {
                    return null;
                }
                $out['reality-opts'] = ['public-key' => ''];
            }
        }
        if ($client !== 'stash') {
            // Nor the node's ECH, which Xray's leg does not have unless its
            // own tlsSettings give it one; that is not carried over.
            $out['ech-opts'] = ['enable' => false];
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

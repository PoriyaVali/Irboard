<?php

namespace App\Utils;

/**
 * What each client family can carry of a VLESS or VMess node's transport,
 * and the VMess cipher to give it.
 *
 * The profile builders handled tcp, ws and grpc and passed anything else
 * through as plain TCP, which the node refuses. An HTTPUpgrade node came out
 * with no transport at all, and an mKCP node as TCP to a UDP port. Each
 * builder now asks here what its client can carry, and leaves out a node the
 * client cannot reach.
 */
final class ClientTransport
{
    /**
     * The transports each client has: mihomo (Clash Meta, Mihomo, Clash
     * Verge, Nyanpasu), Stash, and sing-box (with Hiddify). Which XHTTP
     * nodes a client can reach is Xhttp's to say. None of them has mKCP, and
     * V2Ray's QUIC, HTTP/2 and domain sockets, which the forms do not offer,
     * are left out everywhere.
     */
    private const CARRIES = [
        'mihomo' => ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp'],
        'stash' => ['tcp', 'ws', 'grpc', 'xhttp'],
        'singbox' => ['tcp', 'ws', 'grpc', 'httpupgrade'],
    ];

    public static function carries(string $client, $network): bool
    {
        $network = (string)($network ?: 'tcp');
        return in_array($network, self::CARRIES[$client], true);
    }

    /**
     * Whether a client that has only TCP and WebSocket (Surge, Surfboard, and
     * Loon for VMess) can reach the node, with V2Ray's HTTP header over TCP
     * when the client has it too. The builders of those clients handled ws
     * and passed anything else, gRPC and mKCP among them, as plain TCP.
     */
    public static function tcpOrWebSocket(array $server, bool $httpHeader = false): bool
    {
        $network = (string)(($server['network'] ?? '') ?: 'tcp');
        if ($network === 'ws') {
            return true;
        }
        if ($network !== 'tcp') {
            return false;
        }
        $settings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
        return $httpHeader || !is_array($settings) || ($settings['header']['type'] ?? null) !== 'http';
    }

    /** The HTTPUpgrade host: the node's own, which the node checks. */
    private static function host(array $settings): ?string
    {
        $host = $settings['host'] ?? null;
        if (is_array($host)) {
            $host = reset($host);
        }
        return is_scalar($host) && (string)$host !== '' ? (string)$host : null;
    }

    /**
     * HTTPUpgrade as mihomo carries it: WebSocket's ws-opts, with
     * v2ray-http-upgrade.
     */
    public static function mihomoHttpUpgrade($settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $opts = ['v2ray-http-upgrade' => true];
        if (!empty($settings['path'])) {
            $opts['path'] = (string)$settings['path'];
        }
        if (($host = self::host($settings)) !== null) {
            $opts['headers'] = ['Host' => $host];
        }
        return $opts;
    }

    /** sing-box's HTTPUpgrade transport. */
    public static function singboxHttpUpgrade($settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $transport = ['type' => 'httpupgrade'];
        if (!empty($settings['path'])) {
            $transport['path'] = (string)$settings['path'];
        }
        if (($host = self::host($settings)) !== null) {
            $transport['host'] = $host;
        }
        return $transport;
    }

    /**
     * The VMess cipher a client should use.
     *
     * Xray 26 nodes accept only aes-128-gcm and chacha20-poly1305; "none"
     * and "zero" are gone. sing-box turns "auto" into "zero" over TLS, so
     * every sing-box and Hiddify client of a VMess TLS node on V2bX's Xray
     * core was refused. A TLS node therefore names aes-128-gcm, and a node
     * the admin set to a cipher keeps it.
     */
    public static function vmessCipher(array $server): string
    {
        $settings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
        $cipher = is_array($settings) && is_scalar($settings['security'] ?? null) ? (string)$settings['security'] : '';
        if (in_array($cipher, ['aes-128-gcm', 'chacha20-poly1305'], true)) {
            return $cipher;
        }
        return !empty($server['tls']) ? 'aes-128-gcm' : 'auto';
    }
}

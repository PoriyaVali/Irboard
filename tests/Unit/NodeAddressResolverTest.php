<?php

namespace Tests\Unit;

use App\Services\NodeAddressResolver;
use PHPUnit\Framework\TestCase;

class NodeAddressResolverTest extends TestCase
{
    private static function resolve(): callable
    {
        return function (string $host) {
            return ['node.example.test' => '198.51.100.7', 'cdn.example.test' => '104.16.1.1'][$host] ?? null;
        };
    }

    private static function swap(array $server): array
    {
        return NodeAddressResolver::apply([$server], self::resolve())[0];
    }

    public function testARealityNodeIsServedByAddressAndKeepsItsCoverName(): void
    {
        $out = self::swap(['type' => 'vless', 'host' => 'node.example.test', 'tls' => 2, 'network' => 'tcp',
            'tls_settings' => ['server_name' => 'www.cover.example', 'public_key' => 'k']]);
        $this->assertSame('198.51.100.7', $out['host']);
        $this->assertSame('www.cover.example', $out['tls_settings']['server_name']);
    }

    public function testATlsNodeKeepsItsOldHostAsTheTlsName(): void
    {
        $trojan = self::swap(['type' => 'trojan', 'host' => 'node.example.test', 'server_name' => '', 'network' => 'tcp']);
        $this->assertSame(['198.51.100.7', 'node.example.test'], [$trojan['host'], $trojan['server_name']]);
        $hy = self::swap(['type' => 'hysteria', 'host' => 'node.example.test']);
        $this->assertSame('node.example.test', $hy['server_name']);
        $vmess = self::swap(['type' => 'vmess', 'host' => 'node.example.test', 'tls' => 1, 'network' => 'tcp', 'tlsSettings' => []]);
        $this->assertSame('node.example.test', $vmess['tlsSettings']['serverName']);
        $set = self::swap(['type' => 'tuic', 'host' => 'node.example.test', 'server_name' => 'own.example.test']);
        $this->assertSame('own.example.test', $set['server_name']);
    }

    public function testAnEmptyTopLevelNameTakesTheNestedTlsNameNotTheHost(): void
    {
        // AnyTLS over REALITY: server_name null, the cover site nested. The
        // AnyTLS link prefers the top-level name, so filling it with the
        // node's host would replace the cover site in the handshake.
        $out = self::swap(['type' => 'anytls', 'host' => 'node.example.test', 'tls' => 2, 'server_name' => null,
            'tls_settings' => ['server_name' => 'www.cover.example']]);
        $this->assertSame('198.51.100.7', $out['host']);
        $this->assertSame('www.cover.example', $out['server_name']);
        $this->assertSame('www.cover.example', $out['tls_settings']['server_name']);
    }

    public function testAnHttpTransportNeedsItsOwnHostHeader(): void
    {
        $bare = ['type' => 'vless', 'host' => 'node.example.test', 'tls' => 1, 'network' => 'ws',
            'network_settings' => ['path' => '/x'], 'tls_settings' => ['server_name' => 'node.example.test']];
        $this->assertSame('node.example.test', self::swap($bare)['host']);
        $withHeader = $bare;
        $withHeader['network_settings']['headers'] = ['Host' => 'node.example.test'];
        $this->assertSame('198.51.100.7', self::swap($withHeader)['host']);
    }

    public function testCdnNodesTaggedNodesAndUnsupportedTypesKeepTheirName(): void
    {
        $this->assertSame('cdn.example.test', self::swap(['type' => 'trojan', 'host' => 'cdn.example.test', 'network' => 'tcp'])['host']);
        $tagged = self::swap(['type' => 'trojan', 'host' => 'node.example.test', 'network' => 'tcp',
            'tags' => ['fast', NodeAddressResolver::KEEP_TAG]]);
        $this->assertSame(['node.example.test', ['fast']], [$tagged['host'], $tagged['tags']]);
        foreach (['mdns', 'trusttunnel', 'v2node', 'mieru'] as $type) {
            $this->assertSame('node.example.test', self::swap(['type' => $type, 'host' => 'node.example.test'])['host']);
        }
        $this->assertSame('node.example.test', self::swap(['type' => 'shadowsocks', 'host' => 'node.example.test', 'obfs' => 'http'])['host']);
        $this->assertSame('198.51.100.7', self::swap(['type' => 'shadowsocks', 'host' => 'node.example.test'])['host']);
    }

    public function testAnAddressOrAFailedLookupChangesNothing(): void
    {
        $this->assertSame('203.0.113.9', self::swap(['type' => 'trojan', 'host' => '203.0.113.9'])['host']);
        $this->assertSame('gone.example.test', self::swap(['type' => 'trojan', 'host' => 'gone.example.test', 'server_name' => ''])['host']);
        $this->assertSame('', self::swap(['type' => 'trojan', 'host' => 'gone.example.test', 'server_name' => ''])['server_name']);
    }

    public function testCloudflareRangesAreRecognized(): void
    {
        $this->assertTrue(NodeAddressResolver::isCdn('104.16.1.1'));
        $this->assertTrue(NodeAddressResolver::isCdn('172.67.0.1'));
        $this->assertSame(false, NodeAddressResolver::isCdn('198.51.100.7'));
    }
}

<?php

namespace Tests\Unit;

use App\Services\NodePortTargets;
use PHPUnit\Framework\TestCase;

class NodePortTargetsTest extends TestCase
{
    private static function row(array $values = []): array
    {
        return $values + ['type' => 'vless', 'id' => 1, 'host' => ' Node.Example.test ',
            'port' => 443, 'server_port' => 2053, 'parent_id' => null, 'updated_at' => 1700000000];
    }

    public function testOnlyClientEndpointFieldsAreExportedWithoutSecrets(): void
    {
        $result = NodePortTargets::fromServers([self::row(['password' => 'private', 'tls_settings' => ['private_key' => 'secret']])]);
        $this->assertSame([['node' => 'vless#1', 'host' => 'node.example.test', 'port' => '443',
            'transport' => 'tcp', 'updated_at' => 1700000000]], $result);
    }

    public function testAChildKeepsItsOwnRelayHostAndClientPort(): void
    {
        $rows = NodePortTargets::fromServers([self::row(), self::row(['id' => 2, 'parent_id' => 1,
            'host' => 'relay.example.test', 'port' => 8443, 'server_port' => 2053])]);
        $this->assertSame('relay.example.test', $rows[1]['host']);
        $this->assertSame('8443', $rows[1]['port']);
        $this->assertSame('vless#2', $rows[1]['node']);
    }

    public function testMissingClientPortNeverUsesTheServerPort(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['port' => null, 'server_port' => 2053])]);
        $this->assertNull($rows[0]['port']);
        $this->assertSame('unknown', $rows[0]['transport']);
    }

    public function testRangesAreCanonicalButNotClaimedProbeable(): void
    {
        $rows = NodePortTargets::fromServers([
            self::row(['port' => '2000 - 2010']),
            self::row(['type' => 'mieru', 'port' => 443, 'port_range' => '03000-03010', 'transport' => 'UDP']),
        ]);
        $this->assertSame(['2000-2010', '3000-3010'], array_column($rows, 'port'));
        $this->assertSame(['unknown', 'unknown'], array_column($rows, 'transport'));
    }

    public function testIpv6BracketsAndDnsCaseAreNormalized(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['host' => ' [2001:DB8::1] ', 'port' => '00443'])]);
        $this->assertSame('2001:db8::1', $rows[0]['host']);
        $this->assertSame('443', $rows[0]['port']);
    }

    public function testTransportSelectionDoesNotInventTcpForUdpOnlyNodes(): void
    {
        foreach (['mdns', 'hysteria', 'tuic', 'shadowquic', 'wireguard', 'masque'] as $type) {
            $this->assertSame('udp', NodePortTargets::transport(['type' => $type]));
        }
        foreach (['vless', 'trusttunnel', 'shadowsocks', 'socks5', 'snell'] as $type) {
            $this->assertSame('tcp', NodePortTargets::transport(['type' => $type]));
        }
        $this->assertSame('udp', NodePortTargets::transport(['type' => 'vless', 'network' => 'kcp']));
        $this->assertSame('udp', NodePortTargets::transport(['type' => 'mieru', 'transport' => 'UDP']));
        $this->assertSame('tcp', NodePortTargets::transport(['type' => 'openvpn', 'proto' => 'tcp-client']));
        $this->assertSame('udp', NodePortTargets::transport(['type' => 'openvpn', 'proto' => 'udp']));
        $this->assertSame('unknown', NodePortTargets::transport(['type' => 'easytier']));
    }

    public function testExternalUnmanagedNodesCanHaveTheirClientEndpointObserved(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['type' => 'snell', 'managed' => false])]);
        $this->assertSame('tcp', $rows[0]['transport']);
        $this->assertSame('snell#1', $rows[0]['node']);
    }

    public function testMdnsIsUdpAndKeepsItsClientPortInsteadOfTheDnsServicePort(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['type' => 'mdns', 'port' => 2096, 'server_port' => 53])]);
        $this->assertSame('udp', $rows[0]['transport']);
        $this->assertSame('2096', $rows[0]['port']);
    }

    public function testInvalidHostCannotLeakAnEmbeddedCredentialOrBecomeATarget(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['host' => 'https://name:secret@example.test/path'])]);
        $this->assertSame('', $rows[0]['host']);
        $this->assertSame('unknown', $rows[0]['transport']);
        $this->assertSame([], NodePortTargets::fromServers([self::row(['id' => 0])]));
    }
}

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

    public function testUdpNodesCarryTheQuestionTheirProtocolAnswers(): void
    {
        $rows = NodePortTargets::fromServers([
            self::row(['type' => 'tuic', 'id' => 1]),
            self::row(['type' => 'hysteria', 'id' => 2, 'version' => 2, 'obfs' => 'salamander', 'obfs_password' => 'key-2222']),
            self::row(['type' => 'hysteria', 'id' => 3, 'version' => 1, 'obfs_password' => 'key-3333']),
            self::row(['type' => 'hysteria', 'id' => 4, 'version' => 2, 'obfs' => null]),
            self::row(['type' => 'mdns', 'id' => 5, 'domain' => ['T.Example.test', 'u.example.test']]),
            self::row(['type' => 'wireguard', 'id' => 6]),
        ]);
        $this->assertSame(['probe' => 'quic'], array_intersect_key($rows[0], ['probe' => 1, 'obfs' => 1]));
        $this->assertSame(['salamander', 'key-2222'], [$rows[1]['obfs'], $rows[1]['obfs_password']]);
        $this->assertSame(['xplus', 'key-3333'], [$rows[2]['obfs'], $rows[2]['obfs_password']]);
        $this->assertArrayNotHasKey('obfs', $rows[3]);
        $this->assertSame(['dns', 't.example.test'], [$rows[4]['probe'], $rows[4]['domain']]);
        $this->assertArrayNotHasKey('probe', $rows[5], 'WireGuard does not answer strangers');
    }

    public function testARelayUsesItsRootsObfuscationAndAnUnknownOneIsNotGuessed(): void
    {
        $rows = NodePortTargets::fromServers([
            self::row(['type' => 'hysteria', 'id' => 1, 'version' => 2, 'obfs' => 'salamander', 'obfs_password' => 'root-key']),
            self::row(['type' => 'hysteria', 'id' => 2, 'parent_id' => 1, 'host' => 'relay.example.test', 'port' => 8443]),
            self::row(['type' => 'hysteria', 'id' => 3, 'version' => 2, 'obfs' => 'other', 'obfs_password' => 'x-key']),
        ]);
        $this->assertSame(['salamander', 'root-key'], [$rows[1]['obfs'], $rows[1]['obfs_password']]);
        $this->assertSame('relay.example.test', $rows[1]['host']);
        $this->assertArrayNotHasKey('probe', $rows[2]);
    }

    public function testInvalidHostCannotLeakAnEmbeddedCredentialOrBecomeATarget(): void
    {
        $rows = NodePortTargets::fromServers([self::row(['host' => 'https://name:secret@example.test/path'])]);
        $this->assertSame('', $rows[0]['host']);
        $this->assertSame('unknown', $rows[0]['transport']);
        $this->assertSame([], NodePortTargets::fromServers([self::row(['id' => 0])]));
    }
}

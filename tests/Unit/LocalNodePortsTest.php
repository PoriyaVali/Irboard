<?php

namespace Tests\Unit;

use App\Services\LocalNodePorts;
use PHPUnit\Framework\TestCase;

class LocalNodePortsTest extends TestCase
{
    private const HERE = ['203.0.113.10', '2001:db8::10'];

    private static function resolver(array $map): callable
    {
        return function (string $host) use ($map) {
            return $map[$host] ?? [];
        };
    }

    private static function node(string $type, int $id, array $fields = []): array
    {
        return $fields + ['type' => $type, 'id' => $id, 'host' => 'elsewhere.example', 'port' => 443, 'server_port' => 443];
    }

    public function testANodeOnThisServerGetsItsServicePortAndProtocol(): void
    {
        $ports = LocalNodePorts::desired(
            [self::node('vless', 5, ['host' => '203.0.113.10', 'port' => 2053, 'server_port' => 2053])],
            self::HERE, [], self::resolver([])
        );
        $this->assertSame([['port' => '2053', 'proto' => 'tcp', 'nodes' => ['vless#5']]], $ports);
    }

    public function testNodesElsewhereNeedNothingHere(): void
    {
        $ports = LocalNodePorts::desired(
            [self::node('vless', 1), self::node('trojan', 2, ['host' => '198.51.100.7'])],
            self::HERE, [], self::resolver(['elsewhere.example' => ['198.51.100.1']])
        );
        $this->assertSame([], $ports);
    }

    public function testAHostNameResolvingHereCounts(): void
    {
        $ports = LocalNodePorts::desired(
            [self::node('anytls', 3, ['host' => 'node.example', 'server_port' => 2087])],
            self::HERE, [], self::resolver(['node.example' => ['2001:db8::10']])
        );
        $this->assertSame('2087', $ports[0]['port']);
    }

    public function testAV2bxReportingFromHereCountsEvenBehindACdn(): void
    {
        // The host resolves to a CDN, but the node's V2bX reports from this server.
        $ports = LocalNodePorts::desired(
            [self::node('vless', 7, ['host' => 'cdn.example', 'server_port' => 8880])],
            self::HERE, ['vless#7' => '203.0.113.10'], self::resolver(['cdn.example' => ['104.16.0.1']])
        );
        $this->assertSame('8880', $ports[0]['port']);
    }

    public function testLoopbackIsNeverProofOfBeingLocal(): void
    {
        // A V2bX reaching the panel through an SSH tunnel reports from 127.0.0.1
        // whichever server it runs on.
        $ports = LocalNodePorts::desired(
            [self::node('vless', 8, ['host' => '127.0.0.1'])],
            array_merge(self::HERE, ['127.0.0.1', '::1']), ['vless#8' => '127.0.0.1'], self::resolver([])
        );
        $this->assertSame([], $ports);
    }

    public function testASharedPortListsEveryNodeAndStaysOneEntry(): void
    {
        $ports = LocalNodePorts::desired([
            self::node('vless', 1, ['host' => '203.0.113.10', 'server_port' => 2053]),
            self::node('trojan', 2, ['host' => '203.0.113.10', 'server_port' => 2053]),
            self::node('vless', 3, ['host' => '203.0.113.10', 'server_port' => 2083]),
        ], self::HERE, [], self::resolver([]));
        $this->assertSame([
            ['port' => '2053', 'proto' => 'tcp', 'nodes' => ['vless#1', 'trojan#2']],
            ['port' => '2083', 'proto' => 'tcp', 'nodes' => ['vless#3']],
        ], $ports);
    }

    public function testProtocolsFollowTheNodeType(): void
    {
        $this->assertSame(['udp'], LocalNodePorts::protocols(['type' => 'hysteria']));
        $this->assertSame(['udp'], LocalNodePorts::protocols(['type' => 'tuic']));
        $this->assertSame(['tcp', 'udp'], LocalNodePorts::protocols(['type' => 'shadowsocks']));
        $this->assertSame(['tcp', 'udp'], LocalNodePorts::protocols(['type' => 'trusttunnel']));
        $this->assertSame(['udp'], LocalNodePorts::protocols(['type' => 'vmess', 'network' => 'kcp']));
        $this->assertSame(['tcp'], LocalNodePorts::protocols(['type' => 'vless', 'network' => 'xhttp']));
        $this->assertSame(['udp'], LocalNodePorts::protocols(['type' => 'mieru', 'transport' => 'UDP']));
        $this->assertSame(['tcp', 'udp'], LocalNodePorts::protocols(['type' => 'something-new']));
    }

    public function testChildrenAndUnmanagedProtocolNodesHaveNoListenerHere(): void
    {
        $ports = LocalNodePorts::desired([
            self::node('vless', 9, ['host' => '203.0.113.10', 'parent_id' => 1, 'server_port' => 2096]),
            self::node('snell', 4, ['host' => '203.0.113.10', 'managed' => false, 'server_port' => 6160]),
            self::node('snell', 5, ['host' => '203.0.113.10', 'managed' => true, 'server_port' => 6161]),
        ], self::HERE, [], self::resolver([]));
        $this->assertSame(['6161', '6161'], array_column($ports, 'port'));
        $this->assertSame(['tcp', 'udp'], array_column($ports, 'proto'));
    }

    public function testPortSpecs(): void
    {
        $this->assertSame('2053', LocalNodePorts::portSpec(['type' => 'vless', 'server_port' => 2053, 'port' => 443]));
        $this->assertSame('443', LocalNodePorts::portSpec(['type' => 'vless', 'server_port' => null, 'port' => '443']));
        $this->assertSame('2000-2010', LocalNodePorts::portSpec(['type' => 'mieru', 'port_range' => '2000-2010', 'server_port' => 1]));
        $this->assertNull(LocalNodePorts::portSpec(['type' => 'vless', 'server_port' => 70000, 'port' => 'abc']));
        $this->assertNull(LocalNodePorts::portSpec(['type' => 'vless', 'server_port' => 0, 'port' => 0]));
    }
}

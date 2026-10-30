<?php

namespace Tests\Feature;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Mihomo;
use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\ProtocolRegistry as Registry;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\MihomoProfiles;
use Tests\TestCase;

class MihomoSubscriptionTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAllMihomoClientsExportTypedProfilesAndTrustTunnelCredentials(): void
    {
        $user = ['id' => 91, 'uuid' => '11111111-2222-3333-4444-555555555555', 'u' => 0, 'd' => 0, 'transfer_enable' => 100000, 'expired_at' => time() + 86400];
        $servers = [];
        foreach (array_keys(Outbound::catalog()) as $protocol) {
            $settings = MihomoProfiles::settings($protocol);
            if (isset($settings['udp'])) $settings['udp'] = false;
            $server = ['name' => 'fixture ' . $protocol . ' : فارسی', 'type' => $protocol] + Registry::storage($protocol, $settings);
            $server['mihomo'] = Outbound::render($server, $user);
            $servers[] = $server;
        }
        $servers[] = ['type' => 'trusttunnel', 'name' => 'fixture trusttunnel', 'host' => '192.0.2.10', 'port' => 443,
            'hostname' => 'cert.example.test', 'custom_sni' => 'sni.example.test', 'cert_type' => 'letsencrypt', 'upstream_protocol' => 'http3'];
        foreach ([ClashMeta::class, Mihomo::class, ClashVerge::class, ClashNyanpasu::class] as $class) {
            $config = Yaml::parse((new $class($user, $servers))->handle());
            $proxies = $config['proxies'];
            $this->assertCount(16, $proxies, $class);
            foreach (array_keys(Outbound::catalog()) as $index => $protocol) {
                $proxy = $proxies[$index];
                $this->assertSame($servers[$index]['mihomo'], $proxy, $class . ' ' . $protocol);
                if (isset($proxy['udp'])) $this->assertFalse($proxy['udp']);
                if ($protocol === 'ssh') $this->assertSame(['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'], $proxy['host-key-algorithms'], $class);
                $this->assertContains($proxy['name'], $config['proxy-groups'][0]['proxies']);
            }
            $trust = $proxies[15];
            $this->assertSame($user['uuid'], $trust['username']);
            $this->assertSame($user['uuid'], $trust['password']);
            $this->assertSame('sni.example.test', $trust['sni']);
            $this->assertTrue($trust['quic']);
            $this->assertFalse($trust['skip-cert-verify']);
            if ($dir = getenv('IRBOARD_MIHOMO_CONTRACT_DIR')) {
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                file_put_contents($dir . '/' . class_basename($class) . '.json', json_encode($proxies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
        // The classic Clash core cannot parse these types.
        $legacy = Yaml::parse((new Clash($user, $servers))->handle());
        $this->assertSame([], $legacy['proxies']);
    }

    /** Managed profiles carry each subscriber's own credential; exported for ValidateMihomoConfig.py. */
    public function testManagedProfilesCarryEachSubscribersCredential(): void
    {
        $users = [['id' => 91, 'uuid' => '11111111-2222-3333-4444-555555555555'], ['id' => 92, 'uuid' => '66666666-7777-8888-9999-000000000000']];
        $exported = [];
        foreach (\App\Protocols\Mihomo\ManagedServer::TYPES as $protocol) {
            $settings = MihomoProfiles::settings($protocol);
            foreach (['username', 'password', 'psk'] as $key) if (array_key_exists($key, $settings)) $settings[$key] = '';
            if ($protocol === 'snell') $settings['obfs-opts'] = ['mode' => 'shadow-tls', 'version' => 3, 'host' => 'www.example.com'];
            if ($protocol === 'sudoku') $settings['key'] = \App\Protocols\Mihomo\SudokuKeys::generate();
            $server = ['name' => 'managed ' . $protocol, 'type' => $protocol, 'managed' => true, 'server_port' => 443] + Registry::storage($protocol, $settings);
            \App\Protocols\Mihomo\ManagedServer::validate($protocol, $server);
            [$one, $two] = array_map(function ($user) use ($server) { return Outbound::render($server, $user); }, $users);
            $credential = ['sudoku' => 'key', 'snell' => 'psk'][$protocol] ?? 'password';
            $this->assertNotSame($one[$credential], $two[$credential], $protocol);
            if ($protocol === 'snell') $this->assertSame($one['psk'], $one['obfs-opts']['password']);
            if ($protocol === 'sudoku') $this->assertSame(128, strlen($one['key']));
            $exported[] = $one;
        }
        if ($dir = getenv('IRBOARD_MIHOMO_CONTRACT_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dir . '/Managed.json', json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    public function testMieruPortRangeAndWireguardPeersKeepTheirShape(): void
    {
        $mieru = MihomoProfiles::settings('mieru');
        unset($mieru['port']); $mieru['port-range'] = '2090-2099';
        $this->assertSame($mieru, Outbound::validate('mieru', $mieru));
        $wg = MihomoProfiles::settings('wireguard');
        $wg['ip'] = '10.0.0.2/32';
        $wg['peers'] = [['server' => $wg['server'], 'port' => $wg['port'], 'public-key' => $wg['public-key'], 'allowed-ips' => ['0.0.0.0/0', '::/0']]];
        unset($wg['server'], $wg['port'], $wg['public-key']);
        $this->assertSame($wg, Outbound::validate('wireguard', $wg));
    }

    public function testPinnedSshHostKeysChooseTheAlgorithmsUnlessTheAdministratorDid(): void
    {
        $user = ['id' => 7, 'uuid' => '11111111-2222-3333-4444-555555555555'];
        $settings = MihomoProfiles::settings('ssh');
        $server = ['name' => 'ssh', 'type' => 'ssh'] + Registry::storage('ssh', $settings);
        // Mihomo's x/crypto prefers ECDSA, which a stock OpenSSH server also holds.
        $this->assertSame(['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'], Outbound::render($server, $user)['host-key-algorithms']);
        $this->assertSame(['ssh-ed25519'], Outbound::render(['host_key_algorithms' => ['ssh-ed25519']] + $server, $user)['host-key-algorithms']);
        $this->assertArrayNotHasKey('host-key-algorithms', Outbound::render(['host_key' => null] + $server, $user));
        $this->assertSame(Registry::storage('ssh', $settings), Registry::storage('ssh', Outbound::validate('ssh', $settings)), 'the stored node is not changed');
    }

    public function testTrustTunnelSelfSignedAndCertificateNameFallback(): void
    {
        $server = ['type' => 'trusttunnel', 'name' => 'self signed', 'host' => '192.0.2.10', 'port' => '8443', 'hostname' => 'owned.example.test'];
        $outbound = Outbound::fromServer(['uuid' => 'fictional-uuid'], $server);
        $this->assertTrue($outbound['skip-cert-verify']);
        $this->assertFalse($outbound['quic']);
        $this->assertSame('owned.example.test', $outbound['sni']);
        $this->assertSame(8443, $outbound['port']);
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInformationAliasesUseCurrentNamesWithoutChangingTheStoredProfile(): void
    {
        $user = ['id' => 91, 'uuid' => 'fictional-uuid', 'u' => 0, 'd' => 0, 'transfer_enable' => 100000, 'expired_at' => time() + 86400];
        $original = ['name' => 'original node', 'type' => 'socks5'] + Registry::storage('socks5', MihomoProfiles::settings('socks5'));
        $original['mihomo'] = Outbound::render($original, $user);
        $names = ['ترافیک: ۱۰۰ گیگ', 'تاریخ: فردا', 'original node'];
        $servers = array_map(function ($name) use ($original) {
            return array_replace($original, ['name' => $name]);
        }, $names);
        foreach ([ClashMeta::class, Mihomo::class, ClashVerge::class, ClashNyanpasu::class] as $class) {
            $config = Yaml::parse((new $class($user, $servers))->handle());
            $this->assertSame($names, array_column($config['proxies'], 'name'), $class);
            foreach ($names as $name) $this->assertContains($name, $config['proxy-groups'][0]['proxies']);
        }
        $this->assertSame('original node', $original['mihomo']['name']);
    }
}

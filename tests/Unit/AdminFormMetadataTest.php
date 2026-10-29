<?php

namespace Tests\Unit;

use App\Protocols\Mihomo\AdminForm;
use App\Protocols\Mihomo\ManagedServer;
use App\Protocols\Mihomo\Outbound;
use App\Protocols\Mihomo\ProtocolRegistry;
use Tests\Fixtures\MihomoProfiles;
use Tests\TestCase;

class AdminFormMetadataTest extends TestCase
{
    public function testEveryFieldHasActionableBilingualGuidanceAndTheCatalogRemainsUiOnly(): void
    {
        $runtime = Outbound::catalog();
        $catalog = AdminForm::catalog($runtime);
        $this->assertCount(15, $catalog);
        $this->assertSame($runtime, Outbound::catalog());
        foreach ($catalog as $type => $definition) {
            $this->assertArrayNotHasKey('form', $runtime[$type]);
            foreach (['description', 'capability'] as $key) foreach (['fa', 'en'] as $language) {
                $this->assertGreaterThanOrEqual(18, mb_strlen($definition['form'][$key][$language]), $type . '/' . $key);
            }
            $sections = array_column($definition['form']['sections'], 'key');
            foreach (['name', 'host', 'port', 'server_port', 'rate', 'group_id', 'route_id', 'tags', 'parent_id', 'managed', 'show', 'sort'] as $key) {
                $this->assertArrayHasKey($key, $definition['form']['common']);
            }
            foreach (array_merge($definition['fields'], $definition['form']['common']) as $column => $field) {
                foreach (['fa', 'en'] as $language) {
                    $this->assertNotEmpty($field['label'][$language], $type . '/' . $column);
                    $this->assertGreaterThanOrEqual(18, mb_strlen($field['help'][$language]), $type . '/' . $column);
                }
                if (isset($field['kind'])) {
                    $this->assertContains($field['section'], $sections);
                    $this->assertIsBool($field['required']);
                }
            }
        }
    }

    public function testNewDraftDefaultsDoNotInventServiceIdentitiesOrCredentials(): void
    {
        $sensitive = ['username', 'password', 'psk', 'private_key', 'public_key', 'pre_shared_key', 'key',
            'ca', 'cert', 'certificate', 'auth_key', 'identity_secret', 'network_secret', 'network_name',
            'network', 'ip', 'ipv4', 'ipv6', 'peers', 'listeners', 'state_dir', 'exit_node', 'sni'];
        foreach (AdminForm::catalog(Outbound::catalog()) as $type => $definition) {
            $defaults = $definition['form']['defaults'];
            $this->assertSame('', $defaults['host']);
            $this->assertSame([], $defaults['group_id']);
            $this->assertFalse($defaults['show']);
            $this->assertSame(1, $defaults['rate']);
            foreach ($sensitive as $key) $this->assertArrayNotHasKey($key, $defaults, $type . '/' . $key);
            foreach ($defaults as $key => $value) {
                $this->assertTrue(isset($definition['fields'][$key]) || isset($definition['form']['common'][$key]), $type . '/' . $key);
            }
            if (ManagedServer::supports($type)) $this->assertTrue($defaults['managed']);
            else $this->assertArrayNotHasKey('managed', $defaults);
            // Only V2bX listens on a service port; an external service already has one.
            if (isset($defaults['port']) && ManagedServer::supports($type)) $this->assertSame($defaults['port'], $defaults['server_port']);
            else $this->assertArrayNotHasKey('server_port', $defaults, $type);
            if (isset($defaults['skip_cert_verify'])) $this->assertFalse($defaults['skip_cert_verify']);
            if (in_array($type, ['http', 'socks5'], true)) $this->assertTrue($defaults['tls']);
        }
    }

    public function testManagedDraftDefaultsProduceValidDistinctSubscriberCredentials(): void
    {
        foreach (ManagedServer::TYPES as $type) {
            $definition = AdminForm::catalog(Outbound::catalog())[$type];
            $node = array_replace($definition['form']['defaults'], ['host' => 'node.example.test', 'username' => '', 'password' => '']);
            ManagedServer::validate($type, $node);
            $settings = ManagedServer::credentials(ProtocolRegistry::options($type, $node));
            Outbound::validate($type, $settings);
            $one = Outbound::render($node + ['type' => $type, 'name' => 'node'], ['id' => 11, 'uuid' => 'subscriber-one']);
            $two = Outbound::render($node + ['type' => $type, 'name' => 'node'], ['id' => 12, 'uuid' => 'subscriber-two']);
            $this->assertSame('subscriber-one', $one['username']);
            $this->assertSame('subscriber-two', $two['password']);
            $this->assertNotSame($one['password'], $two['password']);
            foreach (['username', 'password'] as $field) {
                $this->assertTrue($definition['fields'][$field]['managed_credential']);
                $this->assertSame(in_array($type, ['mieru', 'shadowquic'], true), $definition['fields'][$field]['required_external']);
            }
        }
    }

    public function testChoiceValuesAreAcceptedByTheActualProtocolValidator(): void
    {
        foreach (AdminForm::catalog(Outbound::catalog()) as $type => $definition) {
            foreach ($definition['fields'] as $field) {
                foreach ($field['choices'] ?? [] as $choice) {
                    $settings = MihomoProfiles::settings($type);
                    $settings[$field['option']] = $choice['value'];
                    if ($type === 'snell' && in_array($choice['value'], [1, 2], true)) $settings['udp'] = false;
                    $validated = Outbound::validate($type, $settings);
                    $this->assertSame($choice['value'], $validated[$field['option']], $type . '/' . $field['option']);
                }
            }
        }
    }

    public function testKeyAndCertificateGuidanceUsesTheRealProtocolFormats(): void
    {
        $catalog = AdminForm::catalog(Outbound::catalog());
        $this->assertStringContainsString('۳۲', $catalog['wireguard']['fields']['private_key']['help']['fa']);
        $this->assertStringContainsString('DER', $catalog['masque']['fields']['private_key']['help']['en']);
        $this->assertStringContainsString('PEM', $catalog['openvpn']['fields']['key']['help']['en']);
        $this->assertStringContainsString('authorized', $catalog['ssh']['fields']['private_key']['help']['en']);
        $this->assertStringContainsString('V2bX', $catalog['http']['fields']['private_key']['help']['en']);
        $this->assertStringContainsString('16 hexadecimal', $catalog['zerotier']['fields']['network']['help']['en']);
        $orbit = json_decode($catalog['zerotier']['fields']['orbit']['placeholder']['en'], true);
        $this->assertSame(['world', 'seed'], array_keys($orbit[0]));
        $this->assertStringContainsString('client device', $catalog['zerotier']['fields']['planet']['help']['en']);
        $this->assertStringContainsString('disables host-key verification', $catalog['ssh']['fields']['host_key']['help']['en']);
        $this->assertStringContainsString('ssh-keyscan', $catalog['ssh']['fields']['host_key']['help']['en']);
        $this->assertStringContainsString('BEGIN', $catalog['ssh']['fields']['private_key']['help']['en']);
        $this->assertStringContainsString('File paths are not accepted', $catalog['http']['fields']['certificate']['help']['en']);
        $sections = array_column($catalog['ssh']['form']['sections'], 'label', 'key');
        $this->assertSame('SSH server verification', $sections['security']['en']);
        $this->assertStringContainsString('TLS', array_column($catalog['http']['form']['sections'], 'label', 'key')['security']['en']);
        $this->assertSame([10, 30], [$catalog['sudoku']['form']['defaults']['padding_min'], $catalog['sudoku']['form']['defaults']['padding_max']]);
        $this->assertSame(3, $catalog['snell']['form']['defaults']['version']);
    }
}

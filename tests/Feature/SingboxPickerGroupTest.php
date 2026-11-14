<?php

namespace Tests\Feature;

use App\Protocols\Hiddify\Hiddify;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use Tests\TestCase;

/**
 * The group a person picks a node from, in each shipped sing-box template.
 *
 * SingboxOld filled it only when its tag was the panel's app_name, so on any
 * panel whose name differed from the template's group it offered the automatic
 * group and nothing else.
 */
class SingboxPickerGroupTest extends TestCase
{
    private const USER = ['id' => 7, 'uuid' => '11111111-2222-3333-4444-555555555555', 'u' => 0, 'd' => 0,
        'transfer_enable' => 1073741824, 'expired_at' => 1900000000];

    private static function node(string $name): array
    {
        return ['type' => 'vless', 'name' => $name, 'host' => 'node.example.test', 'port' => 443, 'tls' => 1,
            'flow' => null, 'network' => 'ws', 'network_settings' => ['path' => '/ws'],
            'tls_settings' => ['server_name' => 'node.example.test']];
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryNodeIsOfferedWhateverThePanelIsCalled(): void
    {
        // What a fresh install calls itself: not the template's group name.
        config(['v2board.app_name' => 'IrBoard']);
        $servers = [self::node('first'), self::node('second')];
        foreach ([Singbox::class, SingboxOld::class, Hiddify::class] as $class) {
            $config = json_decode((new $class(self::USER, $servers))->handle()->getContent(), true);
            $pickers = array_filter($config['outbounds'], function ($ob) {
                return ($ob['type'] ?? '') === 'selector' && in_array('انتخاب خودکار', $ob['outbounds'] ?? [], true);
            });
            $this->assertNotEmpty($pickers, $class . ': the template has no group offering the automatic one');
            foreach ($pickers as $picker) {
                $this->assertContains('first', $picker['outbounds'], $class . ': ' . $picker['tag'] . ' offers no node');
                $this->assertContains('second', $picker['outbounds'], $class . ': ' . $picker['tag']);
            }
        }
    }
}

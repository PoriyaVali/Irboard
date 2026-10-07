<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\Server\VlessController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Services\ServerService;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

/**
 * What the panel hands the node (V2bX) and the subscriptions, where the two
 * used to disagree.
 *
 * PHPUnit's own TestCase: these are the pure helpers, and they must never be
 * able to reach a database.
 */
class NodeApiCompatTest extends TestCase
{
    public function testTlsSettingsGiveTheNodeXverAndPortAsStrings()
    {
        // The REALITY form saves xver as a number; V2bX up to 1.6.0 failed
        // the whole config on it and the node never came up.
        $out = UniProxyController::nodeTlsSettings([
            'server_name' => 'a.example', 'xver' => 2, 'server_port' => 443, 'short_id' => 'ab',
        ]);
        $this->assertSame('2', $out['xver']);
        $this->assertSame('443', $out['server_port']);
        $this->assertSame('a.example', $out['server_name']);
        $this->assertSame('ab', $out['short_id']);

        $this->assertSame(['xver' => '1'], UniProxyController::nodeTlsSettings(['xver' => '1']));
        $this->assertSame([], UniProxyController::nodeTlsSettings([]));
        $this->assertNull(UniProxyController::nodeTlsSettings(null));
    }

    public function testAliveWindowsFollowThePushInterval()
    {
        // The default interval keeps exactly the old fixed windows.
        $this->assertSame([100, 120], UniProxyController::aliveWindows(60));
        $this->assertSame([100, 120], UniProxyController::aliveWindows(30));
        // A node reporting every two minutes: its addresses must outlive the
        // gap between two of its reports, or its devices go uncounted.
        [$stale, $keep] = UniProxyController::aliveWindows(120);
        $this->assertGreaterThan(120, $stale);
        $this->assertGreaterThan($stale, $keep);
    }

    public function testNodeLoadKeepsWhatTheAdminIsShown()
    {
        $load = UniProxyController::nodeLoad([
            'cpu' => 12.345,
            'mem' => ['total' => 4000, 'used' => 1600],
            'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 100, 'used' => 31],
        ]);
        $this->assertSame(12.3, $load['cpu']);
        $this->assertSame(['total' => 4000, 'used' => 1600], $load['mem']);
        $this->assertSame(['total' => 100, 'used' => 31], $load['disk']);
        $this->assertIsInt($load['updated_at']);

        $this->assertNull(UniProxyController::nodeLoad([]));
        $this->assertNull(UniProxyController::nodeLoad(null));
        $this->assertNull(UniProxyController::nodeLoad(['mem' => ['total' => 1]]));
    }

    public function testNodeLoadCarriesTheVersionTheNodeNames()
    {
        $load = UniProxyController::nodeLoad(['cpu' => 1], 'V2bX/v1.7.2');
        $this->assertSame('v1.7.2', $load['version']);

        // Builds before 1.7.2 send resty's generic agent: no version to show.
        $load = UniProxyController::nodeLoad(['cpu' => 1], 'go-resty/2.16.5 (https://github.com/go-resty/resty)');
        $this->assertNull($load['version']);
        $this->assertNull(UniProxyController::nodeLoad(['cpu' => 1])['version']);
    }

    public function testNodeVersionOnlyAcceptsAPlainVersion()
    {
        $this->assertSame('v1.7.2', UniProxyController::nodeVersion(' V2bX/v1.7.2 '));
        $this->assertSame('1.8.0-rc.1', UniProxyController::nodeVersion('V2bX/1.8.0-rc.1'));
        $this->assertSame('v1.7.2-local', UniProxyController::nodeVersion('V2bX/v1.7.2-local'));
        // It ends up on the admin page, so nothing that is not a version.
        $this->assertNull(UniProxyController::nodeVersion('V2bX/<script>'));
        $this->assertNull(UniProxyController::nodeVersion('V2bX/v1.7.2 extra'));
        $this->assertNull(UniProxyController::nodeVersion('V2bX/TempVersion'));
        $this->assertNull(UniProxyController::nodeVersion('V2bX'));
        $this->assertNull(UniProxyController::nodeVersion(null));
    }

    public function testVlessEncryptionIsNeverSavedWithEmptyParts()
    {
        // Only the method picked: what used to leave the node with an
        // unusable decryption string.
        $this->assertSame(
            ['mode' => 'native', 'rtt' => '1rtt', 'ticket' => '0s'],
            VlessController::encryptionDefaults([])
        );
        $this->assertSame(
            ['rtt' => '0rtt', 'mode' => 'native', 'ticket' => '600s'],
            VlessController::encryptionDefaults(['rtt' => '0rtt'])
        );
        $this->assertSame(
            ['mode' => 'xorpub', 'rtt' => '0rtt', 'ticket' => '300-600s'],
            VlessController::encryptionDefaults(['mode' => 'xorpub', 'rtt' => '0rtt', 'ticket' => '300-600s'])
        );
        // 1-RTT issues no tickets, whatever was typed.
        $this->assertSame('0s', VlessController::encryptionDefaults(['rtt' => '1rtt', 'ticket' => '600s'])['ticket']);
    }

    private function reality(string $pub, string $sid): array
    {
        return [
            'tls' => 2,
            'tls_settings' => ['server_name' => 'a.example', 'public_key' => $pub, 'short_id' => $sid],
            'encryption' => 'mlkem768x25519plus',
            'encryption_settings' => ['mode' => 'native', 'rtt' => '1rtt', 'password' => 'enc-' . $pub],
        ];
    }

    public function testARelayEntryAuthenticatesWithItsParentsKeys()
    {
        $parent = $this->reality('PARENT', 'p1');
        $child = $this->reality('CHILD', 'c1') + ['parent_id' => 1];

        $out = ServerService::withParentKeys($child, $parent);
        $this->assertSame('PARENT', $out['tls_settings']['public_key']);
        $this->assertSame('p1', $out['tls_settings']['short_id']);
        $this->assertSame('enc-PARENT', $out['encryption_settings']['password']);
        // Its own reachability is untouched.
        $this->assertSame('a.example', $out['tls_settings']['server_name']);
    }

    public function testNothingChangesWithoutAMatchingParent()
    {
        $child = $this->reality('CHILD', 'c1');
        $this->assertSame($child, ServerService::withParentKeys($child, null));

        $plainParent = ['tls' => 1, 'tls_settings' => ['server_name' => 'b.example']];
        $out = ServerService::withParentKeys($child, $plainParent);
        $this->assertSame('CHILD', $out['tls_settings']['public_key']);
        $this->assertSame('enc-CHILD', $out['encryption_settings']['password']);

        $noEnc = ['tls' => 0, 'tls_settings' => [], 'encryption' => null, 'encryption_settings' => null];
        $this->assertSame($noEnc, ServerService::withParentKeys($noEnc, $this->reality('PARENT', 'p1')));
    }

    public function testShadowsocksSaveRefusesObfsButNotItsAbsence()
    {
        // The node has no simple-obfs server. "" is what the form sends for
        // "none", null what older rows hold; both must still save.
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new ServerShadowsocksSave())->rules();
        $node = ['name' => 'n', 'group_id' => ['1'], 'host' => 'h', 'port' => '443',
            'server_port' => '443', 'cipher' => 'aes-128-gcm', 'rate' => '1'];
        foreach ([[], ['obfs' => ''], ['obfs' => null]] as $extra) {
            $this->assertFalse($validator->make($node + $extra, $rules)->fails(), json_encode($extra));
        }
        $this->assertTrue($validator->make($node + ['obfs' => 'http'], $rules)->fails());
    }
}

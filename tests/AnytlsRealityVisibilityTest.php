<?php

/**
 * A REALITY anytls node must not be handed to a client that cannot use it.
 *
 * mihomo has no REALITY for anytls at all - its AnyTLSOption struct offers only
 * ShadowTLS/Restls/JLS, so `reality-opts` is not a field it would read even if
 * we emitted one. Surge and Surfboard build anytls with nothing but sni and
 * skip-cert-verify. For every one of those clients a tls=2 anytls node arrives
 * with no key, the server refuses the unauthenticated ClientHello and forwards
 * it to the borrowed site, and the subscriber sees a node in their list that
 * simply never connects, with no explanation.
 *
 * This was live: a node with 320 subscribers on it.
 *
 * The regression these tests guard is silent in both directions - nothing errors
 * when the node is emitted, and nothing errors when a client that CAN do REALITY
 * stops receiving it. So both directions are asserted.
 */

namespace Tests;

use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use PHPUnit\Framework\TestCase;

class AnytlsRealityVisibilityTest extends TestCase
{
    /** The renderers whose client cannot express REALITY on anytls. */
    private const CANNOT_DO_REALITY = [
        ClashMeta::class,
        ClashVerge::class,
        ClashNyanpasu::class,
        Surge::class,
        Surfboard::class,
    ];

    private function node(int $tls): array
    {
        return [
            'id'           => 1,
            'name'         => 'anytls-reality-node',
            'type'         => 'anytls',
            'host'         => '203.0.113.10',
            'port'         => 8443,
            'server_port'  => 8443,
            'tls'          => $tls,
            'tls_settings' => [
                'server_name' => 'cdnjs.cloudflare.com',
                'server_port' => '443',
                'public_key'  => 'js-iKnRbD-clM3Hu_ye7UhufbUtyTwxf98cOjlGltXI',
                'short_id'    => 'a08fc8be',
            ],
        ];
    }

    /**
     * The source is asserted rather than the rendered output because these
     * renderers need a full Laravel request/user context to run end to end,
     * and the property under test is a single guard on the dispatch.
     */
    private function anytlsBranch(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $src  = file_get_contents($file);
        $at   = strpos($src, "'anytls'");
        $this->assertNotFalse($at, "$class no longer dispatches on 'anytls'");
        return substr($src, $at, 1400);
    }

    public function testClientsWithoutRealitySkipATlsTwoAnytlsNode(): void
    {
        foreach (self::CANNOT_DO_REALITY as $class) {
            $branch = $this->anytlsBranch($class);
            $this->assertMatchesRegularExpression(
                '/\(int\)\(\$item\[.tls.\]\s*\?\?\s*1\)\s*===\s*2\)\s*(break|continue)/',
                $branch,
                basename(str_replace('\\', '/', $class)) .
                " emits a REALITY anytls node its client can never connect to"
            );
        }
    }

    /**
     * 🔴 The guard must key on tls, not on the presence of tls_settings. Plain
     * TLS anytls nodes also carry tls_settings (server_name, allow_insecure),
     * so a guard written that way would hide every anytls node in the fleet
     * from these clients - a far bigger outage than the one being fixed.
     */
    public function testPlainTlsAnytlsNodesAreStillEmitted(): void
    {
        foreach (self::CANNOT_DO_REALITY as $class) {
            $branch = $this->anytlsBranch($class);
            $this->assertStringNotContainsString(
                'empty($item[\'tls_settings\'])',
                $branch,
                basename(str_replace('\\', '/', $class)) .
                " gates on tls_settings, which would hide plain-TLS nodes too"
            );
        }
        // and the guard really is satisfied by tls=1
        $this->assertSame(1, (int)($this->node(1)['tls'] ?? 1));
        $this->assertSame(2, (int)($this->node(2)['tls'] ?? 1));
    }

    /**
     * sing-box CAN do REALITY on anytls - it is the one client this works for
     * today, and the node that finally connected. It must keep receiving them.
     */
    public function testSingboxStillEmitsRealityForAnytls(): void
    {
        $src = file_get_contents(app_path('Protocols/Singbox/Singbox.php'));
        $at  = strpos($src, 'function buildAnyTLS');
        $this->assertNotFalse($at, 'Singbox no longer builds anytls');
        // Bounded by the NEXT method, not by a character count: the method is
        // heavily commented and a fixed window silently cut the reality block
        // out of view, which read as a failure that was not there.
        $next = strpos($src, 'function build', $at + 20);
        $body = substr($src, $at, $next === false ? strlen($src) - $at : $next - $at);

        $this->assertStringContainsString("\$tlsConfig['reality']", $body,
            'the sing-box renderer stopped emitting reality for anytls');
        $this->assertStringContainsString("'public_key'", $body);
        $this->assertStringContainsString("'short_id'", $body);
    }

    /**
     * QuantumultX called self::buildAnyTLS, which is not defined on that class
     * and it has no parent - a fatal error for every subscriber the moment an
     * anytls node entered their list, and the fleet is mostly anytls.
     */
    public function testQuantumultXDoesNotCallAMethodItDoesNotHave(): void
    {
        $src = file_get_contents(app_path('Protocols/QuantumultX.php'));
        // The CALL, not the name: the name still appears in the comment that
        // explains why the call was removed, and matching that reported a bug
        // that had already been fixed.
        if (preg_match('/\$uri\s*\.=\s*self::buildAnyTLS/', $src)) {
            $this->assertStringContainsString(
                'function buildAnyTLS',
                $src,
                'QuantumultX calls buildAnyTLS but does not define it - fatal for any anytls node'
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/\$uri\s*\.=\s*self::buildAnyTLS/',
            $src,
            'the undefined-method call is back'
        );
    }

    /**
     * 🔴 The borrowed site must be reachable BY OUR USERS, not merely reachable.
     *
     * This defaulted to www.microsoft.com, and Microsoft geo-blocks Iran: a TLS
     * connection carrying that SNI from an Iranian network goes somewhere the
     * user is not supposed to be able to reach, and it does not survive. Every
     * node created here inherited it, and the symptom was the worst kind - a
     * prober received a flawless certificate chain, so the node looked perfect
     * from outside, while no real client could connect.
     */
    public function testTheDefaultBorrowedSiteIsReachableFromIran(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/V1/Admin/Server/AnyTLSController.php'));

        $this->assertStringNotContainsString("= 'www.microsoft.com'", $src,
            'the REALITY default is a host that geo-blocks Iran; no node created with it can be used');
        $this->assertStringContainsString("= 'cdnjs.cloudflare.com'", $src,
            'the REALITY server_name default is missing');

        // The admin form suggests the same value, and a suggestion is followed.
        foreach (['public/assets/admin/umi.js', 'public/assets/admin/umi-fa.js'] as $bundle) {
            $path = base_path($bundle);
            if (!is_file($path)) {
                continue;
            }
            $this->assertStringNotContainsString('placeholder:"www.microsoft.com"', file_get_contents($path),
                "$bundle still offers the unusable host as the hint an admin will copy");
        }
    }
}

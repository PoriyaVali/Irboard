<?php

namespace Tests\Feature;

use App\Console\Commands\MirrorBuild;
use App\Protocols\General;
use App\Protocols\Hiddify\Hiddify;
use Tests\TestCase;

/**
 * What the relay's copy of a configuration file carries besides the body.
 *
 * The mirror kept the body and a content type that was always text/plain, so
 * an update served during an outage reached the app with no
 * subscription-userinfo: the quota and expiry it showed were wiped by the
 * very update that was meant to keep the subscription alive.
 */
class MirrorSubscribeHeadersTest extends TestCase
{
    private const USER = ['id' => 7, 'uuid' => '11111111-2222-3333-4444-555555555555', 'u' => 1024, 'd' => 4096,
        'transfer_enable' => 1073741824, 'expired_at' => 1900000000];

    private static function node(): array
    {
        return ['type' => 'vless', 'name' => 'first', 'host' => 'node.example.test', 'port' => 443, 'tls' => 1,
            'flow' => null, 'network' => 'ws', 'network_settings' => ['path' => '/ws'],
            'tls_settings' => ['server_name' => 'node.example.test']];
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheAccountHeadersTravelWithEveryResponseRenderer(): void
    {
        config(['v2board.app_name' => 'Panel']);
        foreach ([Hiddify::class, General::class] as $class) {
            $response = (new $class(self::USER, [self::node()]))->handle();
            $headers = MirrorBuild::subscribeHeaders($response);

            $this->assertSame(
                'upload=1024; download=4096; total=1073741824; expire=1900000000',
                $headers['subscription-userinfo'] ?? null,
                $class
            );
            $this->assertSame('24', $headers['profile-update-interval'] ?? null, $class);
            $this->assertSame('base64:' . base64_encode('Panel'), $headers['profile-title'] ?? null, $class);
            $this->assertArrayHasKey('content-disposition', $headers, $class);
            // Nothing outside the list - the relay sends these to the client.
            $this->assertSame([], array_diff(array_keys($headers), MirrorBuild::SUBSCRIBE_HEADERS), $class);
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheSettingsDocumentAddressIsNotCarried(): void
    {
        // Built from the request's host, which in the build command is
        // localhost: no device could reach it.
        $template = base_path('resources/rules/custom.dm-app.json');
        $made = !file_exists($template) && !file_exists(base_path('resources/rules/default.dm-app.json'));
        if ($made) {
            file_put_contents($template, '{}');
        }
        try {
            $response = (new Hiddify(self::USER, [self::node()]))->handle();
            $this->assertNotNull($response->headers->get('dm-config-url'), 'the renderer should announce one');
            $this->assertArrayNotHasKey('dm-config-url', MirrorBuild::subscribeHeaders($response));
        } finally {
            if ($made) {
                @unlink($template);
            }
        }
    }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheContentTypeIsTheRenderersOwn(): void
    {
        $sing = (new Hiddify(self::USER, [self::node()]))->handle();
        $this->assertSame('application/json', MirrorBuild::contentType($sing));
        $plain = (new General(self::USER, [self::node()]))->handle();
        $this->assertSame('text/plain; charset=utf-8', MirrorBuild::contentType($plain));
    }

    public function testARendererThatReturnsAStringCarriesNothing(): void
    {
        // The Clash family: its headers went through header(), inert in CLI.
        $this->assertSame([], MirrorBuild::subscribeHeaders("proxies: []\n"));
        $this->assertSame('text/plain; charset=utf-8', MirrorBuild::contentType("proxies: []\n"));
    }
}

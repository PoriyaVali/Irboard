<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The settings document the Doctor Mobile app adopts as its defaults
 * (resources/rules/default.dm-app.json, served at guest/comm/appConfig).
 *
 * The file is hand-annotated JSON with comments and grouped sections, and the
 * app only understands the flat form the controller turns it into - so a
 * stray comma or a comment in the wrong place reaches every phone as a 500.
 */
class AppConfigDocumentTest extends TestCase
{
    private function document(): array
    {
        $response = $this->get('/api/v1/guest/comm/appConfig');
        $response->assertStatus(200);
        return $response->json();
    }

    public function testTheShippedFileParsesAndIsFlattened()
    {
        $doc = $this->document();

        $this->assertIsInt($doc['version']);
        $settings = $doc['settings'];
        // Sections are for the operator; the app reads one flat map.
        foreach (['app', 'sing-box', 'evasion', 'mihomo', 'mdns', 'trusttunnel', 'anti-dpi'] as $section) {
            $this->assertArrayNotHasKey($section, $settings);
        }
        foreach (array_keys($settings) as $key) {
            $this->assertStringStartsNotWith('_', $key, 'editor notes must not reach the app');
        }
        $this->assertSame('mihomo', $settings['selected_core']);
        $this->assertFalse($settings['mdns_load_balancer']);
        $this->assertTrue($settings['tt_bypass_iran']);
        $this->assertFalse($settings['mirage-enable']);
        $this->assertSame('vpn', $settings['service-mode']);
        // The shipped file enforces nothing.
        $this->assertArrayNotHasKey('force', $doc);
    }

    public function testUpdateSourcesNameOnlyGithub()
    {
        // This file is public, so it names no mirror of an operator's own:
        // the app appends its built-in sources after this list anyway, and a
        // panel adds its own in custom.dm-app.json.
        $this->assertSame([
            'https://raw.githubusercontent.com/PoriyaVali/drmobile-updates/main',
        ], $this->document()['settings']['update_sources']);
    }

    public function testTheShippedValuesAreTheAppsOwn()
    {
        // Publishing the file as-is must change nothing on a phone, so these
        // carry the app's compiled-in values.
        $settings = $this->document()['settings'];
        $this->assertTrue($settings['transfer_check']);
        // Not published: the app uses its built-in endpoint, and a panel that
        // runs its own sets it in custom.dm-app.json.
        $this->assertArrayNotHasKey('transfer_check_endpoints', $settings);
        $this->assertFalse($settings['enable-fake-dns']);
        $this->assertTrue($settings['independent-dns-cache']);
        // On in the app since 2.3.11, on every core: the proxy can never
        // reach a private address, so a tunnelled LAN is only a broken one.
        $this->assertTrue($settings['bypass-lan']);
        // Empty means each region's own resolvers. The Shecan address that
        // used to be here reached Russian and Chinese users as well.
        $this->assertSame('', $settings['mh_direct_ns']);
    }

    public function testTheShippedTemplatesNameOnlyPublicHosts()
    {
        // These files are public. An operator's own hosts - a relay, a mirror,
        // the panel itself - belong in the custom.* copies next to them, which
        // git ignores. Any new host here has to be added to this list on
        // purpose.
        $public = [
            'raw.githubusercontent.com', 'cdn.jsdelivr.net', 'www.gstatic.com',
            'dns.google', 'dns.cloudflare.com', 'dns.quad9.net', 'dns.alidns.com',
            'doh.pub', '1.1.1.1', '1.0.0.1', 'cp.cloudflare.com', 'captive.apple.com',
            'www.google.com', 'bing.com', 'trello.com', 'manual.nssurge.com',
            'unpkg.zhimg.com', '13800000000.rubyfish.cn', 'panel.example.com',
        ];
        $files = array_merge(
            glob(base_path('resources/rules/default.*')),
            [base_path('resources/rules/app.clash.yaml')]
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            preg_match_all('#https?://([^/"\' ,)\s]+)#', file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $host) {
                $this->assertContains($host, $public, basename($file) . " names a host that is not on the public list: $host");
            }
        }
    }

    public function testOperatorSectionsArePassedOnFlattened()
    {
        $custom = base_path('resources/rules/custom.dm-app.json');
        $this->assertFileDoesNotExist($custom, 'a custom file would be overwritten by this test');
        file_put_contents($custom, <<<'JSON'
        {
          "version": 3,
          "settings": { "app": { "selected_core": "singbox" } },
          "operators": {
            "_readme": "notes are dropped",
            "Irancell": {
              "settings": { "mihomo": { "mh_ipv6": true, "_note": "dropped" }, "selected_core": "mihomo" },
              "force": ["selected_core"]
            },
            "wifi": { "mdns_racing": true },   // shorthand: the section is the settings
            "empty": { "force": true },
            "bad": ["not", "a", "map"]
          },
          "operator_codes": { "43221": "Rightel", "_x": "y", "43222": 5 },
          "operator_asns": { "64500": "example" }
        }
        JSON);
        try {
            $doc = $this->document();
        } finally {
            @unlink($custom);
        }

        $this->assertSame(['selected_core' => 'singbox'], $doc['settings']);
        $this->assertSame(['irancell', 'wifi'], array_keys($doc['operators']));
        $this->assertSame(['mh_ipv6' => true, 'selected_core' => 'mihomo'], $doc['operators']['irancell']['settings']);
        $this->assertSame(['selected_core'], $doc['operators']['irancell']['force']);
        $this->assertSame(['mdns_racing' => true], $doc['operators']['wifi']['settings']);
        $this->assertArrayNotHasKey('force', $doc['operators']['wifi']);
        $this->assertSame(['43221' => 'rightel'], $doc['operator_codes']);
        $this->assertSame(['64500' => 'example'], $doc['operator_asns']);
    }

    public function testTheShippedFileSendsNoOperatorSections()
    {
        // Documented in the file's notes, never applied by publishing it.
        $doc = $this->document();
        $this->assertArrayNotHasKey('operators', $doc);
        $this->assertArrayNotHasKey('operator_codes', $doc);
    }

    public function testTheBodyIsTheSameEveryTime()
    {
        // The app skips a document it has already applied by comparing it, so
        // a body that changed on every request would be re-applied on every one.
        $first = $this->get('/api/v1/guest/comm/appConfig')->getContent();
        $second = $this->get('/api/v1/guest/comm/appConfig')->getContent();
        $this->assertSame($first, $second);
    }
}

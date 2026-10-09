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
        // The shipped file enforces nothing.
        $this->assertArrayNotHasKey('force', $doc);
    }

    public function testUpdateSourcesKeepGithubFirstAndTheRelaySecond()
    {
        // The same list the app has built in, so publishing the file as-is
        // changes nothing; an operator edits it to reorder or add mirrors.
        $this->assertSame([
            'https://raw.githubusercontent.com/PoriyaVali/drmobile-updates/main',
            'https://drmobjay.com/updates',
        ], $this->document()['settings']['update_sources']);
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

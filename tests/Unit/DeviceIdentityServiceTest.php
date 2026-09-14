<?php

namespace Tests\Unit;

use App\Services\DeviceIdentityService;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of device recording: what a header may say, and what counts
 * as one device.
 *
 * PHPUnit's own TestCase rather than the app's: nothing here needs Laravel
 * booted or a database, and these must never be able to reach one.
 */
class DeviceIdentityServiceTest extends TestCase
{
    private function h(string $c): string
    {
        return str_repeat($c, 64);
    }

    public function testKeepsValidPairsSortedSoOneDeviceIsAlwaysOneList()
    {
        $ids = DeviceIdentityService::parseHeader('widevine:' . $this->h('b') . ',androidid:' . $this->h('a'));
        $this->assertSame(['androidid:' . $this->h('a'), 'widevine:' . $this->h('b')], $ids);
    }

    public function testDropsAMalformedPairButKeepsTheGoodOneBesideIt()
    {
        $ids = DeviceIdentityService::parseHeader('androidid:not-a-hash,widevine:' . $this->h('b'));
        $this->assertSame(['widevine:' . $this->h('b')], $ids);
    }

    public function testATruncatedOrPaddedHashIsNotAHash()
    {
        $this->assertSame([], DeviceIdentityService::parseHeader('androidid:' . str_repeat('a', 63)));
        $this->assertSame([], DeviceIdentityService::parseHeader('androidid:' . str_repeat('a', 65)));
        // Trailing junk after a valid hash must not smuggle anything through.
        $this->assertSame([], DeviceIdentityService::parseHeader('androidid:' . $this->h('a') . ' x'));
    }

    public function testNormalisesCaseAndDuplicates()
    {
        $ids = DeviceIdentityService::parseHeader('AndroidID:' . strtoupper($this->h('a')) . ',androidid:' . $this->h('a'));
        $this->assertSame(['androidid:' . $this->h('a')], $ids);
    }

    public function testIgnoresAnOversizedHeaderWhole()
    {
        $header = implode(',', array_fill(0, 10, 'androidid:' . $this->h('a')));
        $this->assertSame([], DeviceIdentityService::parseHeader($header));
    }

    public function testReadsNoMoreThanTheCapOfPairs()
    {
        $header = implode(',', ['aa:' . $this->h('1'), 'bb:' . $this->h('2'), 'cc:' . $this->h('3'),
            'dd:' . $this->h('4'), 'ee:' . $this->h('5')]);
        $this->assertCount(DeviceIdentityService::MAX_PAIRS, DeviceIdentityService::parseHeader($header));
    }

    public function testFirstSightingIsOneDevice()
    {
        $out = DeviceIdentityService::merge([], ['androidid:' . $this->h('a')], 100);
        $this->assertCount(1, $out);
        $this->assertSame(100, $out[0]['f']);
        $this->assertSame(100, $out[0]['l']);
    }

    public function testTheSameDeviceLaterIsStillOneDeviceWithItsFirstSightingKept()
    {
        $ids = ['androidid:' . $this->h('a'), 'widevine:' . $this->h('b')];
        $out = DeviceIdentityService::merge(DeviceIdentityService::merge([], $ids, 100), $ids, 900);
        $this->assertCount(1, $out);
        $this->assertSame(100, $out[0]['f']);
        $this->assertSame(900, $out[0]['l']);
    }

    public function testTwoPhonesAreTwoDevices()
    {
        $out = DeviceIdentityService::merge([], ['androidid:' . $this->h('a')], 100);
        $out = DeviceIdentityService::merge($out, ['androidid:' . $this->h('c')], 200);
        $this->assertCount(2, $out);
    }

    public function testAFactoryResetPhoneIsTheSameDeviceThroughItsWidevineId()
    {
        // Before the reset: both signals. After: ANDROID_ID regenerated, the
        // Widevine id unchanged. Counting that as a new device would let a reset
        // wipe an account's history.
        $out = DeviceIdentityService::merge([], ['androidid:' . $this->h('a'), 'widevine:' . $this->h('b')], 100);
        $out = DeviceIdentityService::merge($out, ['androidid:' . $this->h('d'), 'widevine:' . $this->h('b')], 200);
        $this->assertCount(1, $out);
        $this->assertCount(3, $out[0]['ids']);
        $this->assertSame(100, $out[0]['f']);
    }

    public function testTwoPartialSightingsMergeOnceOneRequestCarriesBoth()
    {
        $out = DeviceIdentityService::merge([], ['androidid:' . $this->h('a')], 100);
        $out = DeviceIdentityService::merge($out, ['widevine:' . $this->h('b')], 200);
        $this->assertCount(2, $out, 'unlinked until something ties them together');
        $out = DeviceIdentityService::merge($out, ['androidid:' . $this->h('a'), 'widevine:' . $this->h('b')], 300);
        $this->assertCount(1, $out);
        $this->assertSame(100, $out[0]['f']);
    }

    public function testAnAccountOverTheCapLosesTheDeviceUnusedLongest()
    {
        $out = [];
        for ($i = 0; $i < DeviceIdentityService::MAX_DEVICES + 1; $i++) {
            $out = DeviceIdentityService::merge($out, ['androidid:' . hash('sha256', (string)$i)], 1000 + $i);
        }
        $this->assertCount(DeviceIdentityService::MAX_DEVICES, $out);
        $seen = array_map(function ($d) { return $d['l']; }, $out);
        $this->assertTrue(!in_array(1000, $seen, true), 'the oldest device should have been dropped');
        $this->assertSame(1000 + DeviceIdentityService::MAX_DEVICES, $out[0]['l']);
    }

    public function testADeviceOverItsHashCapKeepsWhatItStillSends()
    {
        $current = 'widevine:' . $this->h('b');
        $out = DeviceIdentityService::merge([], [$current], 100);
        for ($i = 0; $i < DeviceIdentityService::MAX_IDS_PER_DEVICE + 3; $i++) {
            $out = DeviceIdentityService::merge($out, [$current, 'androidid:' . hash('sha256', "reset$i")], 200 + $i);
        }
        $this->assertCount(1, $out);
        $this->assertCount(DeviceIdentityService::MAX_IDS_PER_DEVICE, $out[0]['ids']);
        $this->assertTrue(in_array($current, $out[0]['ids'], true), 'the hash still being sent must survive the cap');
    }

    public function testGarbageInTheStoredColumnIsSkippedNotFatal()
    {
        $stored = ['not a device', ['ids' => 'not a list'], ['no ids' => true]];
        $out = DeviceIdentityService::merge($stored, ['androidid:' . $this->h('a')], 100);
        $this->assertCount(1, $out);
    }
}

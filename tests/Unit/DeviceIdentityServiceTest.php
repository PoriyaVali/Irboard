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

    public function testAnAccountOverTheCapLosesTheDeviceSeenFewestTimes()
    {
        $out = [];
        for ($i = 0; $i < DeviceIdentityService::MAX_DEVICES + 1; $i++) {
            $out = DeviceIdentityService::merge($out, ['androidid:' . hash('sha256', (string)$i)], 1000 + $i);
        }
        $this->assertCount(DeviceIdentityService::MAX_DEVICES, $out);
        // All were seen once, so the tie is broken in favour of the one known
        // longest: the first device stays and the newest arrival is dropped.
        $ids = [];
        foreach ($out as $d) {
            $ids = array_merge($ids, $d['ids']);
        }
        $this->assertTrue(in_array('androidid:' . hash('sha256', '0'), $ids, true),
            'the device known longest must not be dropped');
    }

    /**
     * 🔴 The attack this feature has to survive, and once did not.
     *
     * The header is client-supplied. When the cap dropped the least recently
     * seen device, a user could push their own real device out with junk
     * headers and erase the link - measured on production: 20 was enough.
     */
    public function testJunkHeadersCannotEraseADeviceThatWasActuallyUsed()
    {
        $real = ['androidid:' . $this->h('a'), 'widevine:' . $this->h('b')];
        // Seen twice, as any genuinely used device is: two gate windows apart.
        $out = DeviceIdentityService::merge([], $real, 1000);
        $out = DeviceIdentityService::merge($out, $real, 1000 + DeviceIdentityService::SEEN_TTL);

        // Now the flood: far more junk devices than the cap, all arriving later.
        for ($i = 0; $i < DeviceIdentityService::MAX_DEVICES * 3; $i++) {
            $out = DeviceIdentityService::merge($out, ['androidid:' . hash('sha256', "junk$i")], 90000 + $i);
        }

        $ids = [];
        foreach ($out as $d) {
            $ids = array_merge($ids, $d['ids']);
        }
        $this->assertCount(DeviceIdentityService::MAX_DEVICES, $out);
        $this->assertTrue(in_array($real[0], $ids, true), 'the real device was erased by junk');
        $this->assertTrue(in_array($real[1], $ids, true), 'the real widevine hash was erased by junk');
    }

    public function testRepeatedSightingsAreCountedAndSurviveAMerge()
    {
        $a = 'androidid:' . $this->h('a');
        $w = 'widevine:' . $this->h('b');
        // Seen separately, then tied together - the counts add up rather than
        // resetting, so a long-known device is not demoted by being merged.
        $out = DeviceIdentityService::merge([], [$a], 100);
        $out = DeviceIdentityService::merge($out, [$a], 200);
        $out = DeviceIdentityService::merge($out, [$w], 300);
        $out = DeviceIdentityService::merge($out, [$a, $w], 400);
        $this->assertCount(1, $out);
        // Four sightings went in - 100, 200, 300, 400 - and four is what the
        // single surviving device carries: the two records that turned out to
        // be one device contribute their own counts, plus this sighting.
        $this->assertSame(4, $out[0]['n']);
        $this->assertSame(100, $out[0]['f']);
    }

    public function testARowWrittenBeforeSightingsWereCountedStillCounts()
    {
        // Rows already on production have no "n"; they must read as one
        // sighting, not zero, or the first write after the upgrade would rank
        // every existing device below fresh junk.
        $legacy = [['ids' => ['androidid:' . $this->h('c')], 'f' => 10, 'l' => 20]];
        $out = DeviceIdentityService::merge($legacy, ['androidid:' . $this->h('c')], 30);
        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]['n']);
        $this->assertSame(10, $out[0]['f']);
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

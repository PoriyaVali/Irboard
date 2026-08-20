<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records how many devices each account had, so it can be asked about later.
 *
 * The live number already exists: nodes push their alive IPs, and
 * UniProxyController folds them into ALIVE_IP_USER_<id>. With
 * device_limit_mode=1 that fold counts DISTINCT IPs across every node, which is
 * what "devices" means here - not sessions, and not connections. Two phones on
 * one wifi are one IP and count once; that is a property of IP counting and not
 * something this command can fix.
 *
 * What was missing is any memory of it. This samples the counter and keeps one
 * row per user per day: the peak, and enough to take an average from.
 *
 * Only users that appear online are touched. The cache holds an entry for a
 * user a node has reported, so on a panel with thousands of accounts this reads
 * and writes the few dozen that are actually connected.
 */
class SampleUserDevices extends Command
{
    protected $signature = 'stat:devices';
    protected $description = 'Sample each account\'s live device count into v2_stat_user_device';

    /**
     * Rows older than this are dropped. The app asks for thirty days; the extra
     * few are slack so a day is never missing at the edge of the window.
     */
    private const KEEP_DAYS = 35;

    public function handle()
    {
        $now = time();
        $day = strtotime('today');

        // Chunked rather than pluck()->all(): the id list of a large panel is
        // not worth holding in memory all at once, and Cache::many wants
        // batches anyway.
        $seen = 0;
        User::whereNotNull('plan_id')
            ->select('id')
            ->chunkById(500, function ($users) use ($now, $day, &$seen) {
                $keys = [];
                $idOf = [];
                foreach ($users as $u) {
                    $key = 'ALIVE_IP_USER_' . $u->id;
                    $keys[] = $key;
                    $idOf[$key] = $u->id;
                }

                $rows = [];
                foreach (Cache::many($keys) as $key => $data) {
                    if (!is_array($data) || !isset($data['alive_ip'])) {
                        continue;
                    }
                    $count = (int) $data['alive_ip'];
                    if ($count <= 0) {
                        continue;
                    }
                    $rows[] = [
                        'user_id' => $idOf[$key],
                        'record_at' => $day,
                        'peak' => $count,
                        'total' => $count,
                        'samples' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (empty($rows)) {
                    return;
                }
                $seen += count($rows);

                // One statement for the whole chunk. GREATEST keeps the peak
                // and the other two accumulate, so the row is correct whether
                // this is the day's first sample or its two hundredth - and it
                // stays correct if the command is run twice by mistake, which
                // a read-modify-write in PHP would not.
                DB::table('v2_stat_user_device')->upsert(
                    $rows,
                    ['user_id', 'record_at'],
                    [
                        'peak' => DB::raw('GREATEST(`peak`, VALUES(`peak`))'),
                        'total' => DB::raw('`total` + VALUES(`total`)'),
                        'samples' => DB::raw('`samples` + VALUES(`samples`)'),
                        'updated_at' => DB::raw('VALUES(`updated_at`)'),
                    ]
                );
            });

        // Retention, here rather than in its own daily command: this runs every
        // few minutes, the delete is indexed and matches nothing on almost
        // every run, and one moving part is easier to keep honest than two.
        $cutoff = $day - (self::KEEP_DAYS * 86400);
        $dropped = DB::table('v2_stat_user_device')
            ->where('record_at', '<', $cutoff)
            ->delete();

        $this->info("sampled {$seen} online account(s); dropped {$dropped} row(s) older than " . self::KEEP_DAYS . ' days');
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply queued traffic usage to users';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }
        // Take both directions atomically. A report arriving during this drain
        // belongs either to this snapshot or the next; it cannot be deleted
        // between HGETALL and DEL, or split across the two directions.
        $drainScript = <<<'LUA'
local uploads = redis.call('HGETALL', KEYS[1])
local downloads = redis.call('HGETALL', KEYS[2])
redis.call('DEL', KEYS[1], KEYS[2])
return {uploads, downloads}
LUA;
        $snapshot = Redis::eval($drainScript, 2, 'v2board_upload_traffic', 'v2board_download_traffic');
        $uploads = $this->hashValues($snapshot[0]);
        $downloads = $this->hashValues($snapshot[1]);
        if (empty($uploads) && empty($downloads)) {
            return;
        }

        $userIds = array_unique(array_merge(array_keys($uploads), array_keys($downloads)));
        $time = time();
        $casesU = [];
        $casesD = [];
        $idList = [];

        foreach ($userIds as $userId) {
            $casesU[] = "WHEN {$userId} THEN " . ($uploads[$userId] ?? 0);
            $casesD[] = "WHEN {$userId} THEN " . ($downloads[$userId] ?? 0);
            $idList[] = $userId;
        }
        $idListStr = implode(',', $idList);
        $casesUStr = implode(' ', $casesU);
        $casesDStr = implode(' ', $casesD);
        // Add inside SQL instead of writing a previously read counter value.
        // Concurrent drains/admin increments cannot overwrite one another.
        $sql = "UPDATE v2_user SET u = u + CASE id {$casesUStr} ELSE 0 END, d = d + CASE id {$casesDStr} ELSE 0 END, t = {$time}, updated_at = {$time} WHERE id IN ({$idListStr})";
        try {
            DB::beginTransaction();
            DB::statement($sql);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            // A failed database write must leave the bytes pending for retry.
            // HINCRBY merges them with reports received after the snapshot.
            Redis::transaction(function ($redis) use ($uploads, $downloads) {
                foreach ($uploads as $id => $bytes) $redis->hIncrBy('v2board_upload_traffic', (string)$id, $bytes);
                foreach ($downloads as $id => $bytes) $redis->hIncrBy('v2board_download_traffic', (string)$id, $bytes);
            });
            \Log::error('Traffic update failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function hashValues(array $values): array
    {
        $result = [];
        foreach (array_chunk($values, 2) as [$id, $bytes]) {
            $result[(int)$id] = (int)$bytes;
        }
        return $result;
    }
}

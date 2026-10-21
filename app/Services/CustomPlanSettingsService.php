<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Admin approval is a plan/period allowlist, never a second price store. */
class CustomPlanSettingsService
{
    public const KEY = 'custom_plan_pricing';
    public const PERIOD_DAYS = ['month_price' => 30, 'quarter_price' => 90, 'half_year_price' => 180, 'year_price' => 365];
    public const MODEL = ['offset_gb' => 150, 'reference_gb' => 185, 'reference_days' => 30, 'alpha' => 1.13, 'beta' => 0.20];

    private static function fail(int $status, string $code, array $extra = []): void
    {
        throw new HttpResponseException(response()->json(['message' => $code, 'code' => $code] + $extra, $status));
    }

    private static function defaults(): array
    {
        return ['enabled' => false, 'classifications' => [], 'references' => []];
    }

    private static function hash($value): string
    {
        return hash('sha256', json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public static function capable(): bool
    {
        if (!Schema::hasColumns('v2_bot_settings', ['key', 'value', 'created_at', 'updated_at']) || !CustomPlanService::enabled()) return false;
        // First-save concurrency relies on a real single-column unique key,
        // not merely on a table with similarly named columns.
        $unique = false;
        if (DB::connection()->getDriverName() === 'mysql') {
            $indexes = [];
            foreach (DB::select('SHOW INDEX FROM `v2_bot_settings`') as $index) {
                if ((int)$index->Non_unique === 0) $indexes[$index->Key_name][] = $index->Column_name;
            }
            foreach ($indexes as $columns) if ($columns === ['key']) $unique = true;
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('v2_bot_settings')") as $index) {
                if ((int)$index->unique !== 1) continue;
                $name = str_replace('"', '""', $index->name);
                $columns = DB::select('PRAGMA index_info("' . $name . '")');
                if (count($columns) === 1 && $columns[0]->name === 'key') $unique = true;
            }
        }
        if (!$unique) return false;
        return !Schema::hasTable('v2_plan_prices') || Schema::hasColumns('v2_plan_prices', [
            'plan_id', 'month_price_usd', 'quarter_price_usd', 'half_year_price_usd', 'year_price_usd']);
    }

    /** No default row is inserted by reading or previewing. */
    private static function state(bool $lock = false): array
    {
        $raw = null;
        $exists = false;
        if (Schema::hasTable('v2_bot_settings')) {
            $query = DB::table('v2_bot_settings')->where('key', self::KEY);
            if ($lock) $query->lockForUpdate();
            $row = $query->first();
            if ($row) { $raw = $row->value; $exists = true; }
        }
        $valid = !$exists;
        $configuration = self::defaults();
        if ($raw !== null) {
            try {
                $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (($value['version'] ?? null) === 1 && is_string($value['generation'] ?? null)) {
                    $configuration = self::normalize($value['configuration'] ?? null);
                    $valid = true;
                }
            } catch (\Throwable $e) { /* Corrupt/partial configuration disables purchases. */ }
        }
        return ['raw' => $raw, 'exists' => $exists, 'valid' => $valid, 'configuration' => $configuration,
            'settings_revision' => self::hash(['exists' => $exists, 'raw' => $raw])];
    }

    /** Public for the admin controller; strict JSON types avoid truthy strings. */
    public static function normalize($configuration): array
    {
        if (!is_array($configuration) || !isset($configuration['enabled']) || !is_bool($configuration['enabled'])
            || !isset($configuration['classifications'], $configuration['references'])
            || !is_array($configuration['classifications']) || !is_array($configuration['references'])
            || count($configuration['classifications']) > 300 || count($configuration['references']) > 1000
            || array_diff(array_keys($configuration), ['enabled', 'classifications', 'references'])) {
            self::fail(422, 'custom_settings_invalid');
        }
        $classes = [];
        foreach ($configuration['classifications'] as $row) {
            if (!is_array($row) || !is_int($row['plan_id'] ?? null) || $row['plan_id'] < 1
                || !in_array($row['quota_type'] ?? null, ['unknown', 'finite_total', 'unlimited_fup'], true)
                || array_diff(array_keys($row), ['plan_id', 'quota_type']) || isset($classes[$row['plan_id']])) {
                self::fail(422, 'custom_settings_invalid');
            }
            $classes[$row['plan_id']] = $row;
        }
        ksort($classes, SORT_NUMERIC);
        $references = [];
        foreach ($configuration['references'] as $row) {
            if (!is_array($row) || !is_int($row['plan_id'] ?? null) || $row['plan_id'] < 1
                || !in_array($row['period'] ?? null, array_keys(self::PERIOD_DAYS), true)
                || array_diff(array_keys($row), ['plan_id', 'period'])) self::fail(422, 'custom_settings_invalid');
            $key = $row['plan_id'] . ':' . $row['period'];
            if (isset($references[$key])) self::fail(422, 'custom_settings_invalid');
            $references[$key] = $row;
        }
        ksort($references, SORT_NATURAL);
        $normalized = ['enabled' => $configuration['enabled'], 'classifications' => array_values($classes), 'references' => array_values($references)];
        if (strlen(json_encode($normalized, JSON_THROW_ON_ERROR)) > 60000) self::fail(422, 'custom_settings_invalid');
        return $normalized;
    }

    public static function cohort(Plan $plan): array
    {
        return ['group_id' => (int)$plan->group_id,
            'device_limit' => $plan->device_limit === null ? null : (int)$plan->device_limit,
            'speed_limit' => $plan->speed_limit === null ? null : (int)$plan->speed_limit,
            'reset_traffic_method' => $plan->reset_traffic_method === null ? (int)config('v2board.reset_traffic_method', 0) : (int)$plan->reset_traffic_method];
    }

    /** Snapshot all ordinary tariffs; prices remain owned by the existing editor. */
    private static function sources(bool $lock = false): array
    {
        $query = Plan::whereNull('custom_key')->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $plans = $query->get();
        $usdRows = collect();
        if (Schema::hasTable('v2_plan_prices')) {
            $query = DB::table('v2_plan_prices')->whereIn('plan_id', $plans->pluck('id'));
            if ($lock) $query->lockForUpdate();
            $usdRows = $query->get()->keyBy('plan_id');
        }
        $rate = ExchangeService::getCurrentRate();
        $candidates = [];
        foreach ($plans as $plan) {
            $cohort = self::cohort($plan);
            $reasons = [];
            if ((int)$plan->show !== 1) $reasons[] = 'hidden_plan';
            if ($plan->capacity_limit !== null) $reasons[] = 'capacity_limited';
            if (!is_numeric($plan->transfer_enable) || (float)$plan->transfer_enable !== (float)(int)$plan->transfer_enable
                || $plan->transfer_enable < 1 || $plan->transfer_enable > 1024) $reasons[] = 'invalid_volume';
            if ($cohort['reset_traffic_method'] !== 2) $reasons[] = 'resetting_quota';
            if ($cohort['group_id'] <= 0 || ($cohort['device_limit'] !== null && $cohort['device_limit'] < 0)
                || ($cohort['speed_limit'] !== null && $cohort['speed_limit'] < 0)) $reasons[] = 'invalid_access';
            if ($rate <= 0) $reasons[] = 'exchange_unavailable';
            $periods = [];
            foreach (self::PERIOD_DAYS as $period => $days) {
                $errors = $reasons;
                $toman = $plan->$period;
                if (!is_numeric($toman) || !is_finite((float)$toman) || $toman <= 0) $errors[] = 'missing_price';
                $field = $period . '_usd';
                $overlay = $usdRows->get($plan->id);
                $usd = $overlay ? $overlay->$field : $plan->$field;
                $source = $usd === null ? 'toman' : 'usd';
                if ($usd === null) $usd = $rate > 0 && is_numeric($toman) ? $toman / $rate : null;
                if (!is_numeric($usd) || !is_finite((float)$usd) || $usd <= 0) $errors[] = 'invalid_usd_price';
                $periods[] = ['period' => $period, 'duration_days' => $days,
                    'price_usd' => is_numeric($usd) && is_finite((float)$usd) ? (float)$usd : null,
                    'price_toman' => is_numeric($toman) ? (int)$toman : null, 'price_source' => $source,
                    'eligible' => !$errors, 'reasons' => $errors];
            }
            $candidates[] = ['plan_id' => (int)$plan->id, 'plan_name' => $plan->name,
                'volume_gb' => (int)$plan->transfer_enable, 'cohort' => $cohort, 'periods' => $periods];
        }
        return ['candidates' => $candidates, 'exchange_rate' => $rate,
            'source_revision' => self::hash([$candidates, $rate])];
    }

    private static function evaluate(array $configuration, array $sources, string $settingsRevision): array
    {
        $classes = array_column($configuration['classifications'], 'quota_type', 'plan_id');
        $candidates = [];
        foreach ($sources['candidates'] as $candidate) {
            $candidate['quota_type'] = $classes[$candidate['plan_id']] ?? 'unknown';
            if ($candidate['quota_type'] !== 'finite_total') {
                foreach ($candidate['periods'] as &$period) {
                    $period['reasons'][] = 'not_finite_total';
                    $period['eligible'] = false;
                }
                unset($period);
            }
            $candidates[$candidate['plan_id']] = $candidate;
        }
        $errors = [];
        // A removed, unselected classification has no pricing effect. Missing
        // APPROVED references below still invalidate the entire configuration.
        $points = [];
        $cohort = null;
        foreach ($configuration['references'] as $reference) {
            $candidate = $candidates[$reference['plan_id']] ?? null;
            if (!$candidate) { $errors[] = ['code' => 'plan_missing'] + $reference; continue; }
            $period = array_column($candidate['periods'], null, 'period')[$reference['period']];
            foreach ($period['reasons'] as $reason) $errors[] = ['code' => $reason] + $reference;
            if (!$period['eligible']) continue;
            if ($cohort !== null && $cohort !== $candidate['cohort']) {
                $errors[] = ['code' => 'mixed_cohort'] + $reference;
                continue;
            }
            $cohort = $candidate['cohort'];
            $points[] = ['plan_id' => $candidate['plan_id'], 'plan_name' => $candidate['plan_name'],
                'volume_gb' => $candidate['volume_gb'], 'duration_days' => $period['duration_days'],
                'period' => $period['period'], 'price_usd' => $period['price_usd'], 'price_source' => $period['price_source']];
        }
        if ($configuration['enabled'] && !$configuration['references']) $errors[] = ['code' => 'no_references'];
        $preview = ['enabled' => false];
        if (!$errors && $points) {
            $bases = array_map(fn ($point) => $point['price_usd'] / CustomPlanService::factor($point['volume_gb'], $point['duration_days']), $points);
            sort($bases, SORT_NUMERIC);
            $middle = intdiv(count($bases), 2);
            $base = count($bases) % 2 ? $bases[$middle] : ($bases[$middle - 1] + $bases[$middle]) / 2;
            $model = ['base_usd' => $base] + self::MODEL;
            $preview = ['enabled' => true, 'model' => $model,
                'bounds' => ['volume_gb' => ['min' => 1, 'max' => 1024], 'duration_days' => ['min' => 1, 'max' => 365]],
                'currency' => 'Toman', 'exchange_rate' => $sources['exchange_rate'],
                'pricing_revision' => self::hash([$model, $points, $cohort, $sources['exchange_rate'], $settingsRevision]),
                'source_plan_id' => $points[0]['plan_id'], 'cohort' => $cohort, 'source_points' => $points];
        }
        return ['candidates' => array_values($candidates), 'validation' => ['valid' => !$errors, 'errors' => $errors], 'preview' => $preview];
    }

    private static function view(array $state, array $sources, ?array $draft = null): array
    {
        $configuration = $draft ?? $state['configuration'];
        $evaluated = self::evaluate($configuration, $sources, $state['settings_revision']);
        if (!$state['valid'] && $draft === null) {
            $evaluated['validation'] = ['valid' => false, 'errors' => [['code' => 'invalid_configuration']]];
            $evaluated['preview'] = ['enabled' => false];
        }
        return ['capable' => self::capable(), 'settings_revision' => $state['settings_revision'],
            'source_revision' => $sources['source_revision'], 'configuration' => $configuration,
            'formula' => 'P(d,t) = A * ((d + 150) / 185)^1.13 * (t / 30)^0.20',
            'rounding' => 'floor_toman_1000'] + $evaluated;
    }

    public static function fetch(): array
    {
        if (!self::capable()) return ['capable' => false, 'configuration' => self::defaults(), 'candidates' => [],
            'validation' => ['valid' => false, 'errors' => [['code' => 'schema_unavailable']]], 'preview' => ['enabled' => false]];
        return self::view(self::state(), self::sources());
    }

    public static function preview(array $configuration, string $settingsRevision, string $sourceRevision): array
    {
        if (!self::capable()) self::fail(503, 'custom_unavailable');
        $state = self::state();
        $sources = self::sources();
        if (!hash_equals($state['settings_revision'], $settingsRevision) || !hash_equals($sources['source_revision'], $sourceRevision)) self::fail(409, 'custom_settings_stale');
        return self::view($state, $sources, self::normalize($configuration));
    }

    public static function save(array $configuration, string $settingsRevision, string $sourceRevision): array
    {
        if (!self::capable()) self::fail(503, 'custom_unavailable');
        $configuration = self::normalize($configuration);
        return DB::transaction(function () use ($configuration, $settingsRevision, $sourceRevision) {
            $state = self::state(true);
            $sources = self::sources(true);
            if (!hash_equals($state['settings_revision'], $settingsRevision) || !hash_equals($sources['source_revision'], $sourceRevision)) self::fail(409, 'custom_settings_stale');
            $view = self::view($state, $sources, $configuration);
            if (!$view['validation']['valid']) self::fail(422, 'custom_settings_invalid', ['errors' => $view['validation']['errors'], 'data' => $view]);
            // A new generation makes every explicit save a new approval epoch.
            // Existing-row CAS and first-row unique insertion protect both cases.
            $value = json_encode(['version' => 1, 'generation' => (string)Str::uuid(), 'configuration' => $configuration], JSON_THROW_ON_ERROR);
            if (!$state['exists']) {
                $changed = DB::table('v2_bot_settings')->insertOrIgnore(['key' => self::KEY, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $changed = DB::table('v2_bot_settings')->where('key', self::KEY)->where('value', $state['raw'])->update(['value' => $value, 'updated_at' => now()]);
            }
            if ($changed !== 1) self::fail(409, 'custom_settings_stale');
            return self::view(self::state(), $sources);
        }, 3);
    }

    public static function catalogue(bool $lock = false): array
    {
        if (!self::capable()) return ['enabled' => false];
        $state = self::state($lock);
        if (!$state['valid'] || !$state['configuration']['enabled']) return ['enabled' => false];
        $view = self::view($state, self::sources($lock));
        return $view['validation']['valid'] ? $view['preview'] : ['enabled' => false];
    }
}

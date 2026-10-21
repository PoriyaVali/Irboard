'use strict';

// Demo tariff data exists only in this isolated browser fixture, never in the admin asset.
const crypto = require('node:crypto');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const clone = value => JSON.parse(JSON.stringify(value));
const shape = (volume, days) => ((volume + 150) / 185) ** 1.13 * (days / 30) ** 0.2;
const periods = { month_price: 30, quarter_price: 90, half_year_price: 180, year_price: 365 };

function createFixture() {
    const state = {
        settings: hash('settings-0'), source: hash('source-0'), requests: [], mode: '', hold: null, exchangeRate: 200000,
        configuration: { enabled: false, classifications: [], references: [] },
        candidates: [
            { plan_id: 1, plan_name: '۳۵ گیگ / Standard <img src=x onerror="window.pwned=1">', volume_gb: 35, quota_type: 'unknown', cohort: { group_id: 1, device_limit: 3, speed_limit: 100, reset_traffic_method: 2 }, prices: { month_price: 1.49, quarter_price: 1.70 } },
            { plan_id: 2, plan_name: '۲۰۰ گیگ / Family', volume_gb: 200, quota_type: 'unknown', cohort: { group_id: 1, device_limit: 3, speed_limit: 100, reset_traffic_method: 2 }, prices: { month_price: 3.10, quarter_price: 3.80 } },
            { plan_id: 3, plan_name: 'نامحدود / Unlimited FUP', volume_gb: 500, quota_type: 'unknown', cohort: { group_id: 1, device_limit: 3, speed_limit: 100, reset_traffic_method: 2 }, prices: { month_price: 6.20 } },
            { plan_id: 4, plan_name: 'Monthly reset quota', volume_gb: 70, quota_type: 'unknown', cohort: { group_id: 1, device_limit: 3, speed_limit: 100, reset_traffic_method: 0 }, prices: { month_price: 2 } },
            { plan_id: 5, plan_name: 'Other access group', volume_gb: 500, quota_type: 'unknown', cohort: { group_id: 2, device_limit: 2, speed_limit: 200, reset_traffic_method: 2 }, prices: { month_price: 6.20 } }
        ]
    };
    state.envelope = function (configuration = state.configuration) {
        const candidates = state.candidates.map(candidate => {
            const current = configuration.classifications.find(c => c.plan_id === candidate.plan_id);
            const quotaType = current ? current.quota_type : 'unknown';
            return { ...candidate, quota_type: quotaType, periods: Object.entries(periods).map(([period, duration]) => {
                const price = candidate.prices[period];
                const reasons = [];
                if (quotaType !== 'finite_total') reasons.push('not_finite_total');
                if (candidate.cohort.reset_traffic_method !== 2) reasons.push('resetting_quota');
                if (!(price > 0)) reasons.push('missing_price');
                return { period, duration_days: duration, price_usd: price || null, price_toman: price ? Math.floor(price * state.exchangeRate / 1000) * 1000 : null, price_source: 'usd', eligible: reasons.length === 0, reasons };
            }) };
        });
        const errors = [];
        const points = [];
        let cohort;
        for (const selected of configuration.references) {
            const candidate = candidates.find(c => c.plan_id === selected.plan_id);
            const period = candidate?.periods.find(p => p.period === selected.period);
            if (!candidate) { errors.push({ code: 'plan_missing', ...selected }); continue; }
            if (!period) { errors.push({ code: 'invalid_period', ...selected }); continue; }
            period.reasons.forEach(code => errors.push({ code, ...selected }));
            if (period.reasons.length) continue;
            if (cohort && JSON.stringify(candidate.cohort) !== JSON.stringify(cohort)) errors.push({ code: 'mixed_cohort', ...selected });
            cohort = cohort || candidate.cohort;
            points.push({ plan_id: candidate.plan_id, plan_name: candidate.plan_name, volume_gb: candidate.volume_gb, duration_days: period.duration_days, period: period.period, price_usd: period.price_usd, price_source: period.price_source });
        }
        if (configuration.enabled && !configuration.references.length) errors.push({ code: 'no_references' });
        let preview = { enabled: false };
        if (points.length && !errors.length) {
            const bases = points.map(p => p.price_usd / shape(p.volume_gb, p.duration_days)).sort((a, b) => a - b);
            const mid = Math.floor(bases.length / 2);
            const base = bases.length % 2 ? bases[mid] : (bases[mid - 1] + bases[mid]) / 2;
            preview = { enabled: true, model: { base_usd: base, offset_gb: 150, reference_gb: 185, reference_days: 30, alpha: 1.13, beta: 0.2 }, exchange_rate: state.exchangeRate, currency: 'Toman', source_points: points, cohort, pricing_revision: hash(JSON.stringify(configuration)) };
        }
        return { data: { capable: true, settings_revision: state.settings, source_revision: state.source, configuration: clone(configuration), candidates, validation: { valid: !errors.length, errors }, preview, formula: 'fixed', rounding: 'floor_toman_1000' } };
    };
    return state;
}

module.exports = { createFixture, hash };

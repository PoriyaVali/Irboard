/* Maintained addition to the prebuilt admin SPA. No prices or credentials are persisted here. */
(function () {
    'use strict';
    if (window.__doctorTailoredAdmin) return;
    window.__doctorTailoredAdmin = true;
    var mounted = null;
    var queued = false;
    var ns = 'http://www.w3.org/2000/svg';
    var messages = {
        en: {
            title: 'Tailored subscription pricing', intro: 'Approve each tariff and duration separately. Only a finite total quota with no recurring reset can be a price reference.',
            loading: 'Loading pricing settings…', reload: 'Reload saved settings', discard: 'Discard your unsaved changes and reload the saved settings?',
            enabled: 'Offer tailored subscriptions', saved: 'Saved configuration', unsaved: 'Unsaved changes — preview before saving', preview: 'Preview draft', save: 'Save approved configuration',
            reset: 'Discard changes', modelTitle: 'Pricing model and reference fit', references: 'Approved price references', none: 'No price references selected.',
            previewNeeded: 'Preview the current selections to see the model and curve. This does not create an order or change prices.',
            disabled: 'Customer purchases are disabled. You can still preview selected references.', noModel: 'A valid model is not available for these selections.',
            formula: 'Only the base A is inferred. Offset 150, reference 185 GB / 30 days and powers 1.13 / 0.20 remain fixed.',
            base: 'Inferred base A (USD)', method: 'Median of each approved reference price divided by its formula factor.', rate: 'Exchange rate (Toman per USD)',
            rounding: 'Toman prices are rounded down to the nearest 1,000, using the existing pricing convention.',
            name: 'Tariff / duration', type: 'Quota classification', unknown: 'Unclassified — excluded', finite_total: 'Finite total quota, no recurring reset', unlimited_fup: 'Unlimited / FUP — excluded',
            actual: 'Reference USD', actualToman: 'Reference Toman / source', predicted: 'Model USD', deviation: 'Deviation', access: 'Access', group: 'Group', devices: 'Devices', speed: 'Speed', unlimited: 'No limit',
            volume: 'Total volume (GB)', days: 'Duration (days)', price: 'Model price', total: 'The volume is the total for the entire duration; it does not reset monthly.',
            volumeCurve: 'Volume curve at the selected duration', timeCurve: 'Duration curve at the selected volume', usd: 'USD', toman: 'Toman',
            legend: 'Blue: model curve · Teal: selected package · Orange: approved references. Reference markers only appear when their other coordinate matches this slice.',
            draftChart: 'Draft model — not saved', savedChart: 'Saved model', period: 'days', reasonTitle: 'Excluded:', priceSource: 'Price source',
            pricesHint: 'Edit tariff amounts in the existing subscription editor above, then reload this panel. This editor chooses references; it does not overwrite tariff prices.',
            stale: 'Settings or tariff prices changed elsewhere. Your draft is retained. Reload saved settings before previewing or saving again.',
            session: 'Your admin session changed. Reload this page and sign in again.', forbidden: 'Admin access is required. No settings were loaded or changed.',
            network: 'Could not reach the server. Your draft is retained; retry explicitly.', invalid: 'The selected references are not valid. Review the reasons below.',
            malformed: 'The server returned an unsupported pricing response. No changes were saved.', capability: 'The tailored-package database capability is unavailable. Settings cannot be enabled until the schema is ready.',
            working: 'Please wait…', success: 'Configuration saved.', unavailable: 'Unavailable', previewLabel: 'Draft preview', validation: 'Validation',
            invalidInput: 'Enter whole GB (1–1024) and whole days (1–365).', not_finite_total: 'Explicit finite-total approval is required', hidden_plan: 'Tariff is hidden', capacity_limited: 'Tariff has a sales capacity limit',
            invalid_volume: 'Volume must be between 1 and 1024 GB', resetting_quota: 'Quota resets during the period', invalid_access: 'Invalid access / device / speed settings', missing_price: 'No positive sellable price',
            invalid_usd_price: 'Invalid canonical USD price', exchange_unavailable: 'Exchange rate unavailable', plan_missing: 'Selected tariff no longer exists', invalid_period: 'Unsupported duration',
            mixed_cohort: 'Selected references must have identical access group, device limit and speed limit', no_references: 'Select at least one valid tariff and duration',
            month_price: '30 days', quarter_price: '90 days', half_year_price: '180 days', year_price: '365 days',
            selected: 'Selected tariff periods', remove: 'Remove reference', schema_unavailable: 'Tailored-package database capability is unavailable'
        },
        fa: {
            title: 'قیمت‌گذاری تعرفه دست‌دوز', intro: 'هر تعرفه و دوره را جداگانه تأیید کنید. فقط حجم کلِ محدود و بدون شارژ مجدد می‌تواند مرجع قیمت باشد.',
            loading: 'در حال دریافت تنظیمات قیمت…', reload: 'دریافت دوباره تنظیمات ذخیره‌شده', discard: 'تغییرات ذخیره‌نشده کنار گذاشته شود و تنظیمات ذخیره‌شده دوباره خوانده شود؟',
            enabled: 'فروش تعرفه دست‌دوز فعال باشد', saved: 'تنظیمات ذخیره‌شده', unsaved: 'تغییرات ذخیره‌نشده — پیش از ذخیره، پیش‌نمایش بگیرید', preview: 'پیش‌نمایش تغییرات', save: 'ذخیره تنظیمات تأییدشده',
            reset: 'کنار گذاشتن تغییرات', modelTitle: 'مدل قیمت و تطبیق مراجع', references: 'مراجع قیمت تأییدشده', none: 'هیچ مرجع قیمتی انتخاب نشده است.',
            previewNeeded: 'برای دیدن مدل و منحنی، از انتخاب‌های فعلی پیش‌نمایش بگیرید. پیش‌نمایش سفارشی ایجاد نمی‌کند و قیمت‌ها را تغییر نمی‌دهد.',
            disabled: 'خرید دست‌دوز غیرفعال است. همچنان می‌توانید مراجع انتخاب‌شده را پیش‌نمایش کنید.', noModel: 'برای این انتخاب‌ها مدل معتبری در دسترس نیست.',
            formula: 'فقط ضریب پایه A محاسبه می‌شود. آفست ۱۵۰، مرجع ۱۸۵ گیگ و ۳۰ روز و توان‌های ۱٫۱۳ و ۰٫۲۰ ثابت می‌مانند.',
            base: 'ضریب پایه محاسبه‌شده A (دلار)', method: 'میانه نسبت قیمت هر مرجع تأییدشده به ضریب همان نقطه در فرمول.', rate: 'نرخ تبدیل (تومان برای هر دلار)',
            rounding: 'قیمت تومان مطابق منطق فعلی به هزار تومان پایین‌تر گرد می‌شود؛ ویرگول جداکننده هزارگان است.',
            name: 'تعرفه / دوره', type: 'نوع سهمیه', unknown: 'تعیین‌نشده — خارج از محاسبه', finite_total: 'حجم کلِ محدود، بدون شارژ مجدد', unlimited_fup: 'نامحدود / مصرف منصفانه — خارج از محاسبه',
            actual: 'قیمت مرجع (دلار)', actualToman: 'قیمت مرجع تومان / مبنا', predicted: 'قیمت مدل (دلار)', deviation: 'اختلاف', access: 'دسترسی', group: 'گروه', devices: 'دستگاه', speed: 'سرعت', unlimited: 'بدون محدودیت',
            volume: 'حجم کل (گیگابایت)', days: 'مدت (روز)', price: 'قیمت مدل', total: 'این حجم برای کل دوره است و ماهانه دوباره شارژ نمی‌شود.',
            volumeCurve: 'منحنی حجم در مدت انتخاب‌شده', timeCurve: 'منحنی مدت در حجم انتخاب‌شده', usd: 'دلار', toman: 'تومان',
            legend: 'آبی: منحنی مدل · سبز: بسته انتخابی · نارنجی: مراجع تأییدشده. نقطه مرجع فقط وقتی نمایش داده می‌شود که مختصات دیگر آن با این برش برابر باشد.',
            draftChart: 'مدل پیش‌نویس — ذخیره نشده', savedChart: 'مدل ذخیره‌شده', period: 'روز', reasonTitle: 'علت عدم انتخاب:', priceSource: 'مبنای قیمت',
            pricesHint: 'مبلغ تعرفه‌ها را در ویرایشگر اشتراک‌های همین صفحه تغییر دهید، سپس این بخش را دوباره بارگذاری کنید. این بخش مراجع را انتخاب می‌کند و مبلغ تعرفه‌ها را بازنویسی نمی‌کند.',
            stale: 'تنظیمات یا قیمت تعرفه‌ها در جای دیگری تغییر کرده است. پیش‌نویس شما حفظ شده؛ پیش از ادامه تنظیمات ذخیره‌شده را دوباره دریافت کنید.',
            session: 'نشست مدیریت تغییر کرده است. صفحه را دوباره بارگذاری کنید و وارد شوید.', forbidden: 'دسترسی مدیر لازم است. تنظیماتی دریافت یا تغییر داده نشد.',
            network: 'ارتباط با سرور برقرار نشد. پیش‌نویس حفظ شده؛ دوباره تلاش کنید.', invalid: 'مراجع انتخاب‌شده معتبر نیستند. علت‌ها را در پایین بررسی کنید.',
            malformed: 'پاسخ قیمت‌گذاری سرور معتبر نیست. تغییری ذخیره نشد.', capability: 'زیرساخت پایگاه داده تعرفه دست‌دوز آماده نیست. تا آماده شدن آن امکان فعال‌سازی وجود ندارد.',
            working: 'لطفاً صبر کنید…', success: 'تنظیمات ذخیره شد.', unavailable: 'در دسترس نیست', previewLabel: 'پیش‌نمایش تغییرات', validation: 'اعتبارسنجی',
            invalidInput: 'حجم صحیح بین ۱ تا ۱۰۲۴ گیگ و مدت صحیح بین ۱ تا ۳۶۵ روز وارد کنید.', not_finite_total: 'تأیید صریح حجم کل محدود لازم است', hidden_plan: 'تعرفه مخفی است', capacity_limited: 'تعرفه محدودیت ظرفیت فروش دارد',
            invalid_volume: 'حجم باید بین ۱ تا ۱۰۲۴ گیگ باشد', resetting_quota: 'حجم تعرفه در طول دوره دوباره شارژ می‌شود', invalid_access: 'تنظیمات گروه، دستگاه یا سرعت معتبر نیست', missing_price: 'قیمت مثبت قابل فروش وجود ندارد',
            invalid_usd_price: 'قیمت دلاری اصلی معتبر نیست', exchange_unavailable: 'نرخ تبدیل موجود نیست', plan_missing: 'تعرفه انتخاب‌شده حذف شده است', invalid_period: 'دوره پشتیبانی نمی‌شود',
            mixed_cohort: 'گروه دسترسی، تعداد دستگاه و سرعت همه مراجع باید یکسان باشد', no_references: 'حداقل یک تعرفه و دوره معتبر انتخاب کنید',
            month_price: '۳۰ روز', quarter_price: '۹۰ روز', half_year_price: '۱۸۰ روز', year_price: '۳۶۵ روز',
            selected: 'تعرفه و دوره‌های انتخاب‌شده', remove: 'حذف از مراجع', schema_unavailable: 'زیرساخت پایگاه داده تعرفه دست‌دوز آماده نیست'
        }
    };
    function locale() { return /^fa\b/i.test(localStorage.getItem('umi_locale') || 'fa') ? 'fa' : 'en'; }
    function text(s, key) { return messages[s.lang][key] || messages.en[key] || key; }
    function el(tag, cls, value) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (value !== undefined && value !== null) node.textContent = String(value);
        return node;
    }
    function add(parent, tag, cls, value) { var child = el(tag, cls, value); parent.appendChild(child); return child; }
    function svg(tag, attrs, content) {
        var node = document.createElementNS(ns, tag);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, String(attrs[key])); });
        if (content !== undefined) node.textContent = String(content);
        return node;
    }
    function button(s, label, action, kind) {
        var b = el('button', 'dtp-button' + (kind ? ' dtp-' + kind : ''), text(s, label));
        b.type = 'button'; b.addEventListener('click', action); return b;
    }
    function copy(value) { return JSON.parse(JSON.stringify(value)); }
    function canonical(configuration) {
        return JSON.stringify({ enabled: configuration.enabled === true,
            classifications: (configuration.classifications || []).map(function (x) { return { plan_id: Number(x.plan_id), quota_type: x.quota_type }; }).sort(function (a, b) { return a.plan_id - b.plan_id; }),
            references: (configuration.references || []).map(function (x) { return { plan_id: Number(x.plan_id), period: x.period }; }).sort(function (a, b) { return a.plan_id - b.plan_id || a.period.localeCompare(b.period); }) });
    }
    function dirty(s) { return canonical(s.draft) !== canonical(s.saved); }
    function auth() { return localStorage.getItem('authorization') || ''; }
    function secure() { var value = String((window.settings || {}).secure_path || ''); return /^[A-Za-z0-9_-]+$/.test(value) ? value : ''; }
    function onPlanRoute() {
        var hash = location.hash.replace(/^#/, '').split('?')[0].replace(/\/$/, '');
        if (hash) return hash === '/plan';
        var path = location.pathname.replace(/\/$/, '');
        return path === '/plan' || (!!secure() && path === '/' + secure() + '/plan');
    }
    function alive(s) { return mounted === s && !s.disposed && onPlanRoute() && auth() === s.token && locale() === s.lang; }
    function classification(s, id) {
        var entry = s.draft.classifications.find(function (x) { return Number(x.plan_id) === Number(id); });
        return entry ? entry.quota_type : 'unknown';
    }
    function chosen(s, id, period) { return s.draft.references.some(function (x) { return Number(x.plan_id) === Number(id) && x.period === period; }); }
    function reasons(s, candidate, period) {
        var result = (period.reasons || []).filter(function (code) { return code !== 'not_finite_total'; });
        if (classification(s, candidate.plan_id) !== 'finite_total') result.unshift('not_finite_total');
        return result;
    }
    function invalidate(s) { s.evaluation = null; s.previewKey = null; s.notice = ''; render(s); }
    function number(value, digits) { return Number(value).toLocaleString('en-US', { minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0 }); }
    function validEnvelope(data) {
        return data && typeof data.capable === 'boolean' && (data.capable === false || (/^[a-f0-9]{64}$/.test(data.settings_revision) && /^[a-f0-9]{64}$/.test(data.source_revision)))
            && data.configuration && typeof data.configuration.enabled === 'boolean' && Array.isArray(data.configuration.classifications)
            && Array.isArray(data.configuration.references) && Array.isArray(data.candidates) && data.validation && typeof data.validation.valid === 'boolean'
            && Array.isArray(data.validation.errors) && data.preview && typeof data.preview.enabled === 'boolean';
    }
    async function request(s, action, body) {
        if (!alive(s) || s.busy) return;
        s.busy = true; s.notice = ''; s.error = ''; render(s);
        var generation = ++s.generation;
        var controller = new AbortController(); s.controller = controller;
        var timer = setTimeout(function () { controller.abort(); }, 20000);
        try {
            var response = await fetch('/api/v1/' + secure() + '/plan/custom/' + action, {
                method: action === 'fetch' ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Authorization: s.token, Accept: 'application/json', 'Content-Type': 'application/json' },
                body: action === 'fetch' ? undefined : JSON.stringify(body)
            });
            var payload = await response.json();
            if (!alive(s) || generation !== s.generation) return;
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) { s.error = 'forbidden'; s.blocked = true; }
                else if (response.status === 409) { s.error = 'stale'; s.conflicted = true; s.previewKey = null; s.evaluation = null; }
                else if (response.status === 422 && validEnvelope(payload.data)) { s.error = 'invalid'; s.evaluation = payload.data; s.previewKey = canonical(s.draft); }
                else s.error = 'network';
                return;
            }
            if (!validEnvelope(payload.data)) { s.error = 'malformed'; return; }
            var data = payload.data;
            if (action === 'fetch' || action === 'save') {
                s.data = data; s.saved = copy(data.configuration); s.draft = copy(data.configuration);
                s.evaluation = data; s.previewKey = canonical(s.draft); s.conflicted = false; s.blocked = false;
                if (action === 'save') s.notice = 'success';
            } else {
                s.evaluation = data; s.previewKey = canonical(s.draft);
                if (!data.validation.valid) s.error = 'invalid';
            }
        } catch (error) {
            if (alive(s) && generation === s.generation) s.error = 'network';
        } finally {
            clearTimeout(timer);
            if (alive(s) && generation === s.generation) { s.busy = false; s.controller = null; render(s); }
        }
    }
    function payload(s) { return { settings_revision: s.data.settings_revision, source_revision: s.data.source_revision, configuration: copy(s.draft) }; }
    function reload(s) {
        if (s.busy || (s.data && dirty(s) && !window.confirm(text(s, 'discard')))) return;
        request(s, 'fetch');
    }
    function renderCandidates(s, root) {
        add(root, 'h3', '', text(s, 'references'));
        add(root, 'p', 'dtp-muted', text(s, 'pricesHint'));
        var wrap = add(root, 'div', 'dtp-table-wrap');
        var table = add(wrap, 'table', 'dtp-table');
        var header = add(add(table, 'thead'), 'tr');
        ['name', 'type', 'actual', 'actualToman', 'access'].forEach(function (key) { add(header, 'th', '', text(s, key)); });
        var body = add(table, 'tbody');
        s.data.candidates.forEach(function (candidate) {
            var row = add(body, 'tr', 'dtp-plan');
            var name = add(row, 'td');
            add(name, 'strong', '', candidate.plan_name);
            add(name, 'div', 'dtp-muted', '#' + candidate.plan_id + ' · ' + number(candidate.volume_gb) + ' GB');
            var selectCell = add(row, 'td');
            var select = add(selectCell, 'select', 'dtp-select');
            select.setAttribute('aria-label', text(s, 'type') + ' — ' + candidate.plan_name);
            select.dataset.classification = String(candidate.plan_id);
            ['unknown', 'finite_total', 'unlimited_fup'].forEach(function (type) { var option = add(select, 'option', '', text(s, type)); option.value = type; });
            select.value = classification(s, candidate.plan_id); select.disabled = s.busy || s.blocked;
            select.addEventListener('change', function () {
                s.draft.classifications = s.draft.classifications.filter(function (x) { return Number(x.plan_id) !== Number(candidate.plan_id); });
                s.draft.classifications.push({ plan_id: candidate.plan_id, quota_type: select.value });
                // Keep existing selections visible when a classification becomes invalid; the server explains and rejects them.
                invalidate(s);
            });
            add(row, 'td', 'dtp-muted', '—');
            add(row, 'td', 'dtp-muted', '—');
            var cohort = candidate.cohort || {};
            add(row, 'td', 'dtp-muted', text(s, 'group') + ': ' + cohort.group_id + ' · ' + text(s, 'devices') + ': ' + (cohort.device_limit == null ? text(s, 'unlimited') : cohort.device_limit) + ' · ' + text(s, 'speed') + ': ' + (cohort.speed_limit == null ? text(s, 'unlimited') : cohort.speed_limit));
            (candidate.periods || []).forEach(function (period) {
                var line = add(body, 'tr');
                var choiceCell = add(line, 'td');
                var label = add(choiceCell, 'label', 'dtp-choice');
                var checkbox = add(label, 'input'); checkbox.type = 'checkbox';
                checkbox.dataset.reference = candidate.plan_id + ':' + period.period;
                checkbox.checked = chosen(s, candidate.plan_id, period.period);
                var excluded = reasons(s, candidate, period);
                checkbox.disabled = s.busy || s.blocked || (excluded.length > 0 && !checkbox.checked);
                add(label, 'span', '', text(s, period.period));
                checkbox.addEventListener('change', function () {
                    s.draft.references = s.draft.references.filter(function (x) { return !(Number(x.plan_id) === Number(candidate.plan_id) && x.period === period.period); });
                    if (checkbox.checked) s.draft.references.push({ plan_id: candidate.plan_id, period: period.period });
                    invalidate(s);
                });
                var status = add(line, 'td', excluded.length ? 'dtp-excluded' : 'dtp-muted');
                status.textContent = excluded.length ? text(s, 'reasonTitle') + ' ' + excluded.map(function (code) { return text(s, code); }).join(' · ') : text(s, 'finite_total');
                add(line, 'td', 'dtp-numeric', period.price_usd == null ? '—' : '$' + number(period.price_usd, 4));
                add(line, 'td', 'dtp-muted', (period.price_toman == null ? '—' : number(period.price_toman) + ' ' + text(s, 'toman')) + ' · ' + text(s, 'priceSource') + ': ' + String(period.price_source || '—'));
                add(line, 'td', 'dtp-muted', '—');
            });
        });
        if (!s.data.candidates.length) add(root, 'p', 'dtp-muted', text(s, 'none'));
        if (s.draft.references.length) {
            add(root, 'h3', '', text(s, 'selected'));
            var selected = add(root, 'ul', 'dtp-selected-list');
            s.draft.references.forEach(function (reference) {
                var candidate = s.data.candidates.find(function (p) { return Number(p.plan_id) === Number(reference.plan_id); });
                var name = '#' + reference.plan_id + ' · ' + (candidate ? candidate.plan_name + ' · ' : '') + text(s, reference.period);
                var row = add(selected, 'li'); add(row, 'span', '', name);
                var remove = button(s, 'remove', function () {
                    s.draft.references = s.draft.references.filter(function (r) { return !(Number(r.plan_id) === Number(reference.plan_id) && r.period === reference.period); });
                    invalidate(s);
                });
                remove.dataset.removeReference = reference.plan_id + ':' + reference.period;
                remove.setAttribute('aria-label', text(s, 'remove') + ' — ' + name); remove.disabled = s.busy || s.blocked;
                row.appendChild(remove);
            });
        }
    }
    function modelPrice(model, volume, days) {
        return model.base_usd * Math.pow((volume + model.offset_gb) / model.reference_gb, model.alpha) * Math.pow(days / model.reference_days, model.beta);
    }
    function validModel(preview) {
        var m = preview && preview.model;
        return preview && preview.enabled === true && m && Number.isFinite(m.base_usd) && m.base_usd > 0 && m.offset_gb === 150 && m.reference_gb === 185
            && m.reference_days === 30 && m.alpha === 1.13 && m.beta === 0.2 && Number.isFinite(preview.exchange_rate) && preview.exchange_rate > 0 && Array.isArray(preview.source_points);
    }
    function chart(s, preview, mode) {
        var model = preview.model; var maxX = mode === 'volume' ? 1024 : 365;
        var coordinate = mode === 'volume' ? s.volume : s.days;
        var other = mode === 'volume' ? s.days : s.volume;
        var price = function (x) { var usd = modelPrice(model, mode === 'volume' ? x : other, mode === 'volume' ? other : x); return s.currency === 'toman' ? Math.floor(usd * preview.exchange_rate / 1000) * 1000 : usd; };
        var points = preview.source_points.filter(function (p) { return Number(mode === 'volume' ? p.duration_days : p.volume_gb) === other; });
        var values = points.map(function (p) { return s.currency === 'toman' ? Math.floor(p.price_usd * preview.exchange_rate / 1000) * 1000 : p.price_usd; });
        // A valid low-price slice can floor entirely to zero Toman. Keep axes finite without changing prices.
        var maxY = Math.max.apply(null, [s.currency === 'toman' ? 1000 : 0.01, price(maxX)].concat(values)) * 1.12;
        var width = 760, height = 300, left = 86, right = 22, top = 20, bottom = 58;
        var x = function (v) { return left + (v - 1) / (maxX - 1) * (width - left - right); };
        var y = function (v) { return height - bottom - v / maxY * (height - top - bottom); };
        var canvas = svg('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve'), class: 'dtp-chart', direction: 'ltr' });
        canvas.appendChild(svg('title', {}, text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve')));
        for (var i = 0; i <= 4; i++) {
            var value = maxY * i / 4;
            canvas.appendChild(svg('line', { x1: left, x2: width - right, y1: y(value), y2: y(value), class: 'dtp-grid' }));
            canvas.appendChild(svg('text', { x: left - 10, y: y(value) + 4, 'text-anchor': 'end' }, number(value, s.currency === 'toman' ? 0 : 2)));
        }
        [1, Math.round(maxX / 4), Math.round(maxX / 2), Math.round(maxX * 3 / 4), maxX].forEach(function (value) { canvas.appendChild(svg('text', { x: x(value), y: height - bottom + 22, 'text-anchor': 'middle' }, value)); });
        canvas.appendChild(svg('text', { x: (left + width - right) / 2, y: height - 8, 'text-anchor': 'middle' }, text(s, mode === 'volume' ? 'volume' : 'days')));
        canvas.appendChild(svg('text', { x: left, y: 12 }, text(s, s.currency)));
        var path = [];
        for (var step = 0; step <= 120; step++) { var v = 1 + (maxX - 1) * step / 120; path.push((step ? 'L' : 'M') + x(v).toFixed(2) + ',' + y(price(v)).toFixed(2)); }
        canvas.appendChild(svg('path', { d: path.join(' '), class: 'dtp-line' }));
        points.forEach(function (p, index) {
            var dot = svg('circle', { cx: x(mode === 'volume' ? p.volume_gb : p.duration_days), cy: y(values[index]), r: 5, class: 'dtp-reference' });
            dot.appendChild(svg('title', {}, p.plan_name + ': $' + number(p.price_usd, 4))); canvas.appendChild(dot);
        });
        canvas.appendChild(svg('circle', { cx: x(coordinate), cy: y(price(coordinate)), r: 6, class: 'dtp-selected' }));
        return canvas;
    }
    function normalizeInteger(value) {
        var normalized = String(value).replace(/[۰-۹٠-٩]/g, function (digit) { var code = digit.charCodeAt(0); return String(code >= 1776 ? code - 1776 : code - 1632); });
        return /^\d+$/.test(normalized) ? Number(normalized) : NaN;
    }
    function renderCharts(s, parent, preview) {
        var controls = add(parent, 'div', 'dtp-controls');
        ['volume', 'days'].forEach(function (key) {
            var label = add(controls, 'label', 'dtp-control');
            add(label, 'span', '', text(s, key));
            var input = add(label, 'input', 'dtp-number'); input.type = 'text'; input.inputMode = 'numeric'; input.value = s[key]; input.dataset.quantity = key;
            var range = add(label, 'input', 'dtp-range'); range.type = 'range'; range.min = 1; range.max = key === 'volume' ? 1024 : 365; range.step = 1; range.value = s[key]; range.setAttribute('aria-label', text(s, key)); range.dataset.slider = key;
            input.addEventListener('change', function () {
                var value = normalizeInteger(input.value); var max = key === 'volume' ? 1024 : 365;
                if (!Number.isInteger(value) || value < 1 || value > max) { input.setCustomValidity(text(s, 'invalidInput')); input.reportValidity(); return; }
                input.setCustomValidity(''); s[key] = value; render(s);
            });
            range.addEventListener('input', function () {
                s[key] = Number(range.value); input.value = s[key]; input.setCustomValidity(''); updateCurve(s, parent, preview);
            });
        });
        var currencyLabel = add(controls, 'label', 'dtp-control'); add(currencyLabel, 'span', '', text(s, 'price'));
        var currency = add(currencyLabel, 'select', 'dtp-select'); currency.dataset.currency = 'true';
        ['usd', 'toman'].forEach(function (code) { var option = add(currency, 'option', '', text(s, code)); option.value = code; });
        currency.value = s.currency; currency.addEventListener('change', function () { s.currency = currency.value; updateCurve(s, parent, preview); });
        add(parent, 'div', 'dtp-price'); add(parent, 'div', 'dtp-curves');
        add(parent, 'p', 'dtp-muted', text(s, 'total')); add(parent, 'p', 'dtp-muted', text(s, 'legend'));
        updateCurve(s, parent, preview);
    }
    function updateCurve(s, parent, preview) {
        var usd = modelPrice(preview.model, s.volume, s.days); var toman = Math.floor(usd * preview.exchange_rate / 1000) * 1000;
        var priceLabel = parent.querySelector('.dtp-price'); priceLabel.replaceChildren();
        [s.volume + ' GB', s.days + ' ' + text(s, 'period'), '$' + number(usd, 4), number(toman) + ' ' + text(s, 'toman')].forEach(function (part, index) {
            if (index) priceLabel.appendChild(document.createTextNode(index === 2 ? ' → ' : ' · '));
            var isolated = add(priceLabel, 'bdi', '', part); isolated.dir = 'auto';
        });
        var curves = parent.querySelector('.dtp-curves'); curves.replaceChildren();
        ['volume', 'days'].forEach(function (mode) { var figure = add(curves, 'figure'); add(figure, 'figcaption', '', text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve')); figure.appendChild(chart(s, preview, mode)); });
    }
    function renderModel(s, parent) {
        var section = add(parent, 'section', 'dtp-model'); add(section, 'h3', '', text(s, 'modelTitle'));
        if (!s.evaluation || s.previewKey !== canonical(s.draft)) { add(section, 'p', 'dtp-muted', text(s, 'previewNeeded')); return; }
        var evaluation = s.evaluation;
        if (evaluation.validation.errors.length) {
            var errors = add(section, 'ul', 'dtp-errors');
            evaluation.validation.errors.forEach(function (error) { add(errors, 'li', '', (error.plan_id ? '#' + error.plan_id + ' ' : '') + (error.period ? text(s, error.period) + ': ' : '') + text(s, error.code)); });
        }
        if (!validModel(evaluation.preview) || !evaluation.validation.valid) { add(section, 'p', 'dtp-muted', text(s, 'noModel')); return; }
        var preview = evaluation.preview;
        add(section, 'p', 'dtp-model-state', text(s, dirty(s) ? 'draftChart' : 'savedChart'));
        var formula = add(section, 'code', 'dtp-formula', 'P(d,t) = A × ((d + 150) / 185)^1.13 × (t / 30)^0.20'); formula.dir = 'ltr';
        add(section, 'p', 'dtp-muted', text(s, 'formula'));
        var stats = add(section, 'dl', 'dtp-stats');
        add(stats, 'dt', '', text(s, 'base')); add(stats, 'dd', 'dtp-numeric', number(preview.model.base_usd, 8));
        add(stats, 'dt', '', text(s, 'rate')); add(stats, 'dd', 'dtp-numeric', number(preview.exchange_rate));
        add(section, 'p', 'dtp-muted', text(s, 'method')); add(section, 'p', 'dtp-muted', text(s, 'rounding'));
        renderCharts(s, section, preview);
        var table = add(add(section, 'div', 'dtp-table-wrap'), 'table', 'dtp-table dtp-fit');
        var header = add(add(table, 'thead'), 'tr'); ['name', 'actual', 'predicted', 'deviation'].forEach(function (key) { add(header, 'th', '', text(s, key)); });
        var body = add(table, 'tbody');
        preview.source_points.forEach(function (point) {
            var model = modelPrice(preview.model, point.volume_gb, point.duration_days);
            var row = add(body, 'tr');
            add(row, 'td', '', point.plan_name + ' · ' + point.volume_gb + ' GB / ' + point.duration_days + ' ' + text(s, 'period'));
            add(row, 'td', 'dtp-numeric', '$' + number(point.price_usd, 4)); add(row, 'td', 'dtp-numeric', '$' + number(model, 4));
            add(row, 'td', 'dtp-numeric', ((model / point.price_usd - 1) * 100).toFixed(2) + '%');
        });
    }
    function render(s) {
        if (!alive(s)) return;
        var root = s.root; root.replaceChildren(); root.dir = s.lang === 'fa' ? 'rtl' : 'ltr'; root.lang = s.lang;
        add(root, 'h2', '', text(s, 'title')); add(root, 'p', 'dtp-muted', text(s, 'intro'));
        if (s.error) { var error = add(root, 'p', 'dtp-alert dtp-error', text(s, s.error)); error.setAttribute('role', 'alert'); }
        if (s.notice) { var notice = add(root, 'p', 'dtp-alert dtp-success', text(s, s.notice)); notice.setAttribute('role', 'status'); }
        var toolbar = add(root, 'div', 'dtp-toolbar');
        var reloadButton = button(s, 'reload', function () { reload(s); }); reloadButton.disabled = s.busy; toolbar.appendChild(reloadButton);
        if (!s.data) { add(root, 'p', 'dtp-muted', text(s, s.busy ? 'loading' : 'unavailable')); return; }
        add(toolbar, 'span', dirty(s) ? 'dtp-dirty' : 'dtp-muted', text(s, dirty(s) ? 'unsaved' : 'saved'));
        if (!s.data.capable) add(root, 'p', 'dtp-alert dtp-error', text(s, 'capability'));
        var enabled = add(root, 'label', 'dtp-choice dtp-enable'); var toggle = add(enabled, 'input'); toggle.type = 'checkbox'; toggle.checked = s.draft.enabled;
        toggle.dataset.enabled = 'true'; toggle.disabled = s.busy || s.blocked || !s.data.capable;
        add(enabled, 'span', '', text(s, 'enabled')); toggle.addEventListener('change', function () { s.draft.enabled = toggle.checked; invalidate(s); });
        if (!s.draft.enabled) add(root, 'p', 'dtp-muted', text(s, 'disabled'));
        renderCandidates(s, root);
        var actions = add(root, 'div', 'dtp-actions');
        var previewButton = button(s, 'preview', function () { request(s, 'preview', payload(s)); }); previewButton.dataset.action = 'preview';
        previewButton.disabled = s.busy || s.blocked || s.conflicted || !s.data.capable; actions.appendChild(previewButton);
        var saveButton = button(s, 'save', function () {
            if (!s.data.capable || !s.evaluation || s.previewKey !== canonical(s.draft) || !s.evaluation.validation.valid || s.conflicted || s.blocked || !dirty(s)) return;
            request(s, 'save', payload(s));
        }, 'primary'); saveButton.dataset.action = 'save';
        saveButton.disabled = s.busy || s.blocked || s.conflicted || !s.data.capable || !dirty(s) || !s.evaluation || !s.evaluation.validation.valid || s.previewKey !== canonical(s.draft);
        actions.appendChild(saveButton);
        var resetButton = button(s, 'reset', function () { if (!window.confirm(text(s, 'discard'))) return; s.draft = copy(s.saved); s.evaluation = s.data; s.previewKey = canonical(s.draft); s.error = s.conflicted ? 'stale' : ''; s.notice = ''; render(s); });
        resetButton.disabled = s.busy || s.blocked || !dirty(s); actions.appendChild(resetButton);
        if (s.busy) add(actions, 'span', 'dtp-muted', text(s, 'working'));
        renderModel(s, root);
    }
    function dispose(s) {
        s.disposed = true; s.generation++; if (s.controller) s.controller.abort(); s.root.remove(); s.entry.remove();
        if (mounted === s) mounted = null;
    }
    function scan() {
        queued = false;
        if (mounted && (!onPlanRoute() || auth() !== mounted.token || locale() !== mounted.lang)) dispose(mounted);
        if (!onPlanRoute() || !secure() || !auth()) return;
        var parent = document.querySelector('.ant-layout-content') || document.querySelector('main') || document.getElementById('root');
        if (!parent || (parent.id === 'root' && !parent.firstElementChild)) return;
        if (mounted) {
            if (!mounted.root.isConnected || mounted.root.parentElement !== parent) parent.appendChild(mounted.root);
            if (!mounted.entry.isConnected || mounted.entry.parentElement !== parent) parent.prepend(mounted.entry);
            return;
        }
        var root = el('section', 'dtp-panel'); root.id = 'doctor-tailored-pricing'; root.tabIndex = -1; root.setAttribute('aria-label', text({ lang: locale() }, 'title'));
        var entry = el('div', 'dtp-entry'); entry.dir = locale() === 'fa' ? 'rtl' : 'ltr';
        var jump = button({ lang: locale() }, 'title', function () { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); root.focus({ preventScroll: true }); });
        jump.dataset.tailoredJump = 'true'; entry.appendChild(jump);
        var s = { root: root, token: auth(), lang: locale(), generation: 0, disposed: false, busy: false, blocked: false, conflicted: false,
            entry: entry, data: null, saved: null, draft: null, evaluation: null, previewKey: null, error: '', notice: '', volume: 35, days: 30, currency: 'usd' };
        mounted = s; parent.prepend(entry); parent.appendChild(root); request(s, 'fetch');
    }
    function schedule() { if (!queued) { queued = true; queueMicrotask(scan); } }
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    ['hashchange', 'popstate', 'storage', 'visibilitychange'].forEach(function (event) { window.addEventListener(event, schedule); });
    // Also catches same-tab authorization changes and history-based SPA navigation.
    setInterval(schedule, 500);
    schedule();
}());

/* Native Umi route module. The existing React/Ant Design runtime owns all
 * controls, draft state, charts and request cleanup. No DOM overlay is mounted. */
var React = n.n(n("q1tI")).a;
var Button = (n("+L6B"), n("2/Rp").a);
var Input = (n("5NDa"), n("5rEg").a);
var Select = (n("OaEy"), n("2fM7").a);
var Switch = (n("BoS7"), n("Sdc0").a);
var Table = (n("g9YV"), n("wCAj").a);
var Layout = n("Bl7J").a;
var h = React.createElement;
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
            pricesHint: 'Edit tariff amounts in Subscription management, then return here and reload. This editor chooses references; it does not overwrite tariff prices.',
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
            pricesHint: 'مبلغ تعرفه‌ها را در تب مدیریت اشتراک‌ها تغییر دهید، سپس به این تب برگردید و تنظیمات را دوباره دریافت کنید. این بخش مراجع را انتخاب می‌کند و مبلغ تعرفه‌ها را بازنویسی نمی‌کند.',
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
function copy(value) { return JSON.parse(JSON.stringify(value)); }
function auth() { return localStorage.getItem('authorization') || ''; }
function scope() { return location.pathname + location.hash; }
function secure() { var value = String((window.settings || {}).secure_path || ''); return /^[A-Za-z0-9_-]+$/.test(value) ? value : ''; }
function onTailoredRoute() {
    var hash = location.hash.replace(/^#/, '').split('?')[0].replace(/\/$/, '');
    if (hash) return hash === '/tailored-pricing';
    var path = location.pathname.replace(/\/$/, '');
    return path === '/tailored-pricing' || (!!secure() && path === '/' + secure() + '/tailored-pricing');
}
function canonical(configuration) {
    return JSON.stringify({enabled: configuration.enabled === true,
        classifications: configuration.classifications.map(x => ({plan_id: Number(x.plan_id), quota_type: x.quota_type})).sort((a, b) => a.plan_id - b.plan_id),
        references: configuration.references.map(x => ({plan_id: Number(x.plan_id), period: x.period})).sort((a, b) => a.plan_id - b.plan_id || a.period.localeCompare(b.period))});
}
function dirty(s) { return !!s.data && canonical(s.draft) !== canonical(s.saved); }
function classification(s, id) { var item = s.draft.classifications.find(x => Number(x.plan_id) === Number(id)); return item ? item.quota_type : 'unknown'; }
function chosen(s, id, period) { return s.draft.references.some(x => Number(x.plan_id) === Number(id) && x.period === period); }
function reasons(s, candidate, period) {
    var result = (period.reasons || []).filter(code => code !== 'not_finite_total');
    if (classification(s, candidate.plan_id) !== 'finite_total') result.unshift('not_finite_total');
    return result;
}
function number(value, digits) { return Number(value).toLocaleString('en-US', {minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0}); }
function validEnvelope(data) {
    return data && typeof data.capable === 'boolean' && (data.capable === false || (/^[a-f0-9]{64}$/.test(data.settings_revision) && /^[a-f0-9]{64}$/.test(data.source_revision)))
        && data.configuration && typeof data.configuration.enabled === 'boolean' && Array.isArray(data.configuration.classifications)
        && Array.isArray(data.configuration.references) && Array.isArray(data.candidates) && data.validation && typeof data.validation.valid === 'boolean'
        && Array.isArray(data.validation.errors) && data.preview && typeof data.preview.enabled === 'boolean';
}
function modelPrice(model, volume, days) { return model.base_usd * Math.pow((volume + model.offset_gb) / model.reference_gb, model.alpha) * Math.pow(days / model.reference_days, model.beta); }
function validModel(preview) {
    var m = preview && preview.model;
    return preview && preview.enabled === true && m && Number.isFinite(m.base_usd) && m.base_usd > 0 && m.offset_gb === 150 && m.reference_gb === 185
        && m.reference_days === 30 && m.alpha === 1.13 && m.beta === 0.2 && Number.isFinite(preview.exchange_rate) && preview.exchange_rate > 0 && Array.isArray(preview.source_points);
}
function normalizeInteger(value) {
    var normalized = String(value).replace(/[۰-۹٠-٩]/g, digit => { var code = digit.charCodeAt(0); return String(code >= 1776 ? code - 1776 : code - 1632); });
    return /^\d+$/.test(normalized) ? Number(normalized) : NaN;
}
function initialState() {
    return {lang: locale(), busy: false, blocked: false, conflicted: false, data: null, saved: null, draft: null,
        evaluation: null, previewKey: null, error: '', notice: '', volume: 35, days: 30, currency: 'usd', quantities: {volume: '35', days: '30'}, quantityErrors: {}};
}
function chart(s, preview, mode) {
    var model = preview.model, maxX = mode === 'volume' ? 1024 : 365;
    var coordinate = mode === 'volume' ? s.volume : s.days, other = mode === 'volume' ? s.days : s.volume;
    var price = x => { var usd = modelPrice(model, mode === 'volume' ? x : other, mode === 'volume' ? other : x); return s.currency === 'toman' ? Math.floor(usd * preview.exchange_rate / 1000) * 1000 : usd; };
    var points = preview.source_points.filter(p => Number(mode === 'volume' ? p.duration_days : p.volume_gb) === other);
    var values = points.map(p => s.currency === 'toman' ? Math.floor(p.price_usd * preview.exchange_rate / 1000) * 1000 : p.price_usd);
    // Keep axes finite when a valid low-price slice rounds entirely to zero Toman.
    var maxY = Math.max.apply(null, [s.currency === 'toman' ? 1000 : 0.01, price(maxX)].concat(values)) * 1.12;
    var width = 760, height = 300, left = 86, right = 22, top = 20, bottom = 58;
    var x = v => left + (v - 1) / (maxX - 1) * (width - left - right), y = v => height - bottom - v / maxY * (height - top - bottom);
    var children = [h('title', {key: 'title'}, text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve'))];
    for (var i = 0; i <= 4; i++) {
        var value = maxY * i / 4;
        children.push(h('line', {key: 'grid-' + i, x1: left, x2: width - right, y1: y(value), y2: y(value), className: 'dtp-grid'}),
            h('text', {key: 'y-' + i, x: left - 10, y: y(value) + 4, textAnchor: 'end'}, number(value, s.currency === 'toman' ? 0 : 2)));
    }
    [1, Math.round(maxX / 4), Math.round(maxX / 2), Math.round(maxX * 3 / 4), maxX].forEach(value => children.push(h('text', {key: 'x-' + value, x: x(value), y: height - bottom + 22, textAnchor: 'middle'}, value)));
    children.push(h('text', {key: 'axis-x', x: (left + width - right) / 2, y: height - 8, textAnchor: 'middle'}, text(s, mode === 'volume' ? 'volume' : 'days')),
        h('text', {key: 'axis-y', x: left, y: 12}, text(s, s.currency)));
    var path = [];
    for (var step = 0; step <= 120; step++) { var v = 1 + (maxX - 1) * step / 120; path.push((step ? 'L' : 'M') + x(v).toFixed(2) + ',' + y(price(v)).toFixed(2)); }
    children.push(h('path', {key: 'curve', d: path.join(' '), className: 'dtp-line'}));
    points.forEach((p, index) => children.push(h('circle', {key: 'reference-' + p.plan_id + '-' + p.period, cx: x(mode === 'volume' ? p.volume_gb : p.duration_days), cy: y(values[index]), r: 5, className: 'dtp-reference'}, h('title', null, p.plan_name + ': $' + number(p.price_usd, 4)))));
    children.push(h('circle', {key: 'selected', cx: x(coordinate), cy: y(price(coordinate)), r: 6, className: 'dtp-selected'}));
    return h('svg', {viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve'), className: 'dtp-chart', direction: 'ltr'}, children);
}
class TailoredPricingPage extends React.Component {
    constructor(props) { super(props); this.state = initialState(); this.session = null; this.generation = 0; this.mounted = false; this.working = false; }
    componentDidMount() {
        this.mounted = true;
        this.checkSession = () => this.ensureSession();
        window.addEventListener('storage', this.checkSession);
        window.addEventListener('visibilitychange', this.checkSession);
        // localStorage has no same-tab storage event. This guard only watches the
        // session; Umi mounts/unmounts the route and React owns the complete view.
        this.sessionTimer = setInterval(this.checkSession, 500);
        this.ensureSession();
    }
    componentWillUnmount() {
        this.mounted = false; this.cancel(); clearInterval(this.sessionTimer);
        window.removeEventListener('storage', this.checkSession); window.removeEventListener('visibilitychange', this.checkSession);
    }
    cancel() { this.generation++; if (this.controller) this.controller.abort(); this.controller = null; this.working = false; }
    current(session) { return this.mounted && onTailoredRoute() && this.session === session && session.token === auth() && session.lang === locale() && session.scope === scope() && session.secure === secure(); }
    ensureSession() {
        if (!this.mounted) return false;
        if (!onTailoredRoute()) { if (this.session) { this.cancel(); this.session = null; this.setState(initialState()); } return false; }
        if (this.session && this.current(this.session)) return !!this.session.token;
        this.cancel();
        var session = {token: auth(), lang: locale(), scope: scope(), secure: secure()}; this.session = session;
        this.setState(initialState(), () => { if (this.current(session) && session.token && session.secure) this.request('fetch'); });
        return false;
    }
    async request(action) {
        if (!this.ensureSession() || this.working) return;
        var s = this.state, session = this.session;
        if (action !== 'fetch' && (!s.data || !s.data.capable || s.blocked || s.conflicted)) return;
        if (action === 'save' && (!dirty(s) || !s.evaluation || !s.evaluation.validation.valid || s.previewKey !== canonical(s.draft))) return;
        var body = action === 'fetch' ? undefined : {settings_revision: s.data.settings_revision, source_revision: s.data.source_revision, configuration: copy(s.draft)};
        this.working = true; this.setState({busy: true, notice: '', error: ''});
        var generation = ++this.generation, controller = new AbortController(); this.controller = controller;
        var timer = setTimeout(() => controller.abort(), 20000);
        try {
            var response = await fetch('/api/v1/' + session.secure + '/plan/custom/' + action, {
                method: action === 'fetch' ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {Authorization: session.token, Accept: 'application/json', 'Content-Type': 'application/json'}, body: body === undefined ? undefined : JSON.stringify(body)
            });
            var payload = await response.json();
            if (!this.current(session) || generation !== this.generation) return;
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) this.setState({error: 'forbidden', blocked: true});
                else if (response.status === 409) this.setState({error: 'stale', conflicted: true, previewKey: null, evaluation: null});
                else if (response.status === 422 && validEnvelope(payload.data)) this.setState({error: 'invalid', evaluation: payload.data, previewKey: canonical(s.draft)});
                else this.setState({error: 'network'});
                return;
            }
            if (!validEnvelope(payload.data)) { this.setState({error: 'malformed'}); return; }
            var data = payload.data;
            if (action === 'fetch' || action === 'save') this.setState({data: data, saved: copy(data.configuration), draft: copy(data.configuration), evaluation: data,
                previewKey: canonical(data.configuration), conflicted: false, blocked: false, notice: action === 'save' ? 'success' : ''});
            else this.setState({evaluation: data, previewKey: canonical(s.draft), error: data.validation.valid ? '' : 'invalid'});
        } catch (_) { if (this.current(session) && generation === this.generation) this.setState({error: 'network'}); }
        finally {
            clearTimeout(timer);
            if (this.current(session) && generation === this.generation) { this.working = false; this.controller = null; this.setState({busy: false}); }
        }
    }
    changeDraft(update) {
        if (!this.ensureSession() || this.working || this.state.blocked || !this.state.data.capable) return;
        this.setState(s => ({draft: update(copy(s.draft)), evaluation: null, previewKey: null, notice: '', error: s.conflicted ? 'stale' : ''}));
    }
    reload() { if (!this.ensureSession() || this.working || (dirty(this.state) && !window.confirm(text(this.state, 'discard')))) return; this.request('fetch'); }
    reset() {
        if (!this.ensureSession() || this.working || !window.confirm(text(this.state, 'discard'))) return;
        this.setState(s => ({draft: copy(s.saved), evaluation: s.conflicted ? null : s.data, previewKey: s.conflicted ? null : canonical(s.saved), error: s.conflicted ? 'stale' : '', notice: ''}));
    }
    quantity(key, raw) {
        var value = normalizeInteger(raw), valid = Number.isInteger(value) && value >= 1 && value <= (key === 'volume' ? 1024 : 365);
        this.setState(s => Object.assign({quantities: Object.assign({}, s.quantities, {[key]: raw}), quantityErrors: Object.assign({}, s.quantityErrors, {[key]: !valid})}, valid ? {[key]: value} : {}));
    }
    help(key, value) { return h('small', {id: 'tailored-help-' + key, className: 'form-text text-muted', 'data-tailored-help': key}, value); }
    candidates() {
        var s = this.state, locked = s.busy || s.blocked || !s.data.capable, rows = [];
        s.data.candidates.forEach(candidate => {
            rows.push({key: 'plan-' + candidate.plan_id, candidate: candidate});
            (candidate.periods || []).forEach(period => rows.push({key: candidate.plan_id + ':' + period.period, candidate: candidate, period: period}));
        });
        var columns = [
            {title: text(s, 'name'), key: 'name', width: 230, render: (_, row) => {
                if (!row.period) return h(React.Fragment, null, h('strong', null, row.candidate.plan_name), h('div', {className: 'text-muted'}, '#' + row.candidate.plan_id + ' · ' + number(row.candidate.volume_gb) + ' GB'));
                var id = 'tailored-reference-' + row.key, checked = chosen(s, row.candidate.plan_id, row.period.period), excluded = reasons(s, row.candidate, row.period);
                return h('div', {className: 'dtp-choice'}, h(Switch, {id: id, size: 'small', checked: checked, disabled: locked || (excluded.length > 0 && !checked),
                    'data-reference': row.key, 'aria-label': text(s, row.period.period) + ' — ' + row.candidate.plan_name, 'aria-describedby': 'tailored-reason-' + row.key,
                    onChange: checked => this.changeDraft(draft => { draft.references = draft.references.filter(x => !(Number(x.plan_id) === Number(row.candidate.plan_id) && x.period === row.period.period)); if (checked) draft.references.push({plan_id: row.candidate.plan_id, period: row.period.period}); return draft; })}), h('label', {htmlFor: id}, text(s, row.period.period)));
            }},
            {title: text(s, 'type'), key: 'classification', width: 310, render: (_, row) => {
                if (row.period) { var excluded = reasons(s, row.candidate, row.period); return h('span', {id: 'tailored-reason-' + row.key, className: excluded.length ? 'dtp-excluded' : 'text-muted'}, excluded.length ? text(s, 'reasonTitle') + ' ' + excluded.map(code => text(s, code)).join(' · ') : text(s, 'finite_total')); }
                return h(Select, {value: classification(s, row.candidate.plan_id), disabled: locked, style: {width: '100%'}, 'data-classification': row.candidate.plan_id,
                    'aria-label': text(s, 'type') + ' — ' + row.candidate.plan_name, 'aria-describedby': 'tailored-help-classification',
                    onChange: value => this.changeDraft(draft => { draft.classifications = draft.classifications.filter(x => Number(x.plan_id) !== Number(row.candidate.plan_id)); draft.classifications.push({plan_id: row.candidate.plan_id, quota_type: value}); return draft; })},
                    ['unknown', 'finite_total', 'unlimited_fup'].map(value => h(Select.Option, {key: value, value: value, 'data-tailored-option': value}, text(s, value))));
            }},
            {title: text(s, 'actual'), key: 'usd', width: 145, render: (_, row) => h('span', {className: 'dtp-numeric'}, !row.period || row.period.price_usd == null ? '—' : '$' + number(row.period.price_usd, 4))},
            {title: text(s, 'actualToman'), key: 'toman', width: 210, render: (_, row) => !row.period ? '—' : h('span', null, h('bdi', null, row.period.price_toman == null ? '—' : number(row.period.price_toman) + ' ' + text(s, 'toman')), ' · ' + text(s, 'priceSource') + ': ' + String(row.period.price_source || '—'))},
            {title: text(s, 'access'), key: 'access', width: 200, render: (_, row) => { var cohort = row.candidate.cohort || {}; return row.period ? '—' : text(s, 'group') + ': ' + cohort.group_id + ' · ' + text(s, 'devices') + ': ' + (cohort.device_limit == null ? text(s, 'unlimited') : cohort.device_limit) + ' · ' + text(s, 'speed') + ': ' + (cohort.speed_limit == null ? text(s, 'unlimited') : cohort.speed_limit + ' Mbps'); }}
        ];
        return h('section', {'aria-labelledby': 'tailored-references-title'}, h('h3', {id: 'tailored-references-title', className: 'block-title'}, text(s, 'references')),
            this.help('classification', text(s, 'intro')), h('p', {className: 'text-muted'}, text(s, 'pricesHint')),
            h(Table, {dataSource: rows, columns: columns, pagination: false, rowKey: 'key', scroll: {x: 1095}, rowClassName: row => row.period ? '' : 'dtp-plan'}),
            !rows.length && h('p', {className: 'text-muted'}, text(s, 'none')),
            !!s.draft.references.length && h(React.Fragment, null, h('h3', {className: 'block-title dtp-section-title'}, text(s, 'selected')),
                h('ul', {className: 'dtp-selected-list'}, s.draft.references.map(reference => {
                    var candidate = s.data.candidates.find(p => Number(p.plan_id) === Number(reference.plan_id)), key = reference.plan_id + ':' + reference.period;
                    var name = '#' + reference.plan_id + ' · ' + (candidate ? candidate.plan_name + ' · ' : '') + text(s, reference.period);
                    return h('li', {key: key}, h('span', null, name), h(Button, {size: 'small', disabled: locked, 'data-remove-reference': key, 'aria-label': text(s, 'remove') + ' — ' + name,
                        onClick: () => this.changeDraft(draft => {draft.references = draft.references.filter(r => !(Number(r.plan_id) === Number(reference.plan_id) && r.period === reference.period)); return draft;})}, text(s, 'remove')));
                }))));
    }
    charts(preview) {
        var s = this.state, usd = modelPrice(preview.model, s.volume, s.days), toman = Math.floor(usd * preview.exchange_rate / 1000) * 1000;
        return h(React.Fragment, null,
            h('div', {className: 'row dtp-controls'}, ['volume', 'days'].map(key => h('div', {key: key, className: 'col-md-4 form-group'},
                h('label', {htmlFor: 'tailored-' + key}, text(s, key)), h(Input, {id: 'tailored-' + key, inputMode: 'numeric', value: s.quantities[key], 'data-quantity': key,
                    'aria-describedby': 'tailored-help-' + key, 'aria-invalid': !!s.quantityErrors[key], onChange: e => this.quantity(key, e.target.value), dir: 'ltr'}),
                h('input', {type: 'range', min: 1, max: key === 'volume' ? 1024 : 365, step: 1, value: s[key], className: 'dtp-range', 'data-slider': key, 'aria-label': text(s, key), 'aria-describedby': 'tailored-help-' + key, onChange: e => this.quantity(key, e.target.value)}),
                this.help(key, text(s, key === 'volume' ? 'total' : 'invalidInput')), s.quantityErrors[key] && h('div', {role: 'alert', className: 'text-danger'}, text(s, 'invalidInput')))),
                h('div', {className: 'col-md-4 form-group'}, h('label', {htmlFor: 'tailored-currency'}, text(s, 'price')), h(Select, {id: 'tailored-currency', value: s.currency, style: {width: '100%'}, 'data-currency': true, 'aria-describedby': 'tailored-help-currency', onChange: value => this.setState({currency: value})}, ['usd', 'toman'].map(value => h(Select.Option, {key: value, value: value, 'data-tailored-option': value}, text(s, value)))), this.help('currency', text(s, 'rounding')))),
            h('div', {className: 'dtp-price', role: 'status'}, [s.volume + ' GB', s.days + ' ' + text(s, 'period'), '$' + number(usd, 4), number(toman) + ' ' + text(s, 'toman')].map((part, index) => h(React.Fragment, {key: index}, index ? (index === 2 ? ' → ' : ' · ') : null, h('bdi', {dir: 'auto'}, part)))),
            h('div', {className: 'dtp-curves'}, ['volume', 'days'].map(mode => h('figure', {key: mode}, h('figcaption', null, text(s, mode === 'volume' ? 'volumeCurve' : 'timeCurve')), chart(s, preview, mode)))),
            h('p', {className: 'text-muted'}, text(s, 'legend')));
    }
    model() {
        var s = this.state, evaluation = s.evaluation, current = evaluation && s.previewKey === canonical(s.draft);
        var valid = current && evaluation.validation.valid && validModel(evaluation.preview);
        return h('section', {className: 'dtp-model', 'aria-labelledby': 'tailored-model-title'}, h('h3', {id: 'tailored-model-title', className: 'block-title'}, text(s, 'modelTitle')),
            !current && h('p', {className: 'text-muted'}, text(s, 'previewNeeded')),
            current && !!evaluation.validation.errors.length && h('ul', {className: 'dtp-errors', role: 'alert'}, evaluation.validation.errors.map((error, index) => h('li', {key: index}, (error.plan_id ? '#' + error.plan_id + ' ' : '') + (error.period ? text(s, error.period) + ': ' : '') + text(s, error.code)))),
            current && !valid && h('p', {className: 'text-muted'}, text(s, 'noModel')),
            valid && h(React.Fragment, null, h('p', {className: 'dtp-model-state text-muted'}, text(s, dirty(s) ? 'draftChart' : 'savedChart')),
                h('code', {className: 'dtp-formula', dir: 'ltr'}, 'P(d,t) = A × ((d + 150) / 185)^1.13 × (t / 30)^0.20'), h('p', {className: 'text-muted'}, text(s, 'formula')),
                h('dl', {className: 'dtp-stats'}, h('dt', null, text(s, 'base')), h('dd', {className: 'dtp-numeric'}, number(evaluation.preview.model.base_usd, 8)), h('dt', null, text(s, 'rate')), h('dd', {className: 'dtp-numeric'}, number(evaluation.preview.exchange_rate))),
                h('p', {className: 'text-muted'}, text(s, 'method')), this.charts(evaluation.preview),
                h('div', {className: 'dtp-fit'}, h(Table, {pagination: false, scroll: {x: 760}, rowKey: point => point.plan_id + ':' + point.period, dataSource: evaluation.preview.source_points,
                    columns: [
                        {title: text(s, 'name'), key: 'name', render: (_, point) => point.plan_name + ' · ' + point.volume_gb + ' GB / ' + point.duration_days + ' ' + text(s, 'period')},
                        {title: text(s, 'actual'), key: 'actual', render: (_, point) => h('span', {className: 'dtp-numeric'}, '$' + number(point.price_usd, 4))},
                        {title: text(s, 'predicted'), key: 'predicted', render: (_, point) => h('span', {className: 'dtp-numeric'}, '$' + number(modelPrice(evaluation.preview.model, point.volume_gb, point.duration_days), 4))},
                        {title: text(s, 'deviation'), key: 'deviation', render: (_, point) => h('span', {className: 'dtp-numeric'}, ((modelPrice(evaluation.preview.model, point.volume_gb, point.duration_days) / point.price_usd - 1) * 100).toFixed(2) + '%')}
                    ]}))));
    }
    render() {
        var s = this.state, present = onTailoredRoute() && this.session && this.session.token && this.session.secure;
        var locked = s.busy || s.blocked || s.conflicted || !s.data || !s.data.capable;
        return h(Layout, Object.assign({}, this.props, {title: text(s, 'title')}), present && h('div', {id: 'doctor-tailored-pricing', className: 'block block-rounded dtp-panel', dir: s.lang === 'fa' ? 'rtl' : 'ltr', lang: s.lang, 'data-tailored-native': true},
            h('div', {className: 'block-header block-header-default'}, h('h2', {className: 'block-title'}, text(s, 'title'))),
            h('div', {className: 'block-content block-content-full'}, h('p', {className: 'text-muted'}, text(s, 'intro')),
                s.error && h('div', {className: 'alert alert-danger dtp-error', role: 'alert'}, text(s, s.error)),
                s.notice && h('div', {className: 'alert alert-success dtp-success', role: 'status'}, text(s, s.notice)),
                h('div', {className: 'dtp-toolbar'}, h(Button, {onClick: () => this.reload(), disabled: s.busy, loading: s.busy}, text(s, 'reload')), s.data && h('span', {className: dirty(s) ? 'dtp-dirty' : 'text-muted'}, text(s, dirty(s) ? 'unsaved' : 'saved'))),
                !s.data ? h('p', {className: 'text-muted'}, text(s, s.busy ? 'loading' : 'unavailable')) : h(React.Fragment, null,
                    !s.data.capable && h('div', {className: 'alert alert-warning', role: 'alert'}, text(s, 'capability')),
                    h('div', {className: 'form-group'}, h('div', {className: 'dtp-choice'}, h(Switch, {id: 'tailored-enabled', checked: s.draft.enabled, disabled: s.busy || s.blocked || !s.data.capable, 'data-enabled': true, 'aria-describedby': 'tailored-help-enabled', onChange: value => this.changeDraft(draft => {draft.enabled = value; return draft;})}), h('label', {htmlFor: 'tailored-enabled'}, text(s, 'enabled'))), this.help('enabled', text(s, s.draft.enabled ? 'intro' : 'disabled'))),
                    this.candidates(),
                    h('div', {className: 'dtp-actions'}, h(Button, {'data-action': 'preview', disabled: locked, loading: s.busy, onClick: () => this.request('preview')}, text(s, 'preview')),
                        h(Button, {type: 'primary', 'data-action': 'save', disabled: locked || !dirty(s) || !s.evaluation || !s.evaluation.validation.valid || s.previewKey !== canonical(s.draft), loading: s.busy, onClick: () => this.request('save')}, text(s, 'save')),
                        h(Button, {'data-action': 'reset', disabled: s.busy || s.blocked || !dirty(s), onClick: () => this.reset()}, text(s, 'reset'))),
                    this.model()))));
    }
}
t.default = TailoredPricingPage;

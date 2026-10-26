/* Source fragment embedded into the existing node-management webpack module.
 * Its imports (React, Drawer, Select, notification and dva connect) are already
 * bound by that module. Run patch_admin_mihomo_nodes.py after editing this file. */
var imnProtocolNames = {
    ssr: 'ShadowsocksR', socks5: 'SOCKS5', http: 'HTTP / HTTPS', snell: 'Snell',
    wireguard: 'WireGuard / AmneziaWG', shadowquic: 'ShadowQUIC', 'gost-relay': 'GOST Relay',
    ssh: 'SSH', mieru: 'Mieru', sudoku: 'Sudoku', masque: 'MASQUE', openvpn: 'OpenVPN',
    tailscale: 'Tailscale / Headscale', zerotier: 'ZeroTier', easytier: 'EasyTier'
};
function imnToken() { return localStorage.getItem('authorization') || ''; }
function imnScope() { return location.pathname + '#' + location.hash; }
function imnText(fa, en) { return /^fa/i.test(localStorage.getItem('umi_locale') || 'fa') ? fa : en; }
function imnCopy(value) { return JSON.parse(JSON.stringify(value)); }
async function imnApi(action, body, session, controller) {
    var secure = String((window.settings || {}).secure_path || '');
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!/^[A-Za-z0-9_-]+$/.test(secure) || !session.token) throw new Error(imnText('نشست مدیریت معتبر نیست.', 'An admin session is required.'));
    var response = await fetch('/api/v1/' + secure + '/server/mihomo/' + action, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {authorization: session.token, Accept: 'application/json', 'Content-Type': 'application/json'},
        signal: controller ? controller.signal : undefined,
        body: body === undefined ? undefined : JSON.stringify(body)
    });
    var data = await response.json();
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!response.ok) {
        var message = data.message || imnText('درخواست ناموفق بود.', 'Request failed.');
        if (data.errors) message += '\n' + Object.keys(data.errors).map(function(key) { return key + ': ' + data.errors[key].join(' '); }).join('\n');
        throw new Error(message);
    }
    return data.data;
}
async function imnMutation(owner, action, body) {
    if (owner.imnBusy) return;
    if (action === 'drop' && !window.confirm(imnText('این نود حذف شود؟', 'Delete this node?'))) return;
    var session = {token: imnToken(), scope: imnScope()};
    owner.imnBusy = true;
    try {
        await imnApi(action, body, session);
        owner.props.dispatch({type: 'serverManage/getNodes'});
    } catch(error) {
        if (session.token === imnToken() && session.scope === imnScope() && error.message !== 'stale') c['a'].error(error.message);
    } finally { owner.imnBusy = false; }
}
function imnAddress(row) {
    if (row.host) return String(row.host) + (row.port ? ':' + row.port : '');
    var settings = row.outbound_settings || {};
    return settings.network || settings.hostname || settings['network-name'] || imnProtocolNames[row.protocol] || '';
}
var imnFieldLabels = {
    username: ['نام کاربری', 'Username'], password: ['رمز عبور', 'Password'], psk: ['کلید PSK', 'PSK'],
    cipher: ['روش رمزگذاری', 'Cipher'], protocol: ['پروتکل SSR', 'SSR protocol'], obfs: ['استتار', 'Obfuscation'],
    'obfs-param': ['پارامتر استتار', 'Obfuscation parameter'], 'protocol-param': ['پارامتر پروتکل SSR', 'SSR protocol parameter'],
    version: ['نسخه', 'Version'], udp: ['UDP', 'UDP'], tls: ['TLS', 'TLS'],
    ip: ['آدرس IPv4 کلاینت', 'Client IPv4 address'], ipv6: ['آدرس IPv6 کلاینت', 'Client IPv6 address'],
    'private-key': ['کلید خصوصی کلاینت', 'Client private key'], 'public-key': ['کلید عمومی سرویس', 'Service public key'],
    'pre-shared-key': ['کلید از پیش مشترک', 'Pre-shared key'], reserved: ['Reserved', 'Reserved'],
    'private-key-passphrase': ['عبارت عبور کلید خصوصی', 'Private key passphrase'],
    transport: ['انتقال', 'Transport'], 'port-range': ['بازهٔ پورت (به‌جای پورت اتصال)', 'Port range (instead of connection port)'],
    sni: ['SNI', 'SNI'], key: ['کلید', 'Key'], ca: ['گواهی CA', 'CA certificate'], cert: ['گواهی کلاینت', 'Client certificate'],
    proto: ['انتقال OpenVPN', 'OpenVPN transport'], 'auth-key': ['کلید ثبت دستگاه', 'Device registration key'],
    hostname: ['نام دستگاه', 'Device hostname'], 'exit-node': ['نود خروجی', 'Exit node'],
    'control-url': ['نشانی کنترلر', 'Controller URL'], network: ['شناسهٔ شبکه', 'Network ID'],
    'network-name': ['نام شبکه', 'Network name'], 'network-secret': ['رمز شبکه', 'Network secret'],
    peers: ['همتاها', 'Peers'], 'aead-method': ['روش AEAD', 'AEAD method'],
    'padding-min': ['حداقل Padding', 'Minimum padding'], 'padding-max': ['حداکثر Padding', 'Maximum padding'],
    'table-type': ['نوع جدول Sudoku', 'Sudoku table type'], 'skip-cert-verify': ['صرف‌نظر از تأیید گواهی', 'Skip certificate verification']
};
var imnAdditionalFields = {
    ssr: {'obfs-param':'', 'protocol-param':''},
    wireguard: {ipv6:'', 'pre-shared-key':'', reserved:[], peers:[], 'amnezia-wg-option':{}},
    ssh: {'private-key':'', 'private-key-passphrase':''},
    mieru: {'port-range':''},
    openvpn: {cert:'', key:''},
    tailscale: {'control-url':''}
};
class MihomoNodeDrawer extends y.a.Component {
    constructor(props) {
        super(props);
        this.state = {visible:false, busy:false, error:'', server:null, catalog:null, json:'', optionDrafts:{}};
        this.session = null; this.controller = null; this.alive = true;
    }
    componentWillUnmount() {
        this.alive = false; clearInterval(this.timer);
        if (this.controller) this.controller.abort();
        this.session = null;
    }
    current(session) { return this.alive && this.session === session && session.token === imnToken() && session.scope === imnScope(); }
    close() {
        clearInterval(this.timer); this.timer = null;
        if (this.controller) this.controller.abort();
        this.session = null;
        if (this.alive) this.setState({visible:false,busy:false,error:'',server:null,catalog:null,json:'',optionDrafts:{}});
    }
    async open() {
        if (this.controller) this.controller.abort();
        var session = {token:imnToken(), scope:imnScope()};
        this.session = session; this.controller = new AbortController();
        clearInterval(this.timer);
        this.timer = setInterval(()=>{if (!this.current(session)) this.close();},250);
        this.setState({visible:true,busy:true,error:'',server:null,catalog:null,json:'',optionDrafts:{}});
        try {
            var data = await imnApi('fetch', undefined, session, this.controller);
            if (!this.current(session)) return;
            var protocol = this.props.record ? this.props.record.protocol : this.props.protocol;
            if (!data || !data.protocols || !data.protocols[protocol]) throw new Error(imnText('پروتکل در این نسخه موجود نیست.', 'This protocol is unavailable.'));
            var example = imnCopy(data.protocols[protocol].example);
            var server = this.props.record ? imnCopy(this.props.record) : {
                name:'',protocol:protocol,host:example.server || '',port:example.port || '',server_port:example.port || '',
                rate:1,group_id:[],route_id:[],tags:[],show:0,sort:0,outbound_settings:example
            };
            server.group_id = (server.group_id || []).map(String);
            server.route_id = (server.route_id || []).map(String);
            this.setState({catalog:data.protocols,server:server,json:JSON.stringify(server.outbound_settings || {},null,2),optionDrafts:{},busy:false});
        } catch(error) { if (this.current(session)) this.setState({busy:false,error:error.message}); }
    }
    common(key,value) {
        var server = Object.assign({},this.state.server); server[key] = value;
        this.setState({server:server});
    }
    option(key,value) {
        var server = Object.assign({},this.state.server), settings = Object.assign({},server.outbound_settings);
        settings[key] = value; server.outbound_settings = settings;
        if (key === 'port-range' && value) server.port = '';
        this.setState({server:server,json:JSON.stringify(settings,null,2),error:''});
    }
    optionJson(key,text) {
        var drafts = Object.assign({},this.state.optionDrafts); drafts[key] = text;
        this.setState({optionDrafts:drafts});
        if (text.trim() === '') {
            var server = Object.assign({},this.state.server), settings = Object.assign({},server.outbound_settings);
            delete settings[key]; server.outbound_settings = settings;
            this.setState({server:server,json:JSON.stringify(settings,null,2),error:''}); return;
        }
        try { this.option(key,JSON.parse(text)); }
        catch(_) { this.setState({error:imnText('مقدار JSON معتبر نیست: ','Invalid JSON value: ')+key}); }
    }
    advanced(value) {
        this.setState({json:value});
        try {
            var settings = JSON.parse(value);
            if (!settings || Array.isArray(settings) || typeof settings !== 'object') return;
            var server = Object.assign({},this.state.server,{outbound_settings:settings});
            if (server.protocol === 'mieru' && settings['port-range']) server.port = '';
            this.setState({server:server,optionDrafts:{},error:''});
        } catch(_) {}
    }
    async save(event) {
        event.preventDefault(); if (this.state.busy || !this.session) return;
        if (!this.current(this.session)) { this.close(); return; }
        var server = imnCopy(this.state.server), settings;
        var drafts = this.state.optionDrafts;
        try { Object.keys(drafts).forEach(key=>{if (drafts[key].trim() !== '') JSON.parse(drafts[key]);}); }
        catch(_) { this.setState({error:imnText('فیلد JSON را پیش از ذخیره اصلاح کنید.','Correct the JSON field before saving.')}); return; }
        try {
            settings = JSON.parse(this.state.json);
            if (!settings || Array.isArray(settings) || typeof settings !== 'object') throw new Error();
        } catch(_) { this.setState({error:imnText('تنظیمات پیشرفته باید یک شیء JSON معتبر باشد.', 'Advanced settings must be a valid JSON object.')}); return; }
        var session = this.session;
        server.outbound_settings = settings;
        server.port = server.port === '' || server.port == null ? null : Number(server.port);
        server.server_port = server.server_port === '' || server.server_port == null ? null : Number(server.server_port);
        server.host = server.host || null; server.rate = Number(server.rate); server.sort = Number(server.sort); server.show = !!server.show;
        this.setState({busy:true,error:''});
        try {
            await imnApi('save', server, session, this.controller);
            if (!this.current(session)) return;
            this.props.dispatch({type:'serverManage/getNodes'}); this.close();
        } catch(error) { if (this.current(session)) this.setState({busy:false,error:error.message}); }
    }
    input(label,name,value,onChange,options) {
        var props = Object.assign({name:name,value:value == null ? '' : value,disabled:this.state.busy,className:'form-control',onChange:event=>onChange(event.target.value)},options || {});
        return y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,label),y.a.createElement('input',props));
    }
    options() {
        var server = this.state.server, example = this.state.catalog[server.protocol].example;
        var definitions = Object.assign({},example,imnAdditionalFields[server.protocol] || {},server.outbound_settings);
        return Object.keys(definitions).filter(key=>['server','port','name','type'].indexOf(key)<0).map(key=>{
            var value = server.outbound_settings[key], sample = definitions[key], labels = imnFieldLabels[key], label = labels ? imnText(labels[0],labels[1]) : key;
            if (typeof sample === 'boolean' || typeof value === 'boolean') return y.a.createElement('div',{className:'form-group',key:key},y.a.createElement('label',null,
                y.a.createElement('input',{name:'outbound.'+key,type:'checkbox',checked:!!value,disabled:this.state.busy,onChange:event=>this.option(key,event.target.checked)}),' ',label));
            if (Array.isArray(sample) || (sample && typeof sample === 'object')) return y.a.createElement('div',{className:'form-group',key:key},y.a.createElement('label',null,label+' (JSON)'),
                y.a.createElement('textarea',{name:'outbound.'+key,className:'form-control',dir:'ltr',rows:3,disabled:this.state.busy,value:Object.prototype.hasOwnProperty.call(this.state.optionDrafts,key) ? this.state.optionDrafts[key] : value === undefined ? '' : JSON.stringify(value,null,2),onChange:event=>this.optionJson(key,event.target.value)}));
            if (['ca','cert','key','private-key'].indexOf(key)>=0) return y.a.createElement('div',{className:'form-group',key:key},y.a.createElement('label',null,label),
                y.a.createElement('textarea',{name:'outbound.'+key,className:'form-control',dir:'ltr',rows:3,disabled:this.state.busy,value:value || '',onChange:event=>this.option(key,event.target.value)}));
            var numeric = typeof sample === 'number';
            return y.a.createElement('div',{key:key},this.input(label,'outbound.'+key,value,change=>this.option(key,numeric && change !== '' ? Number(change) : change),{type:numeric?'number':/password|secret|psk|auth-key|passphrase/.test(key)?'password':'text',autoComplete:'off',dir:'ltr'}));
        });
    }
    body() {
        var server = this.state.server;
        if (!server) return null;
        var overlay = ['tailscale','zerotier','easytier'].indexOf(server.protocol)>=0;
        return y.a.createElement('form',{'data-mihomo-form':server.protocol,onSubmit:event=>this.save(event)},
            y.a.createElement('div',{className:'row'},y.a.createElement('div',{className:'col-md-8'},this.input(imnText('نام نود','Node name'),'name',server.name,v=>this.common('name',v),{required:true,maxLength:255})),
                y.a.createElement('div',{className:'col-md-4'},this.input(imnText('ضریب','Rate'),'rate',server.rate,v=>this.common('rate',v),{type:'number',min:0,step:'0.01',required:true}))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('گروه دسترسی','Access groups')),
                y.a.createElement(N['a'],{mode:'multiple',value:server.group_id,style:{width:'100%'},disabled:this.state.busy,'data-mihomo-groups':true,onChange:values=>this.common('group_id',values)},
                    this.props.groups.map(group=>y.a.createElement(N['a'].Option,{key:String(group.id),value:String(group.id)},group.name)))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('برچسب نود','Tags')),
                y.a.createElement(N['a'],{mode:'tags',value:server.tags || [],style:{width:'100%'},disabled:this.state.busy,onChange:values=>this.common('tags',values)})),
            !overlay && y.a.createElement('div',{className:'row'},y.a.createElement('div',{className:'col-md-6'},this.input(imnText('آدرس اتصال','Connection address'),'host',server.host,v=>this.common('host',v),{required:server.protocol !== 'wireguard',dir:'ltr'})),
                y.a.createElement('div',{className:'col-md-3'},this.input(imnText('پورت اتصال','Connection port'),'port',server.port,v=>this.common('port',v),{type:'number',min:1,max:65535,disabled:this.state.busy || !!server.outbound_settings['port-range']})),
                y.a.createElement('div',{className:'col-md-3'},this.input(imnText('پورت سرویس','Service port'),'server_port',server.server_port,v=>this.common('server_port',v),{type:'number',min:1,max:65535}))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('مسیریابی','Routes')),
                y.a.createElement(N['a'],{mode:'multiple',value:server.route_id || [],style:{width:'100%'},disabled:this.state.busy,onChange:values=>this.common('route_id',values)},
                    this.props.routes.map(route=>y.a.createElement(N['a'].Option,{key:String(route.id),value:String(route.id)},route.remarks || route.name || String(route.id))))),
            y.a.createElement('h4',null,imnProtocolNames[server.protocol]),this.options(),
            y.a.createElement('p',{className:'text-muted'},imnText('اطلاعات و تنظیمات نود در پنل ذخیره می‌شود. پشتیبانی سمت سرور در V2bX مرحلهٔ بعد است.','Node settings are managed here. V2bX server support is a later step.')),
            y.a.createElement('details',null,y.a.createElement('summary',null,imnText('تنظیمات پیشرفته','Advanced settings')),
                y.a.createElement('p',{className:'text-muted'},imnText('گزینه‌های تو در تو حفظ می‌شوند. نام پروتکل، نام نود و آدرس/پورت از فیلدهای همین فرم گرفته می‌شوند. {{uuid}} و {{user_id}} در مقادیر متنی برای هر مشترک جایگزین می‌شوند.','Nested options are preserved. Protocol, name and address/port come from this form. {{uuid}} and {{user_id}} in string values are replaced per subscriber.')),
                y.a.createElement('textarea',{name:'outbound_settings',className:'form-control',dir:'ltr',rows:12,disabled:this.state.busy,value:this.state.json,onChange:event=>this.advanced(event.target.value),style:{fontFamily:'monospace'}})),
            this.input(imnText('ترتیب','Sort order'),'sort',server.sort,v=>this.common('sort',v),{type:'number',min:0,max:2147483647}),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,y.a.createElement('input',{name:'show',type:'checkbox',checked:!!server.show,disabled:this.state.busy,onChange:event=>this.common('show',event.target.checked)}),' ',imnText('نمایش در اشتراک','Visible in subscriptions'))),
            y.a.createElement('button',{type:'submit','data-mihomo-save':true,className:'btn btn-primary',disabled:this.state.busy},imnText('ثبت','Save')));
    }
    render() {
        return y.a.createElement(y.a.Fragment,null,y.a.cloneElement(this.props.children,{onClick:event=>{event.preventDefault();this.open();}}),
            y.a.createElement(R['a'],{id:'mihomo-node-drawer',visible:this.state.visible,width:'80%',maskClosable:true,onClose:()=>this.close(),title:(this.props.record?imnText('ویرایش نود','Edit node'):imnText('نود جدید','New node'))+' — '+(imnProtocolNames[this.props.protocol || (this.props.record || {}).protocol] || ''),destroyOnClose:true},
                this.state.busy && y.a.createElement('p',{role:'status'},imnText('در حال انجام…','Working…')),
                this.state.error && y.a.createElement('p',{role:'alert',className:'alert alert-danger',style:{whiteSpace:'pre-wrap'}},this.state.error),this.body()));
    }
}
var mMihomo = Object(_['c'])(state=>({groups:state.serverGroup.groups || [],routes:state.serverRoute.routes || []}))(MihomoNodeDrawer);

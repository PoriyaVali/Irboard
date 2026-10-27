/* Maintained React fragment for the native Irboard node manager.
 * Embed in both existing SPAs with patch_admin_mihomo_nodes.py. */
var imnProtocolNames = {
    ssr:'ShadowsocksR', socks5:'SOCKS5', http:'HTTP / HTTPS', snell:'Snell', wireguard:'WireGuard / AmneziaWG',
    shadowquic:'ShadowQUIC', gostrelay:'GOST Relay', ssh:'SSH', mieru:'Mieru', sudoku:'Sudoku', masque:'MASQUE',
    openvpn:'OpenVPN', tailscale:'Tailscale / Headscale', zerotier:'ZeroTier', easytier:'EasyTier'
};
function imnToken() { return localStorage.getItem('authorization') || ''; }
function imnScope() { return location.pathname + '#' + location.hash; }
function imnText(fa,en) { return /^fa/i.test(localStorage.getItem('umi_locale') || 'fa') ? fa : en; }
function imnCopy(value) { return JSON.parse(JSON.stringify(value)); }
async function imnApi(path,body,session,controller) {
    var secure = String((window.settings || {}).secure_path || '');
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!/^[A-Za-z0-9_-]+$/.test(secure) || !session.token) throw new Error(imnText('نشست مدیریت معتبر نیست.','An admin session is required.'));
    var response = await fetch('/api/v1/'+secure+'/server/'+path, {
        method:body === undefined?'GET':'POST',
        headers:{authorization:session.token,Accept:'application/json','Content-Type':'application/json'},
        signal:controller?controller.signal:undefined,body:body === undefined?undefined:JSON.stringify(body)
    });
    var data = await response.json();
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!response.ok) {
        var message = data.message || imnText('درخواست ناموفق بود.','Request failed.');
        if (data.errors) message += '\n'+Object.keys(data.errors).map(key=>key+': '+data.errors[key].join(' ')).join('\n');
        throw new Error(message);
    }
    return data.data;
}
async function imnMutation(owner,type,action,body) {
    if (owner.imnBusy) return;
    if (action === 'drop' && !window.confirm(imnText('این نود حذف شود؟','Delete this node?'))) return;
    var session = {token:imnToken(),scope:imnScope()};owner.imnBusy = true;
    try { await imnApi(type+'/'+action,body,session);owner.props.dispatch({type:'serverManage/getNodes'}); }
    catch(error) { if (session.token === imnToken() && session.scope === imnScope() && error.message !== 'stale') c['a'].error(error.message); }
    finally { owner.imnBusy = false; }
}
function imnAddress(row) {
    if (row.host) return String(row.host)+(row.port?':'+row.port:row.port_range?':'+row.port_range:'');
    return row.network || row.hostname || row.network_name || imnProtocolNames[row.type] || '';
}
var imnFieldLabels = {
    username:['نام کاربری','Username'],password:['رمز عبور','Password'],psk:['کلید PSK','PSK'],cipher:['روش رمزگذاری','Cipher'],
    protocol:['پروتکل SSR','SSR protocol'],obfs:['استتار','Obfuscation'],version:['نسخه','Version'],udp:['UDP','UDP'],tls:['TLS','TLS'],
    ip:['آدرس IPv4 کلاینت','Client IPv4 address'],ipv6:['آدرس IPv6 کلاینت','Client IPv6 address'],
    'private-key':['کلید خصوصی کلاینت','Client private key'],'public-key':['کلید عمومی سرویس','Service public key'],
    'pre-shared-key':['کلید از پیش مشترک','Pre-shared key'],'private-key-passphrase':['عبارت عبور کلید خصوصی','Private key passphrase'],
    transport:['انتقال','Transport'],'port-range':['بازهٔ پورت','Port range'],sni:['SNI','SNI'],key:['کلید','Key'],ca:['گواهی CA','CA certificate'],
    cert:['گواهی کلاینت','Client certificate'],proto:['انتقال OpenVPN','OpenVPN transport'],'auth-key':['کلید ثبت دستگاه','Device registration key'],
    hostname:['نام دستگاه','Device hostname'],'exit-node':['نود خروجی','Exit node'],'control-url':['نشانی کنترلر','Controller URL'],
    network:['شناسهٔ شبکه','Network ID'],'network-name':['نام شبکه','Network name'],'network-secret':['رمز شبکه','Network secret'],
    peers:['همتاها','Peers'],listeners:['نشانی‌های ورودی','Listeners'],'aead-method':['روش AEAD','AEAD method'],
    'padding-min':['حداقل Padding','Minimum padding'],'padding-max':['حداکثر Padding','Maximum padding'],'table-type':['نوع جدول Sudoku','Sudoku table type']
};
class MihomoNodeDrawer extends y.a.Component {
    constructor(props) { super(props);this.state = {visible:false,busy:false,error:'',server:null,catalog:null,drafts:{}};this.session=null;this.controller=null;this.alive=true; }
    componentWillUnmount() { this.alive=false;clearInterval(this.timer);if(this.controller)this.controller.abort();this.session=null; }
    current(session) { return this.alive && this.session === session && session.token === imnToken() && session.scope === imnScope(); }
    close() { clearInterval(this.timer);if(this.controller)this.controller.abort();this.session=null;if(this.alive)this.setState({visible:false,busy:false,error:'',server:null,catalog:null,drafts:{}}); }
    async open() {
        if(this.controller)this.controller.abort();
        var session={token:imnToken(),scope:imnScope()};this.session=session;this.controller=new AbortController();clearInterval(this.timer);
        this.timer=setInterval(()=>{if(!this.current(session))this.close();},250);
        this.setState({visible:true,busy:true,error:'',server:null,catalog:null,drafts:{}});
        try {
            var data=await imnApi('protocols',undefined,session,this.controller);if(!this.current(session))return;
            var type=this.props.record?this.props.record.type:this.props.protocol,definition=data && data.protocols && data.protocols[type];
            if(!definition)throw new Error(imnText('پروتکل در این نسخه موجود نیست.','This protocol is unavailable.'));
            var sample=definition.example,server=this.props.record?imnCopy(this.props.record):{
                type:type,name:'',host:sample.server || '',port:sample.port || null,server_port:sample.port || null,
                rate:1,group_id:[],route_id:[],tags:[],parent_id:null,show:0,sort:0
            };
            if(!this.props.record)Object.keys(definition.fields).forEach(column=>{
                var option=definition.fields[column].option;
                if(Object.prototype.hasOwnProperty.call(sample,option))server[column]=imnCopy(sample[option]);
            });
            server.group_id=(server.group_id || []).map(String);server.route_id=(server.route_id || []).map(String);
            this.setState({catalog:data.protocols,server:server,drafts:{},busy:false});
        } catch(error) { if(this.current(session))this.setState({busy:false,error:error.message}); }
    }
    source(server) {
        var source=server,seen=new Set();
        while(source.parent_id && !seen.has(source.parent_id)){
            seen.add(source.parent_id);var parent=this.props.nodes.find(row=>row.type===server.type && row.id===source.parent_id);
            if(!parent)break;source=parent;
        }
        return source;
    }
    common(key,value) {
        var server=Object.assign({},this.state.server);server[key]=value;
        var source=this.source(server);
        if(server.type==='mieru' && source.port_range)server.port=null;
        if(server.type==='wireguard' && source.peers && source.peers.length){server.host=null;server.port=null;}
        this.setState({server:server,error:''});
    }
    json(column,value) {
        var drafts=Object.assign({},this.state.drafts);drafts[column]=value;this.setState({drafts:drafts});
        if(!value.trim()){this.common(column,null);return;}
        try {this.common(column,JSON.parse(value));}catch(_){this.setState({error:imnText('JSON معتبر نیست: ','Invalid JSON: ')+column});}
    }
    async save(event) {
        event.preventDefault();if(this.state.busy || !this.session)return;if(!this.current(this.session)){this.close();return;}
        var server=this.state.server,definition=this.state.catalog[server.type],payload={},session=this.session;
        try {Object.keys(this.state.drafts).forEach(key=>{if(this.state.drafts[key].trim())JSON.parse(this.state.drafts[key]);});}
        catch(_){this.setState({error:imnText('فیلد JSON را اصلاح کنید.','Correct the JSON field before saving.')});return;}
        ['id','name','host','port','server_port','rate','group_id','route_id','tags','parent_id','show','sort'].concat(Object.keys(definition.fields)).forEach(key=>{if(Object.prototype.hasOwnProperty.call(server,key))payload[key]=server[key];});
        ['port','server_port','parent_id'].forEach(key=>{payload[key]=payload[key]==='' || payload[key]==null?null:Number(payload[key]);});
        payload.host=payload.host || null;payload.rate=Number(payload.rate);payload.sort=Number(payload.sort);payload.show=!!payload.show;
        this.setState({busy:true,error:''});
        try {await imnApi(server.type+'/save',payload,session,this.controller);if(!this.current(session))return;this.props.dispatch({type:'serverManage/getNodes'});this.close();}
        catch(error){if(this.current(session))this.setState({busy:false,error:error.message});}
    }
    input(label,name,value,onChange,options) {
        var props=Object.assign({name:name,value:value==null?'':value,disabled:this.state.busy,className:'form-control',onChange:event=>onChange(event.target.value)},options || {});
        return y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,label),y.a.createElement('input',props));
    }
    fields(advanced) {
        var server=this.state.server,definition=this.state.catalog[server.type],sample=definition.example;
        return Object.keys(definition.fields).filter(column=>{
            var essential=Object.prototype.hasOwnProperty.call(sample,definition.fields[column].option) || ['private_key','peers','port_range','cert','key'].indexOf(column)>=0;
            return advanced?!essential:essential;
        }).map(column=>{
            var field=definition.fields[column],option=field.option,value=server[column],labels=imnFieldLabels[option],label=labels?imnText(labels[0],labels[1]):option;
            if(field.kind==='bool')return y.a.createElement('div',{key:column,className:'form-group'},y.a.createElement('label',null,label),
                y.a.createElement('select',{name:column,className:'form-control',value:value==null?'':value?'true':'false',disabled:this.state.busy,onChange:event=>this.common(column,event.target.value===''?null:event.target.value==='true')},
                    y.a.createElement('option',{value:''},imnText('پیش‌فرض پروتکل','Protocol default')),y.a.createElement('option',{value:'true'},imnText('فعال','Enabled')),y.a.createElement('option',{value:'false'},imnText('غیرفعال','Disabled'))));
            if(field.kind==='json' || ['ca','cert','key','private_key','certificate','tls_auth','tls_crypt','tls_crypt_v2'].indexOf(column)>=0)return y.a.createElement('div',{key:column,className:'form-group'},y.a.createElement('label',null,label+(field.kind==='json'?' (JSON)':'')),
                y.a.createElement('textarea',{name:column,className:'form-control',dir:'ltr',rows:3,disabled:this.state.busy,value:field.kind==='json'?(Object.prototype.hasOwnProperty.call(this.state.drafts,column)?this.state.drafts[column]:value==null?'':JSON.stringify(value,null,2)):(value || ''),onChange:event=>field.kind==='json'?this.json(column,event.target.value):this.common(column,event.target.value)}));
            return y.a.createElement('div',{key:column},this.input(label,column,value,change=>this.common(column,field.kind==='int'?(change===''?null:Number(change)):change),{type:field.kind==='int'?'number':/password|secret|psk|auth_key|passphrase/.test(column)?'password':'text',autoComplete:'off',dir:'ltr',min:field.kind==='int'?0:undefined}));
        });
    }
    parents() {
        var server=this.state.server,rows=this.props.nodes.filter(row=>row.type===server.type),blocked=new Set([server.id]);
        var changed=true;while(changed){changed=false;rows.forEach(row=>{if(blocked.has(row.parent_id) && !blocked.has(row.id)){blocked.add(row.id);changed=true;}});}
        return rows.filter(row=>!blocked.has(row.id));
    }
    body() {
        var server=this.state.server;if(!server)return null;
        var source=this.source(server),overlay=['tailscale','zerotier','easytier'].indexOf(server.type)>=0 || (server.type==='wireguard' && source.peers && source.peers.length);
        return y.a.createElement('form',{'data-mihomo-form':server.type,onSubmit:event=>this.save(event)},
            y.a.createElement('div',{className:'row'},y.a.createElement('div',{className:'col-md-8'},this.input(imnText('نام نود','Node name'),'name',server.name,v=>this.common('name',v),{required:true,maxLength:255})),
                y.a.createElement('div',{className:'col-md-4'},this.input(imnText('ضریب','Rate'),'rate',server.rate,v=>this.common('rate',v),{type:'number',min:0,step:'0.01',required:true}))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('گروه دسترسی','Access groups')),
                y.a.createElement(N['a'],{mode:'multiple',value:server.group_id,style:{width:'100%'},disabled:this.state.busy,'data-mihomo-groups':true,onChange:values=>this.common('group_id',values)},this.props.groups.map(group=>y.a.createElement(N['a'].Option,{key:String(group.id),value:String(group.id)},group.name)))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('نود والد','Parent node')),
                y.a.createElement('select',{name:'parent_id',className:'form-control',value:server.parent_id || '',disabled:this.state.busy,onChange:event=>this.common('parent_id',event.target.value?Number(event.target.value):null)},
                    y.a.createElement('option',{value:''},imnText('بدون والد','No parent')),this.parents().map(row=>y.a.createElement('option',{key:row.id,value:row.id},row.name+' (#'+row.id+')')))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('برچسب نود','Tags')),y.a.createElement(N['a'],{mode:'tags',value:server.tags || [],style:{width:'100%'},disabled:this.state.busy,onChange:values=>this.common('tags',values)})),
            !overlay && y.a.createElement('div',{className:'row'},y.a.createElement('div',{className:'col-md-6'},this.input(imnText('آدرس اتصال','Connection address'),'host',server.host,v=>this.common('host',v),{required:server.type!=='wireguard',dir:'ltr'})),
                y.a.createElement('div',{className:'col-md-3'},this.input(imnText('پورت اتصال','Connection port'),'port',server.port,v=>this.common('port',v),{type:'number',min:1,max:65535,disabled:this.state.busy || !!source.port_range})),
                y.a.createElement('div',{className:'col-md-3'},this.input(imnText('پورت سرویس','Service port'),'server_port',server.server_port,v=>this.common('server_port',v),{type:'number',min:1,max:65535}))),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,imnText('مسیریابی','Routes')),y.a.createElement(N['a'],{mode:'multiple',value:server.route_id || [],style:{width:'100%'},disabled:this.state.busy,onChange:values=>this.common('route_id',values)},this.props.routes.map(route=>y.a.createElement(N['a'].Option,{key:String(route.id),value:String(route.id)},route.remarks || route.name || String(route.id))))),
            server.parent_id?y.a.createElement('p',{className:'text-muted'},imnText('تنظیمات پروتکل از نود والد گرفته می‌شود.','Protocol settings are inherited from the parent node.')):
                y.a.createElement(y.a.Fragment,null,y.a.createElement('h4',null,imnProtocolNames[server.type]),this.fields(false),y.a.createElement('details',null,y.a.createElement('summary',null,imnText('تنظیمات پیشرفتهٔ پروتکل','Advanced protocol settings')),this.fields(true))),
            this.input(imnText('ترتیب','Sort order'),'sort',server.sort,v=>this.common('sort',v),{type:'number',min:0,max:2147483647}),
            y.a.createElement('div',{className:'form-group'},y.a.createElement('label',null,y.a.createElement('input',{name:'show',type:'checkbox',checked:!!server.show,disabled:this.state.busy,onChange:event=>this.common('show',event.target.checked)}),' ',imnText('نمایش در اشتراک','Visible in subscriptions'))),
            y.a.createElement('button',{type:'submit','data-mihomo-save':true,className:'btn btn-primary',disabled:this.state.busy},imnText('ثبت','Save')));
    }
    render() {
        return y.a.createElement(y.a.Fragment,null,y.a.cloneElement(this.props.children,{onClick:event=>{event.preventDefault();this.open();}}),
            y.a.createElement(R['a'],{id:'mihomo-node-drawer',visible:this.state.visible,width:'80%',maskClosable:true,onClose:()=>this.close(),title:(this.props.record?imnText('ویرایش نود','Edit node'):imnText('نود جدید','New node'))+' — '+(imnProtocolNames[this.props.protocol || (this.props.record || {}).type] || ''),destroyOnClose:true},
                this.state.busy && y.a.createElement('p',{role:'status'},imnText('در حال انجام…','Working…')),this.state.error && y.a.createElement('p',{role:'alert',className:'alert alert-danger',style:{whiteSpace:'pre-wrap'}},this.state.error),this.body()));
    }
}
var mMihomo = Object(_['c'])(state=>({groups:state.serverGroup.groups || [],routes:state.serverRoute.routes || [],nodes:state.serverManage.servers || []}))(MihomoNodeDrawer);

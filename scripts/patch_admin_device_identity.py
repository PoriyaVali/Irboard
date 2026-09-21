#!/usr/bin/env python3
"""Add the "devices" tab to the compiled admin bundle.

The admin UI ships compiled only, so a new tab is three splices into
`public/assets/admin/umi.js` (and its byte-identical twin `umi-fa.js`, which is
the one actually loaded when the panel is in Persian):

  1. a menu object, cloned from the Group Pricing one,
  2. a route row pointing at the SAME shared placeholder component `pi3A`,
  3. an IIFE at the tail that overwrites `#main-container` when the hash matches.

🔑 Nothing here is invented. Each construct is a clone of a working one, because
building one by hand in a minified bundle has blanked this panel twice and
`node --check` passed through both failures - neither was a syntax error.

The injected script carries the same anti-race design the Group Pricing tab
needed: the latch is a marker element in the DOM, not a boolean, because React
renders the placeholder into the same container just after we write into it and
a boolean cannot tell that it happened. load()'s own write carries the marker
too, or a completed page would read as "not ours" and re-inject forever, and a
budget (3 per 3s) rather than a cooldown bounds the one case the marker cannot
cover - a failed fetch leaving markup with no marker. A cooldown was tried in
the Group Pricing patch and blocked the very recovery it existed for.

Idempotent: the tail block is fenced, so a re-run replaces it. The menu and
route splices are guarded by "is it already there" instead, since they sit in
the compiled app where a fence comment would be noise.

The tab shows what DeviceIdentityService records, searches it, and - since v2 -
acts on it: ban the ticked accounts, forget a device, and undo either.

🔴 Why the ban control looks the way it does. A "ban everyone on this hash"
button would be one click from banning strangers, because Android does not
guarantee the Widevine id is unique and two unrelated phones can present the
same one. So: every checkbox starts empty, there is no "select all", the
confirmation names every account by email, and the undo is offered in place the
moment the ban returns - not buried in a history tab. The server takes explicit
user ids, never a hash, so the screen is the only thing that can widen a ban.

Markup follows the panel rather than inventing: a `block block-rounded` shell
around an `ant-table`, `ant-checkbox-wrapper` ticks, `ant-btn-danger` for the
destructive action. custom.css already right-aligns `.ant-table-thead th` /
`.ant-table-tbody td` and forces `code` and inputs to LTR, so this sets almost
no direction of its own - inline styles went from 50 to 12 in the v2 rewrite.
"""

import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]

START = "/*__DEVID_START__*/"
END = "/*__DEVID_END__*/"

# ---------------------------------------------------------------- anchors ---
# ASCII-only and asserted unique. The Persian in the real menu object is left
# where it is; we anchor on the href, walk out to the object's braces, and
# append after it, so no Persian is ever retyped here.
MENU_ANCHOR = 'href: "/group-pricing"'
ROUTE_ANCHOR = 'path: "/group-pricing"'

# Persian as escapes so this file and the emitted region stay ASCII.
T_DEVICES = "\\u062f\\u0633\\u062a\\u06af\\u0627\\u0647\\u200c\\u0647\\u0627"  # دستگاه‌ها

MENU_NEW = (
    '{title: "' + T_DEVICES + '", type: "item", href: "/device-identity", '
    'icon: o.a.createElement("i", {className: "nav-main-link-icon si si-screen-smartphone"})}'
)
ROUTE_NEW = '{path: "/device-identity", exact: !0, component: n("pi3A").default}'


# The tab body. Persian strings are \u escapes; everything else is plain ASCII.
BLOCK = START + r"""
;(function(){
if(window.__devid)return;window.__devid=1;
var API='/api/v1/'+((window.settings&&window.settings.secure_path)||'admin')+'/device-identity';
function tok(){return localStorage.getItem('authorization')||'';}
function j(u,o){o=o||{};o.headers=Object.assign({'Authorization':tok()},o.headers||{});
  return fetch(u,o).then(function(r){return r.json()});}
function jpost(u,body){return j(u,{method:'POST',
  headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})});}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function dt(t){return t?new Date(t*1000).toLocaleString('fa-IR'):'—';}
// Hashes go in <code>: custom.css forces code/pre to direction:ltr, so the
// digest reads correctly without any inline direction of our own.
function short(h){var a=h.indexOf(':');var k=a<0?'':h.slice(0,a);var d=a<0?h:h.slice(a+1);
  return '<span class="text-muted">'+esc(k)+'</span>:<code>'+esc(d.slice(0,12))+'…</code>';}

var injected=false;
var state={tab:'shared',busy:false};

/* ---------- shared markup ---------------------------------------------
   The panel's own vocabulary: a block shell around an ant-table. custom.css
   already right-aligns .ant-table-thead th and .ant-table-tbody td and keeps
   inputs and code LTR, so nothing here sets a direction by hand. */
function table(head,body){
  return '<div class="ant-table ant-table-small ant-table-bordered"><div class="ant-table-content">'
    +'<div class="ant-table-body"><table><thead class="ant-table-thead"><tr>'+head
    +'</tr></thead><tbody class="ant-table-tbody">'+body+'</tbody></table></div></div></div>';
}
function note(text){return '<div class="text-muted mb-3" style="font-size:12px;line-height:1.9">'+text+'</div>';}
function empty(text){return '<div class="text-center text-muted p-4">'+esc(text)+'</div>';}
function bannedTag(b){return b?' <span class="ant-tag ant-tag-red">مسدود</span>':'';}

function devCell(devs){
  if(!devs||!devs.length)return '<span class="text-muted">—</span>';
  return devs.map(function(d){
    return '<div class="mb-2" style="font-size:12px">'
      +'<b>'+esc(d.kinds.join(' + '))+'</b> <span class="text-muted">'+d.sightings+' بار</span>'
      +'<div class="text-muted" style="font-size:11px">'+d.ids.map(short).join(' · ')+'</div>'
      +'<div class="text-muted" style="font-size:11px">آخرین: '+dt(d.last_seen)+'</div>'
      +'</div>';
  }).join('');
}

/* ---------- shared devices, with the ban controls ---------------------- */
function renderShared(d){
  var rows=d.data||[];
  var head=note('یک دستگاه که روی چند اکانت دیده شده است. هر دستگاه چند هش می‌فرستد؛ اشتراک در یک هش کافی است.'
    +'<div class="mt-1">'+(d.scanned_accounts||0)+' اکانت دارای دستگاه بررسی شد.</div>');
  if(!rows.length)return head+empty('هیچ دستگاهی روی بیش از یک اکانت دیده نشده.');

  var body=rows.map(function(x,i){
    // Every box starts unticked. There is deliberately no "select all": the
    // operator has to look at each account before it can be banned.
    var accs=x.accounts.map(function(a){
      return '<label class="ant-checkbox-wrapper d-block mb-1">'
        +'<span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input devid-pick"'
        +' data-h="'+i+'" data-uid="'+a.user_id+'" data-email="'+esc(a.email)+'">'
        +'<span class="ant-checkbox-inner"></span></span>'
        +'<span><code>'+esc(a.email)+'</code>'+bannedTag(a.banned)
        +' <span class="text-muted" style="font-size:11px">#'+a.user_id+'</span></span></label>';
    }).join('');
    return '<tr><td>'+short(x.hash)+'</td><td>'+accs+'</td>'
      +'<td class="text-center"><b>'+x.count+'</b></td>'
      +'<td class="text-center"><button class="ant-btn ant-btn-danger ant-btn-sm devid-ban"'
      +' data-h="'+i+'" data-hash="'+esc(x.hash)+'">مسدود کردن انتخاب‌شده‌ها</button></td></tr>';
  }).join('');

  return head+table('<th>هش</th><th>اکانت‌ها</th><th>تعداد</th><th></th>',body);
}

function renderList(d){
  var rows=d.data||[];
  if(!rows.length)return empty('چیزی ثبت نشده.');
  var body=rows.map(function(x){
    var devs=(x.devices||[]).map(function(dv){
      var h=dv.ids&&dv.ids.length?dv.ids[0]:'';
      return '<div class="mb-2">'+devCell([dv])
        +(h?'<button class="ant-btn ant-btn-sm devid-forget" data-uid="'+x.user_id+'"'
            +' data-hash="'+esc(h)+'">فراموش کن</button>':'')+'</div>';
    }).join('')||'<span class="text-muted">—</span>';
    return '<tr><td><code>'+esc(x.email)+'</code>'+bannedTag(x.banned)
      +'<div class="text-muted" style="font-size:11px">#'+x.user_id+'</div></td>'
      +'<td class="text-center"><b>'+x.device_count+'</b></td><td>'+devs+'</td></tr>';
  }).join('');
  return table('<th>کاربر</th><th>تعداد</th><th>دستگاه‌ها</th>',body)
    +'<div class="text-muted mt-2" style="font-size:12px">'+(d.total||0)+' اکانت</div>';
}

/* ---------- history, and the undo ------------------------------------- */
function renderBans(d){
  var rows=d.data||[];
  if(!rows.length)return empty('هنوز مسدودسازی‌ای ثبت نشده.');
  var byBatch={},order=[];
  rows.forEach(function(r){
    if(!byBatch[r.batch]){byBatch[r.batch]={rows:[],created:r.created_at,hash:r.hash,open:false};order.push(r.batch);}
    byBatch[r.batch].rows.push(r);
    if(r.reverted_at===null)byBatch[r.batch].open=true;
  });
  var body=order.map(function(b){
    var g=byBatch[b];
    var who=g.rows.map(function(r){
      return '<div><code>'+esc(r.email||('#'+r.user_id))+'</code>'
        +(r.reverted_at!==null?' <span class="ant-tag">برگردانده شد</span>':bannedTag(r.banned_now))+'</div>';
    }).join('');
    return '<tr><td>'+dt(g.created)+'</td><td>'+short(g.hash||'')+'</td>'
      +'<td>'+who+'</td><td class="text-center">'+g.rows.length+'</td>'
      +'<td class="text-center">'
      +(g.open?'<button class="ant-btn ant-btn-sm devid-revert" data-batch="'+esc(b)+'">واگردانی</button>'
             :'<span class="text-muted">—</span>')+'</td></tr>';
  }).join('');
  return note('هر ردیف یک عملیات است. واگردانی هر حساب را دقیقاً به وضعیت قبلی‌اش برمی‌گرداند — حسابی که از قبل به دلیل دیگری مسدود بوده، مسدود می‌ماند.')
    +table('<th>زمان</th><th>هش</th><th>حساب‌ها</th><th>تعداد</th><th></th>',body);
}

function shell(inner){
  var t=state.tab;
  function btn(k,label){
    return '<button class="ant-btn'+(t===k?' ant-btn-primary':'')+' devid-tab mr-2" data-k="'+k+'">'+label+'</button>';
  }
  return '<div class="block block-rounded">'
    +'<div class="block-header block-header-default"><h3 class="block-title">شناسهٔ دستگاه‌ها</h3></div>'
    +'<div class="block-content">'
    +'<div class="mb-3">'+btn('shared','دستگاه مشترک')+btn('list','همهٔ اکانت‌ها')+btn('bans','تاریخچه')+'</div>'
    +'<div class="mb-3 d-flex" style="gap:8px;flex-wrap:wrap">'
      +'<input id="devid-q" class="ant-input" style="max-width:420px" placeholder="ایمیل، یا هش ۶۴ حرفی، یا kind:hash">'
      +'<button id="devid-go" class="ant-btn ant-btn-primary">جست‌وجو</button></div>'
    +'<div id="devid-msg"></div><div id="devid-result"></div>'
    +'<div id="devid-body">'+inner+'</div>'
    +'</div></div>';
}

function msg(html,kind){
  var e=document.getElementById('devid-msg');
  if(!e)return;
  e.innerHTML=html?'<div class="ant-alert ant-alert-'+(kind||'info')+' mb-3" style="padding:8px 12px">'+html+'</div>':'';
}

/* ---------- wiring ----------------------------------------------------- */
function wire(){
  [].forEach.call(document.querySelectorAll('.devid-tab'),function(b){
    b.onclick=function(){state.tab=b.getAttribute('data-k');load();};
  });

  [].forEach.call(document.querySelectorAll('.devid-ban'),function(b){
    b.onclick=function(){
      if(state.busy)return;
      var group=b.getAttribute('data-h');
      var picked=[].filter.call(document.querySelectorAll('.devid-pick[data-h="'+group+'"]'),
                                function(c){return c.checked;});
      if(!picked.length){msg('هیچ حسابی انتخاب نشده است.','warning');return;}
      var ids=picked.map(function(c){return parseInt(c.getAttribute('data-uid'),10);});
      var mails=picked.map(function(c){return c.getAttribute('data-email');});
      // The confirmation names every account, because the whole risk here is
      // banning someone the operator did not mean to look at.
      if(!window.confirm('این '+ids.length+' حساب مسدود می‌شوند:\n\n'+mails.join('\n')
          +'\n\nاشتراکشان هم قطع می‌شود. ادامه می‌دهید؟'))return;
      state.busy=true;b.disabled=true;
      jpost(API+'/ban',{user_ids:ids,hash:b.getAttribute('data-hash')}).then(function(r){
        state.busy=false;
        if(!r||!r.data){msg(esc((r&&r.message)||'خطا'),'error');b.disabled=false;return;}
        // The undo is offered here and now, not hidden in a history tab.
        msg(r.data.count+' حساب مسدود شد. '
          +'<button class="ant-btn ant-btn-sm devid-revert" data-batch="'+esc(r.data.batch)+'">واگردانی همین عملیات</button>','success');
        wireRevert();
        load();
      }).catch(function(e){state.busy=false;b.disabled=false;msg(esc(e.message),'error')});
    };
  });

  [].forEach.call(document.querySelectorAll('.devid-forget'),function(b){
    b.onclick=function(){
      if(!window.confirm('این دستگاه از فهرست این حساب حذف شود؟\n\n'
        +'توجه: اگر حساب فعال باشد، اپ ظرف حداکثر شش ساعت دوباره آن را ثبت می‌کند. '
        +'این ابزار پاک‌سازی است، نه مسدودسازی.'))return;
      jpost(API+'/forget',{user_id:parseInt(b.getAttribute('data-uid'),10),hash:b.getAttribute('data-hash')})
        .then(function(r){
          if(!r||!r.data){msg(esc((r&&r.message)||'خطا'),'error');return;}
          msg('حذف شد. '+r.data.remaining+' دستگاه باقی ماند.','success');load();
        }).catch(function(e){msg(esc(e.message),'error')});
    };
  });

  wireRevert();

  var go=document.getElementById('devid-go'),q=document.getElementById('devid-q');
  function run(){
    var v=(q&&q.value||'').trim();
    var box=document.getElementById('devid-result');
    if(!v){if(box)box.innerHTML='';return;}
    box.innerHTML='<div class="text-muted p-2" style="font-size:12px">در حال جست‌وجو…</div>';
    j(API+'/lookup?q='+encodeURIComponent(v)).then(function(r){
      if(!r||!r.data){box.innerHTML='<div class="text-danger p-2">'+esc((r&&r.message)||'خطا')+'</div>';return;}
      var acc=r.data.accounts||[];
      box.innerHTML='<div class="block block-rounded block-bordered"><div class="block-content">'
        +'<div class="text-muted mb-2" style="font-size:12px">'+acc.length+' اکانت برای این جست‌وجو</div>'
        +(acc.length?renderList({data:acc,total:acc.length}):'')+'</div></div>';
      wire();
    }).catch(function(e){box.innerHTML='<div class="text-danger p-2">'+esc(e.message)+'</div>'});
  }
  if(go)go.onclick=run;
  if(q)q.onkeydown=function(e){if(e.key==='Enter'){e.preventDefault();run();}};
}

function wireRevert(){
  [].forEach.call(document.querySelectorAll('.devid-revert'),function(b){
    b.onclick=function(){
      if(!window.confirm('این عملیات برگردانده شود؟ هر حساب به وضعیت قبل از مسدودسازی بازمی‌گردد.'))return;
      b.disabled=true;
      jpost(API+'/revert',{batch:b.getAttribute('data-batch')}).then(function(r){
        if(!r||!r.data){msg(esc((r&&r.message)||'خطا'),'error');b.disabled=false;return;}
        msg(r.data.restored+' حساب به وضعیت قبلی برگردانده شد.','success');load();
      }).catch(function(e){b.disabled=false;msg(esc(e.message),'error')});
    };
  });
}

function load(){
  var mc=document.getElementById('main-container');
  if(!mc)return;
  var url=state.tab==='shared'?(API+'/shared')
        :state.tab==='bans'?(API+'/bans')
        :(API+'/fetch?pageSize=50');
  j(url).then(function(r){
    if(!r||!r.data){mc.innerHTML=dvWrap('<div class="text-danger text-center p-5">'+esc((r&&r.message)||'خطا در بارگذاری')+'</div>');return;}
    var inner=state.tab==='shared'?renderShared(r):state.tab==='bans'?renderBans(r):renderList(r);
    mc.innerHTML=dvWrap(shell(inner));
    wire();
  }).catch(function(e){mc.innerHTML=dvWrap('<div class="text-danger text-center p-5">'+esc(e.message)+'</div>')});
}

/* ---------- the injection race (same design as the Group Pricing fix) --- */
function dvHere(){return location.hash.indexOf('/device-identity')>-1;}
function dvBox(){return document.getElementById('main-container');}
// The marker IS the latch: a boolean cannot know React re-rendered over us.
// load()'s own write is wrapped in it too, so a finished page counts as ours.
function dvWrap(html){return '<div data-devid-root="1">'+html+'</div>';}
function dvMine(mc){return !!(mc&&mc.querySelector('[data-devid-root]'));}
var dvBurst=0,dvWindow=0;
function inject(){
  if(!dvHere()){injected=false;return;}
  var mc=dvBox();
  if(!mc)return;
  if(dvMine(mc))return;
  // A budget, not a cooldown: React clobbers the container immediately after
  // we write, so recovery must be allowed at once. A cooldown blocks exactly
  // the case this exists for - that was proven on the Group Pricing tab.
  var now=Date.now();
  if(now-dvWindow>3000){dvWindow=now;dvBurst=0;}
  if(dvBurst>=3)return;
  dvBurst++;
  injected=true;
  mc.innerHTML=dvWrap('<div class="text-center text-muted p-5">⏳ در حال بارگذاری…</div>');
  load();
}
var dvTimer=null;
function dvSoon(){if(dvTimer)return;dvTimer=setTimeout(function(){dvTimer=null;inject();},60);}
new MutationObserver(function(){
  if(dvHere()){dvSoon()}else{injected=false}
}).observe(document.body,{childList:true,subtree:true});
window.addEventListener('hashchange',function(){setTimeout(inject,120);setTimeout(inject,400);});
setTimeout(inject,900);
console.log('\u{1F4F1} Device Identity Admin v2.0');
})();
""" + END


def object_at(src: str, anchor: str):
    """Slice the balanced {...} object containing `anchor`.

    Walking the braces rather than matching a pattern is the rule here: the
    menu entries are formatted inconsistently (some expanded over lines, some
    compressed onto one), so only the brace depth identifies the boundaries.
    """
    at = src.find(anchor)
    if at < 0:
        return None
    start = src.rfind("{", 0, at)
    if start < 0:
        return None
    depth = 0
    i = start
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[start:i + 1]
        i += 1
    return None


def patch(path: Path) -> str:
    with path.open("r", encoding="utf-8", newline="") as fh:
        src = fh.read()

    out = src
    notes = []

    # --- assertions, each one a failure that has already happened here -----
    if out.count(MENU_ANCHOR) != 1:
        return f"ABORT: menu anchor found {out.count(MENU_ANCHOR)}x (expected 1)"
    if out.count(ROUTE_ANCHOR) != 1:
        return f"ABORT: route anchor found {out.count(ROUTE_ANCHOR)}x (expected 1)"

    menu_obj = object_at(out, MENU_ANCHOR)
    route_obj = object_at(out, ROUTE_ANCHOR)
    if not menu_obj:
        return "ABORT: could not slice the menu object"
    if not route_obj:
        return "ABORT: could not slice the route object"
    if out.count(menu_obj) != 1:
        return "ABORT: sliced menu object is not unique"
    if out.count(route_obj) != 1:
        return "ABORT: sliced route object is not unique"
    # The placeholder component must still exist and be the shared one.
    if 'n("pi3A").default' not in route_obj:
        return "ABORT: the route template no longer uses the pi3A placeholder"

    # --- 1. menu entry -----------------------------------------------------
    if '"/device-identity"' not in out:
        out = out.replace(menu_obj, menu_obj + ", " + MENU_NEW, 1)
        notes.append("menu added")
        # re-slice: offsets moved
        route_obj = object_at(out, ROUTE_ANCHOR)

    # --- 2. route row ------------------------------------------------------
    if 'path: "/device-identity"' not in out:
        out = out.replace(route_obj, route_obj + ", " + ROUTE_NEW, 1)
        notes.append("route added")

    # --- 3. the tab body, fenced so a re-run replaces it -------------------
    if START in out:
        a = out.find(START)
        b = out.find(END, a)
        if b < 0:
            return "ABORT: fenced block has no end marker"
        b += len(END)
        if out[a:b] != BLOCK:
            out = out[:a] + BLOCK + out[b:]
            notes.append("block replaced")
    else:
        if not out.endswith("\n"):
            out += "\n"
        out = out + BLOCK + "\n"
        notes.append("block appended")

    if out == src:
        return "already current - no change"

    shutil.copyfile(path, path.with_suffix(path.suffix + ".prepatch"))
    with path.open("w", encoding="utf-8", newline="") as fh:
        fh.write(out)
    return f"{', '.join(notes)} ({len(out) - len(src):+d} chars)"


def main() -> int:
    rc = 0
    for name in TARGETS:
        p = ADMIN / name
        if not p.exists():
            print(f"{name}: MISSING")
            rc = 1
            continue
        result = patch(p)
        print(f"{name}: {result}")
        if result.startswith("ABORT"):
            rc = 1
    return rc


if __name__ == "__main__":
    sys.exit(main())

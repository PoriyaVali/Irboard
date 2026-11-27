#!/usr/bin/env python3
"""Add the digital products page to the web admin.

Products (Apple IDs, gift cards, servers, ...) are sold through the app and
the store: see app/Services/ProductOrderService.php and
docs/digital-products.md. This adds to both prebuilt admin bundles:

  * a "Digital products" menu entry and its /products route (the shared
    placeholder component, like the other injected pages);
  * the page: products with their stock, a create/edit form with a field
    editor and ready-made presets, bulk stock import, the units of a
    product, and the orders waiting to be delivered by hand.

Everything the API returns goes into the page through textContent or an
escaping helper, never as markup. All other bytes, including local operator
edits, are preserved. A missing or repeated anchor aborts BEFORE writing
either file. Re-running is idempotent; --check verifies without writing.

Usage:
  python scripts/patch_admin_products.py [--check] [bundle ...]
"""

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"

MENU_ANCHOR = (
    'title: "سفارشات کارت به کارت",\n'
    '                        type: "item",\n'
    '                        href: "/card-orders",\n'
    '                        icon: o.a.createElement("i", {\n'
    '                            className: "nav-main-link-icon si si-credit-card"\n'
    '                        })\n'
    '                    }'
)
ROUTE_ANCHOR = (
    'path: "/card-orders",\n'
    '            exact: !0,\n'
    '            component: n("pi3A").default\n'
    '        }'
)

MENU = (
    '/*__DPR_MENU_START__*/, {title: "محصولات دیجیتال", type: "item", href: "/products", '
    'icon: o.a.createElement("i", {className: "nav-main-link-icon si si-bag"})}/*__DPR_MENU_END__*/'
)
ROUTE = (
    '/*__DPR_ROUTE_START__*/, {path: "/products", exact: !0, component: n("pi3A").default}/*__DPR_ROUTE_END__*/'
)

PAGE = r"""/*__DPR_PAGE_START__*/
;(function(){
var injected=false,tab="products",products=[];
function api(){return "/api/v1/"+window.settings.secure_path+"/product/"}
function tok(){return localStorage.getItem("authorization")||""}
function esc(v){return String(v==null?"":v).replace(/[&<>"']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]})}
function money(v){return Number(v||0).toLocaleString("fa-IR")+" تومان"}
function when(t){return t?new Date(t*1000).toLocaleString("fa-IR",{timeZone:"Asia/Tehran"}):"—"}
function get(path){return fetch(api()+path,{headers:{"Authorization":tok()}}).then(function(r){return r.json()})}
function post(path,body){return fetch(api()+path,{method:"POST",headers:{"Authorization":tok(),"Content-Type":"application/json"},body:JSON.stringify(body)}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j&&j.message||"خطا");return j})})}
var DELIVERY={stock:"از موجودی (خودکار)",manual:"دستی توسط ادمین",unlimited:"نامحدود (محتوای ثابت)"};
var TYPES={text:"متن",secret:"محرمانه",date:"تاریخ",link:"لینک",note:"یادداشت"};
var PRESETS={
 "اپل آیدی":{category:"apple-id",delivery:"stock",fields:[["email","ایمیل","text"],["password","رمز عبور","secret"],["birthday","تاریخ تولد","date"],["questions","سؤال‌های امنیتی","secret"],["country","کشور","text"]]},
 "گیفت کارت":{category:"gift-card",delivery:"stock",fields:[["code","کد","secret"],["amount","مبلغ","text"],["region","منطقه","text"]]},
 "سرور مجازی":{category:"vps",delivery:"manual",fields:[["ip","آی‌پی","text"],["username","نام کاربری","text"],["password","رمز عبور","secret"],["port","پورت","text"],["os","سیستم‌عامل","text"],["expires","تاریخ انقضا","date"]]},
 "سرور اختصاصی":{category:"dedicated",delivery:"manual",fields:[["ip","آی‌پی","text"],["panel","پنل مدیریت","link"],["username","نام کاربری","text"],["password","رمز عبور","secret"],["expires","تاریخ انقضا","date"]]},
 "فایل / لینک":{category:"file",delivery:"unlimited",fields:[["link","لینک دانلود","link"],["note","توضیح","note"]]}
};
function css(){if(document.getElementById("dpr-style"))return;var s=document.createElement("style");s.id="dpr-style";
s.textContent=".dpr{direction:rtl}.dpr-tabs{display:flex;gap:4px;border-bottom:1px solid #eee;margin-bottom:14px;flex-wrap:wrap}.dpr-tab{padding:10px 14px;cursor:pointer;color:#888;border-bottom:2px solid transparent}.dpr-tab.on{color:#6c5ce7;border-bottom-color:#6c5ce7;font-weight:600}.dpr-card{border:1px solid #eee;border-radius:10px;padding:14px;margin-bottom:10px;background:#fafafa}.dpr-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}.dpr-row{display:flex;justify-content:space-between;font-size:13px;color:#555;padding:3px 0;gap:8px}.dpr-chip{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;background:#eef;color:#446;margin-left:4px}.dpr-chip.low{background:#fde2e2;color:#a33}.dpr-chip.off{background:#eee;color:#888}.dpr-btn{padding:7px 12px;border:none;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;background:#6c5ce7;color:#fff;margin:2px}.dpr-btn.g{background:#27ae60}.dpr-btn.r{background:#e74c3c}.dpr-btn.l{background:#eee;color:#333}.dpr-modal{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:24px 12px;overflow:auto}.dpr-box{background:#fff;border-radius:12px;max-width:760px;width:100%;padding:18px;direction:rtl}.dpr-box label{display:block;font-size:12px;color:#666;margin:8px 0 3px}.dpr-box input,.dpr-box select,.dpr-box textarea{width:100%;padding:7px 9px;border:1px solid #ddd;border-radius:7px;font-size:13px}.dpr-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.dpr-f{display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:6px;margin-bottom:6px}.dpr-mono{direction:ltr;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all}.dpr-empty{text-align:center;color:#999;padding:30px}@media(max-width:600px){.dpr-grid,.dpr-f{grid-template-columns:1fr}}";
document.head.appendChild(s)}
function modal(title){var o=document.createElement("div");o.className="dpr-modal";var b=document.createElement("div");b.className="dpr-box";var h=document.createElement("h4");h.textContent=title;b.appendChild(h);o.appendChild(b);o.onclick=function(e){if(e.target===o)o.remove()};document.body.appendChild(o);return {o:o,b:b}}
function note(el,msg,ok){var p=document.createElement("div");p.style.cssText="padding:8px;border-radius:7px;margin:8px 0;font-size:13px;background:"+(ok?"#e7f7ec;color:#1e7b3a":"#fdecea;color:#a33");p.textContent=msg;el.appendChild(p);setTimeout(function(){p.remove()},6000)}

function inject(){
 if(injected||!location.hash.includes("/products"))return;
 var area=document.getElementById("main-container");if(!area)return;
 injected=true;css();area.innerHTML="";
 var box=document.createElement("div");box.className="block block-rounded dpr";box.style.marginTop="20px";
 box.innerHTML='<div class="block-header block-header-default" style="background:linear-gradient(135deg,#6c5ce7,#a855f7);border-radius:5px 5px 0 0"><h3 class="block-title" style="color:#fff">🛍 محصولات دیجیتال</h3><button class="dpr-btn g" id="dprNew" type="button">+ محصول جدید</button></div><div class="block-content"><div class="dpr-tabs" id="dprTabs"><div class="dpr-tab" data-t="products">محصولات</div><div class="dpr-tab" data-t="waiting">منتظر تحویل</div><div class="dpr-tab" data-t="delivered">تحویل‌شده</div></div><div id="dprBody"></div></div>';
 area.appendChild(box);
 document.getElementById("dprNew").onclick=function(){editProduct(null)};
 Array.prototype.forEach.call(document.querySelectorAll("#dprTabs .dpr-tab"),function(t){t.onclick=function(){tab=t.dataset.t;show()}});
 show();
}
function show(){
 Array.prototype.forEach.call(document.querySelectorAll("#dprTabs .dpr-tab"),function(t){t.classList.toggle("on",t.dataset.t===tab)});
 if(tab==="products")listProducts();else listDeliveries(tab==="waiting"?0:1);
}
function body(){return document.getElementById("dprBody")}

function listProducts(){
 var el=body();if(!el)return;el.innerHTML='<div class="dpr-empty">در حال بارگذاری…</div>';
 get("fetch").then(function(j){
  products=j.data||[];el.innerHTML="";
  if(!products.length){el.innerHTML='<div class="dpr-empty">هنوز محصولی نیست. با «محصول جدید» شروع کنید.</div>';return}
  products.forEach(function(p){
   var c=document.createElement("div");c.className="dpr-card";
   var st=p.stock||{};
   var stock=p.delivery==="stock"?('موجود: <b>'+st.available+'</b> · رزرو: '+st.reserved+' · فروخته: '+st.sold):esc(DELIVERY[p.delivery]||p.delivery);
   c.innerHTML='<div class="dpr-head"><div><b>'+esc(p.name)+'</b> '+(p.category?'<span class="dpr-chip">'+esc(p.category)+'</span>':'')+(p.show?'':'<span class="dpr-chip off">مخفی</span>')+(p.low?'<span class="dpr-chip low">موجودی کم</span>':'')+(p.waiting?'<span class="dpr-chip low">'+Number(p.waiting)+' منتظر تحویل</span>':'')+'</div><b>'+money(p.price)+'</b></div>'+
    '<div class="dpr-row"><span>تحویل</span><span>'+esc(DELIVERY[p.delivery]||p.delivery)+'</span></div><div class="dpr-row"><span>موجودی</span><span>'+stock+'</span></div>'+
    '<div class="dpr-row"><span>فیلدها</span><span>'+esc((p.fields||[]).map(function(f){return f.label}).join("، "))+'</span></div>';
   var a=document.createElement("div");a.style.marginTop="8px";
   var e=document.createElement("button");e.className="dpr-btn";e.type="button";e.textContent="ویرایش";e.onclick=function(){editProduct(p)};a.appendChild(e);
   if(p.delivery==="stock"){var s=document.createElement("button");s.className="dpr-btn g";s.type="button";s.textContent="موجودی";s.onclick=function(){stockOf(p)};a.appendChild(s)}
   var d=document.createElement("button");d.className="dpr-btn r";d.type="button";d.textContent="حذف";d.onclick=function(){if(!confirm("حذف «"+p.name+"»؟ اگر فروش داشته باشد فقط مخفی می‌شود."))return;post("drop",{id:p.id}).then(function(j){if(j.message)alert(j.message);listProducts()}).catch(function(err){alert(err.message)})};a.appendChild(d);
   c.appendChild(a);el.appendChild(c);
  });
 }).catch(function(){el.innerHTML='<div class="dpr-empty">دریافت ناموفق بود (آیا دستور به‌روزرسانی پنل اجرا شده؟)</div>'});
}

function fieldRow(wrap,f){
 var r=document.createElement("div");r.className="dpr-f";
 var lb=document.createElement("input");lb.placeholder="عنوان (مثلاً ایمیل)";lb.value=f?f.label:"";lb.className="dpr-label";
 var ky=document.createElement("input");ky.placeholder="کلید (email)";ky.value=f?f.key:"";ky.className="dpr-key";ky.style.direction="ltr";
 var tp=document.createElement("select");tp.className="dpr-type";Object.keys(TYPES).forEach(function(k){var o=document.createElement("option");o.value=k;o.textContent=TYPES[k];tp.appendChild(o)});tp.value=f?f.type:"text";
 var rm=document.createElement("button");rm.className="dpr-btn r";rm.type="button";rm.textContent="✕";rm.onclick=function(){r.remove()};
 r.appendChild(lb);r.appendChild(ky);r.appendChild(tp);r.appendChild(rm);wrap.appendChild(r);
}
function readFields(wrap){var out=[];Array.prototype.forEach.call(wrap.querySelectorAll(".dpr-f"),function(r){var l=r.querySelector(".dpr-label").value.trim();if(!l)return;out.push({label:l,key:r.querySelector(".dpr-key").value.trim(),type:r.querySelector(".dpr-type").value})});return out}

function editProduct(p){
 var m=modal(p?"ویرایش محصول":"محصول جدید"),b=m.b;
 var pre=document.createElement("div");pre.style.margin="4px 0 8px";var pt=document.createElement("span");pt.textContent="الگوی آماده: ";pt.style.fontSize="12px";pre.appendChild(pt);
 var form=document.createElement("div");
 form.innerHTML='<div class="dpr-grid"><div><label>نام</label><input id="dpN"></div><div><label>دسته (مثلاً apple-id)</label><input id="dpC" style="direction:ltr"></div><div><label>قیمت (تومان)</label><input id="dpP" type="number" min="0" style="direction:ltr"></div><div><label>نوع تحویل</label><select id="dpD"></select></div><div><label>تصویر (https)</label><input id="dpI" style="direction:ltr"></div><div><label>ترتیب نمایش</label><input id="dpS" type="number" style="direction:ltr"></div><div><label>سقف خرید هر کاربر (خالی = نامحدود)</label><input id="dpL" type="number" min="0" style="direction:ltr"></div><div><label>هشدار موجودی کم در</label><input id="dpW" type="number" min="0" style="direction:ltr"></div></div><label>توضیحات</label><textarea id="dpX" rows="3"></textarea><label><input type="checkbox" id="dpV" style="width:auto"> نمایش در فروشگاه</label><label>فیلدهای هر واحد</label><div id="dpF"></div><button class="dpr-btn l" type="button" id="dpFA">+ فیلد</button><div id="dpU" style="display:none"><label>محتوای تحویل (برای همه خریداران)</label><div id="dpUC"></div></div>';
 b.appendChild(pre);b.appendChild(form);
 var D=form.querySelector("#dpD");Object.keys(DELIVERY).forEach(function(k){var o=document.createElement("option");o.value=k;o.textContent=DELIVERY[k];D.appendChild(o)});
 var F=form.querySelector("#dpF");
 function contentInputs(){var w=form.querySelector("#dpU"),c=form.querySelector("#dpUC");w.style.display=D.value==="unlimited"?"block":"none";var old={};Array.prototype.forEach.call(c.querySelectorAll("input"),function(i){old[i.dataset.k]=i.value});c.innerHTML="";readFields(F).forEach(function(f){var l=document.createElement("label");l.textContent=f.label;var i=document.createElement("input");i.dataset.k=f.key||f.label;i.value=old[i.dataset.k]||((p&&p.content&&p.content[f.key])||"");c.appendChild(l);c.appendChild(i)})}
 Object.keys(PRESETS).forEach(function(name){var x=document.createElement("button");x.type="button";x.className="dpr-btn l";x.textContent=name;x.onclick=function(){var s=PRESETS[name];if(!form.querySelector("#dpN").value)form.querySelector("#dpN").value=name;form.querySelector("#dpC").value=s.category;D.value=s.delivery;F.innerHTML="";s.fields.forEach(function(f){fieldRow(F,{key:f[0],label:f[1],type:f[2]})});contentInputs()};pre.appendChild(x)});
 form.querySelector("#dpN").value=p?p.name:"";form.querySelector("#dpC").value=p&&p.category||"";form.querySelector("#dpP").value=p?p.price:"";D.value=p?p.delivery:"stock";
 form.querySelector("#dpI").value=p&&p.image||"";form.querySelector("#dpS").value=p?p.sort:0;form.querySelector("#dpL").value=p&&p.per_user_limit||"";form.querySelector("#dpW").value=p?p.low_stock:3;
 form.querySelector("#dpX").value=p&&p.description||"";form.querySelector("#dpV").checked=p?!!p.show:true;
 (p?p.fields:[]).forEach(function(f){fieldRow(F,f)});if(!p)fieldRow(F,null);
 form.querySelector("#dpFA").onclick=function(){fieldRow(F,null)};D.onchange=contentInputs;F.addEventListener("input",function(){if(D.value==="unlimited")contentInputs()});contentInputs();
 var act=document.createElement("div");act.style.marginTop="12px";
 var sv=document.createElement("button");sv.className="dpr-btn g";sv.type="button";sv.textContent="ذخیره";
 var cn=document.createElement("button");cn.className="dpr-btn l";cn.type="button";cn.textContent="انصراف";cn.onclick=function(){m.o.remove()};
 sv.onclick=function(){
  var fields=readFields(F),content={};Array.prototype.forEach.call(form.querySelectorAll("#dpUC input"),function(i){content[i.dataset.k]=i.value});
  var bodyData={name:form.querySelector("#dpN").value,category:form.querySelector("#dpC").value,price:form.querySelector("#dpP").value,delivery:D.value,image:form.querySelector("#dpI").value,description:form.querySelector("#dpX").value,show:form.querySelector("#dpV").checked,sort:form.querySelector("#dpS").value||0,per_user_limit:form.querySelector("#dpL").value,low_stock:form.querySelector("#dpW").value||0,fields:fields,content:content};
  if(p)bodyData.id=p.id;sv.disabled=true;
  post("save",bodyData).then(function(){m.o.remove();listProducts()}).catch(function(err){sv.disabled=false;note(b,err.message,false)});
 };
 act.appendChild(sv);act.appendChild(cn);b.appendChild(act);
}

var STATUS={0:"موجود",1:"رزرو",2:"فروخته",3:"کنار گذاشته"};
function stockOf(p){
 var m=modal("موجودی: "+p.name),b=m.b,page=1,filter="";
 var hint=document.createElement("div");hint.style.cssText="font-size:12px;color:#666";hint.textContent="هر خط یک واحد. مقدارها به ترتیب فیلدها با | یا Tab جدا شوند: "+(p.fields||[]).map(function(f){return f.label}).join(" | ");
 var ta=document.createElement("textarea");ta.rows=6;ta.className="dpr-mono";ta.placeholder=(p.fields||[]).map(function(f){return f.key}).join("|");
 var imp=document.createElement("button");imp.className="dpr-btn g";imp.type="button";imp.textContent="افزودن به موجودی";
 var sel=document.createElement("select");sel.style.cssText="width:auto;margin-top:10px";[["","همه"],["0","موجود"],["1","رزرو"],["2","فروخته"],["3","کنار گذاشته"]].forEach(function(x){var o=document.createElement("option");o.value=x[0];o.textContent=x[1];sel.appendChild(o)});
 var list=document.createElement("div");list.style.marginTop="8px";var more=document.createElement("div");
 b.appendChild(hint);b.appendChild(ta);b.appendChild(imp);b.appendChild(sel);b.appendChild(list);b.appendChild(more);
 imp.onclick=function(){if(!ta.value.trim())return;imp.disabled=true;post("import",{product_id:p.id,text:ta.value}).then(function(j){imp.disabled=false;var d=j.data||{};note(b,"افزوده شد: "+d.added+(d.rejected&&d.rejected.length?" · رد شد (خط): "+d.rejected.join("، "):""),true);ta.value="";page=1;load()}).catch(function(err){imp.disabled=false;note(b,err.message,false)})};
 sel.onchange=function(){filter=sel.value;page=1;load()};
 function load(){
  get("items?product_id="+p.id+"&current="+page+"&pageSize=20"+(filter!==""?"&status="+filter:"")).then(function(j){
   if(page===1)list.innerHTML="";var items=j.data||[];
   if(!items.length&&page===1){list.innerHTML='<div class="dpr-empty">واحدی نیست</div>'}
   items.forEach(function(it){
    var c=document.createElement("div");c.className="dpr-card";
    var h=document.createElement("div");h.className="dpr-head";var t=document.createElement("b");t.textContent="#"+it.id+" · "+(STATUS[it.status]||it.status);h.appendChild(t);
    if(it.email){var e=document.createElement("span");e.style.fontSize="12px";e.textContent=it.email+" · "+(it.trade_no||"");h.appendChild(e)}
    c.appendChild(h);
    var pre=document.createElement("div");pre.className="dpr-mono";pre.textContent=Object.keys(it.data||{}).map(function(k){return k+": "+it.data[k]}).join("\n");c.appendChild(pre);
    if(it.status===0||it.status===3){
     var w=document.createElement("button");w.className="dpr-btn l";w.type="button";w.textContent=it.status===3?"بازگرداندن":"کنار گذاشتن";w.onclick=function(){post("item/update",{id:it.id,withdrawn:it.status!==3}).then(function(){page=1;load()}).catch(function(err){alert(err.message)})};
     var x=document.createElement("button");x.className="dpr-btn r";x.type="button";x.textContent="حذف";x.onclick=function(){if(!confirm("حذف این واحد؟"))return;post("item/drop",{id:it.id}).then(function(){page=1;load()}).catch(function(err){alert(err.message)})};
     c.appendChild(w);c.appendChild(x);
    }
    list.appendChild(c);
   });
   more.innerHTML="";if(page*20<(j.total||0)){var mb=document.createElement("button");mb.className="dpr-btn l";mb.type="button";mb.textContent="بیشتر";mb.onclick=function(){page++;load()};more.appendChild(mb)}
  }).catch(function(){list.innerHTML='<div class="dpr-empty">دریافت ناموفق بود</div>'});
 }
 load();
}

function listDeliveries(status){
 var el=body();if(!el)return;el.innerHTML='<div class="dpr-empty">در حال بارگذاری…</div>';
 get("deliveries?status="+status+"&pageSize=50").then(function(j){
  var rows=j.data||[];el.innerHTML="";
  if(!rows.length){el.innerHTML='<div class="dpr-empty">'+(status===0?"سفارشی منتظر تحویل نیست":"تحویلی ثبت نشده")+'</div>';return}
  rows.forEach(function(d){
   var c=document.createElement("div");c.className="dpr-card";
   c.innerHTML='<div class="dpr-head"><b>'+esc(d.product)+'</b><span class="dpr-chip'+(status===0?' low':'')+'">'+(status===0?"منتظر تحویل":"تحویل‌شده")+'</span></div>'+
    '<div class="dpr-row"><span>کاربر</span><span>'+esc(d.email||d.user_id)+'</span></div><div class="dpr-row"><span>سفارش</span><span style="direction:ltr">'+esc(d.trade_no)+'</span></div>'+
    '<div class="dpr-row"><span>مبلغ</span><span>'+money(d.amount)+'</span></div><div class="dpr-row"><span>پرداخت</span><span>'+esc(when(d.paid_at))+'</span></div>';
   if(status===1){var pre=document.createElement("div");pre.className="dpr-mono";pre.textContent=Object.keys(d.data||{}).map(function(k){return k+": "+d.data[k]}).join("\n");c.appendChild(pre)}
   var btn=document.createElement("button");btn.className="dpr-btn "+(status===0?"g":"l");btn.type="button";btn.textContent=status===0?"تحویل":"ویرایش تحویل";btn.onclick=function(){deliver(d)};c.appendChild(btn);
   el.appendChild(c);
  });
 }).catch(function(){el.innerHTML='<div class="dpr-empty">دریافت ناموفق بود</div>'});
}

function deliver(d){
 var m=modal("تحویل: "+d.product),b=m.b,inputs={};
 (d.fields||[]).forEach(function(f){var l=document.createElement("label");l.textContent=f.label;var i=document.createElement(f.type==="note"?"textarea":"input");i.value=(d.data&&d.data[f.key])||"";if(f.type!=="note")i.style.direction="ltr";inputs[f.key]=i;b.appendChild(l);b.appendChild(i)});
 var nl=document.createElement("label");nl.textContent="پیام برای خریدار (اختیاری)";var nt=document.createElement("textarea");nt.rows=2;nt.value=d.note||"";b.appendChild(nl);b.appendChild(nt);
 var sv=document.createElement("button");sv.className="dpr-btn g";sv.type="button";sv.textContent="ثبت و تحویل";sv.style.marginTop="10px";
 sv.onclick=function(){var data={};Object.keys(inputs).forEach(function(k){data[k]=inputs[k].value});sv.disabled=true;post("deliver",{id:d.id,data:data,note:nt.value}).then(function(){m.o.remove();show()}).catch(function(err){sv.disabled=false;note(b,err.message,false)})};
 b.appendChild(sv);
}

new MutationObserver(function(){if(location.hash.includes("/products")){inject()}else{injected=false}}).observe(document.body,{childList:true,subtree:true});
window.addEventListener("hashchange",function(){injected=false;setTimeout(inject,120)});
setTimeout(inject,600);
console.log("\u{1F6CD} Digital Products Admin v1");
})();
/*__DPR_PAGE_END__*/
"""

FENCES = [
    ("__DPR_MENU_START__", "__DPR_MENU_END__", ""),
    ("__DPR_ROUTE_START__", "__DPR_ROUTE_END__", ""),
    ("__DPR_PAGE_START__", "__DPR_PAGE_END__", "\n"),
]


class PatchError(ValueError):
    pass


def remove_owned(source: str) -> str:
    for start, end, tail in FENCES:
        if source.count(start) != source.count(end) or source.count(start) > 1:
            raise PatchError(f"ambiguous or incomplete {start} fence")
        source = re.sub(
            r"/\*" + start + r"\*/.*?/\*" + end + r"\*/" + re.escape(tail), "", source, count=1, flags=re.DOTALL
        )
    return source


def patch(source: str) -> str:
    for start, end, _ in FENCES:
        if source.count(start) != source.count(end) or source.count(start) > 1:
            raise PatchError(f"ambiguous or incomplete {start} fence")
    # The page: replaced where it is (later patches may have appended after
    # it), appended the first time.
    begin, finish = "/*__DPR_PAGE_START__*/", "/*__DPR_PAGE_END__*/\n"
    if begin in source:
        a = source.find(begin)
        b = source.find(finish, a)
        if b < 0:
            raise PatchError("page fence has no end marker")
        source = source[:a] + PAGE + source[b + len(finish):]
    else:
        if not source.endswith("\n"):
            source += "\n"
        source = source + PAGE
    # Menu entry and route: removed and put back right after their anchors.
    base = remove_owned_inline(source)
    for anchor, name in ((MENU_ANCHOR, "menu"), (ROUTE_ANCHOR, "route")):
        if base.count(anchor) != 1:
            raise PatchError(f"{name} anchor occurs {base.count(anchor)} times; expected exactly one")
    base = base.replace(MENU_ANCHOR, MENU_ANCHOR + MENU, 1)
    return base.replace(ROUTE_ANCHOR, ROUTE_ANCHOR + ROUTE, 1)


def remove_owned_inline(source: str) -> str:
    for start, end, tail in FENCES[:2]:
        source = re.sub(r"/\*" + start + r"\*/.*?/\*" + end + r"\*/", "", source, count=1, flags=re.DOTALL)
    return source


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="verify the bundles already have the page; do not write")
    parser.add_argument("bundles", nargs="*", type=Path)
    args = parser.parse_args()
    bundles = args.bundles or [ADMIN / "umi.js", ADMIN / "umi-fa.js"]

    planned = []
    for bundle in bundles:
        original = bundle.read_bytes()
        try:
            output = patch(original.decode("utf-8")).encode("utf-8")
        except (UnicodeDecodeError, PatchError) as error:
            print(f"{bundle}: {error}", file=sys.stderr)
            return 1
        planned.append((bundle, original, output))

    report = []
    for bundle, original, output in planned:
        changed = original != output
        if args.check and changed:
            print(f"{bundle}: digital products page missing or out of date", file=sys.stderr)
            return 1
        if changed and not args.check:
            bundle.write_bytes(output)
        report.append({"bundle": str(bundle), "changed": changed,
                       "before_sha256": hashlib.sha256(original).hexdigest(),
                       "after_sha256": hashlib.sha256(output).hexdigest(),
                       "added_bytes": len(output) - len(original)})
    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())

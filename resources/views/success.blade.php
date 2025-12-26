<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>پرداخت موفق</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Tahoma;background:#10b981;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:15px}
.c{background:#fff;border-radius:15px;padding:25px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3);width:100%;max-width:380px;min-width:280px}
.i{font-size:45px;margin-bottom:15px;color:#10b981;animation:bounce 2s infinite}
.t{font-size:22px;color:#10b981;margin-bottom:10px;font-weight:bold}
.m{font-size:14px;color:#666;margin-bottom:18px;line-height:1.4}
.d{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:15px;margin-bottom:15px;font-size:13px}
.r{display:flex;justify-content:space-between;margin-bottom:6px;align-items:center}
.r:last-child{margin-bottom:0}
.l{color:#777;font-weight:500}
.v{font-weight:bold;color:#333}
.s{color:#10b981!important}
.p{background:#f1f5f9;border-radius:6px;padding:8px;margin-bottom:8px;position:relative;overflow:hidden}
.pb{background:linear-gradient(90deg,#10b981,#34d399);height:6px;border-radius:3px;width:0%;transition:width 1s ease;position:relative}
.pb::after{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.6),transparent);animation:shimmer 2s infinite}
.pt{font-size:12px;color:#10b981;font-weight:bold;margin-bottom:12px}
.cd{font-size:32px;color:#10b981;font-weight:bold;margin-bottom:6px;animation:pulse 1s infinite}
.ct{font-size:12px;color:#666;margin-bottom:15px}
.b{background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;padding:14px;border:none;border-radius:8px;font-size:14px;font-weight:bold;cursor:pointer;width:100%;text-decoration:none;display:block;transition:all 0.3s ease}
.b:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(52,152,219,0.3)}
.b:active{transform:translateY(0)}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.7}}
@keyframes shimmer{0%{left:-100%}100%{left:100%}}
@media(max-width:480px){body{padding:10px}.c{padding:20px;border-radius:12px;max-width:100%}.i{font-size:40px}.t{font-size:20px}.cd{font-size:28px}}
@media(min-width:481px) and (max-width:768px){.c{max-width:420px;padding:30px}}
@media(min-width:769px){.c{max-width:450px;padding:35px}.i{font-size:50px}.t{font-size:24px}}
</style>
</head>
<body>
<div class="c">
<div class="i">✓</div>
<h1 class="t">پرداخت موفق</h1>
<p class="m">پرداخت با موفقیت انجام شد و سرویس فعال می‌شود.</p>
<div class="d">
<div class="r"><span class="l">تاریخ:</span><span class="v" id="d"></span></div>
<div class="r"><span class="l">زمان:</span><span class="v" id="t"></span></div>
<div class="r"><span class="l">وضعیت:</span><span class="v s">موفق</span></div>
<div class="r"><span class="l">کد تأیید:</span><span class="v" id="c"></span></div>
</div>
<div class="p">
<div class="pb" id="pb"></div>
</div>
<div class="pt" id="pt">فعال‌سازی سرویس: 0%</div>
<div class="cd" id="cd">5</div>
<div class="ct">ثانیه تا انتقال به داشبورد</div>
<a href="https://drmobjay.com/index2332.html#/dashboard" class="b">🏠 رفتن به داشبورد</a>
</div>
<script>
let n=new Date(),cd=5,p=0;
document.getElementById('d').textContent=n.getFullYear()+'/'+(n.getMonth()+1).toString().padStart(2,'0')+'/'+n.getDate().toString().padStart(2,'0');
document.getElementById('t').textContent=n.getHours().toString().padStart(2,'0')+':'+n.getMinutes().toString().padStart(2,'0');
document.getElementById('c').textContent='SUC-'+Date.now().toString().slice(-6);
setInterval(()=>{cd--;p+=20;document.getElementById('cd').textContent=cd;document.getElementById('pb').style.width=p+'%';document.getElementById('pt').textContent='فعال‌سازی سرویس: '+p+'%';if(cd<=0)location.href='https://drmobjay.com/index2332.html#/dashboard'},1000);
document.addEventListener('keydown',e=>{if(e.key==='Escape')location.href='https://drmobjay.com/index2332.html#/dashboard'});
</script>
</body>
</html>
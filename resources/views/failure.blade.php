<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>خطا در پرداخت</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Tahoma;background:#e74c3c;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:15px}
.c{background:#fff;border-radius:15px;padding:25px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3);width:100%;max-width:380px;min-width:280px}
.i{font-size:45px;margin-bottom:15px;animation:shake 0.5s ease-in-out}
.t{font-size:22px;color:#e74c3c;margin-bottom:10px;font-weight:bold}
.m{font-size:14px;color:#666;margin-bottom:18px;line-height:1.4}
.d{background:#f8f8f8;border-radius:8px;padding:15px;margin-bottom:18px;font-size:13px}
.r{display:flex;justify-content:space-between;margin-bottom:6px;align-items:center}
.r:last-child{margin-bottom:0}
.l{color:#777;font-weight:500}
.v{font-weight:bold;color:#333}
.e{color:#e74c3c!important}
.cd{font-size:32px;color:#e74c3c;font-weight:bold;margin-bottom:6px;animation:pulse 1s infinite}
.ct{font-size:12px;color:#666;margin-bottom:15px}
.b{background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;padding:14px;border:none;border-radius:8px;font-size:14px;font-weight:bold;cursor:pointer;width:100%;text-decoration:none;display:block;transition:all 0.3s ease}
.b:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(52,152,219,0.3)}
.b:active{transform:translateY(0)}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.6}}
@media(max-width:480px){body{padding:10px}.c{padding:20px;border-radius:12px;max-width:100%}.i{font-size:40px}.t{font-size:20px}.cd{font-size:28px}}
@media(min-width:481px) and (max-width:768px){.c{max-width:420px;padding:30px}}
@media(min-width:769px){.c{max-width:450px;padding:35px}.i{font-size:50px}.t{font-size:24px}}
</style>
</head>
<body>
<div class="c">
<div class="i">❌</div>
<h1 class="t">پرداخت ناموفق</h1>
<p class="m">پرداخت انجام نشد. لطفاً دوباره تلاش کنید.</p>
<div class="d">
<div class="r"><span class="l">تاریخ:</span><span class="v" id="d"></span></div>
<div class="r"><span class="l">زمان:</span><span class="v" id="t"></span></div>
<div class="r"><span class="l">وضعیت:</span><span class="v e">ناموفق</span></div>
<div class="r"><span class="l">کد خطا:</span><span class="v" id="c"></span></div>
</div>
<div class="cd" id="cd">5</div>
<div class="ct">ثانیه تا بازگشت خودکار</div>
<a href="https://drmobjay.com/index2332.html#/dashboard/store" class="b">🛒 بازگشت به فروشگاه</a>
</div>
<script>
let n=new Date(),cd=5;
document.getElementById('d').textContent=n.getFullYear()+'/'+(n.getMonth()+1).toString().padStart(2,'0')+'/'+n.getDate().toString().padStart(2,'0');
document.getElementById('t').textContent=n.getHours().toString().padStart(2,'0')+':'+n.getMinutes().toString().padStart(2,'0');
document.getElementById('c').textContent='ERR-'+Date.now().toString().slice(-6);
setInterval(()=>{cd--;document.getElementById('cd').textContent=cd;if(cd<=0)location.href='https://drmobjay.com/index2332.html#/dashboard/store'},1000);
document.addEventListener('keydown',e=>{if(e.key==='Escape')location.href='https://drmobjay.com/index2332.html#/dashboard/store'});
</script>
</body>
</html>
# پروتکل‌های جدید در مدیریت نود

پروتکل‌های زیر به **همان منوی + در صفحهٔ مدیریت نودها** اضافه شده‌اند:

| پروتکل | حالت‌ها / نام‌های مرتبط |
| --- | --- |
| ShadowsocksR | SSR |
| SOCKS5 | TLS و UDP |
| HTTP | HTTPS با TLS |
| Snell | نسخه‌های ۱ تا ۵ |
| WireGuard | AmneziaWG با تنظیمات مربوط |
| ShadowQUIC | |
| GOST Relay | |
| SSH | رمز یا کلید خصوصی |
| Mieru | TCP / UDP |
| Sudoku | |
| MASQUE | |
| OpenVPN | |
| Tailscale | Headscale با control-url |
| ZeroTier | |
| EasyTier | |

مبنای مقایسه، [parser رسمی Mihomo v1.19.33](https://github.com/MetaCubeX/mihomo/blob/v1.19.33/adapter/parser.go)
با commit `3f27ef39f8c96bbfbc8b0b9bb10f5c9ef60149d8` است. Shadowsocks، VMess،
VLESS، Trojan، Hysteria 1/2، TUIC، AnyTLS و TrustTunnel قبلاً در پنل وجود داشتند.
گزینه‌های `direct`، `dns`، `reject` و `rematch` عمل مسیریابی محلی هستند و می‌توانند
در قالب سفارشی Clash تنظیم شوند.

## استفاده

1. ارتقای معمول پنل و دیتابیس را با مسیر موجود (`php artisan v2board:update`) انجام دهید.
2. در **مدیریت نودها** روی **+** بزنید و پروتکل دلخواه را از همان منوی نودهای قبلی انتخاب کنید.
3. نام، گروه دسترسی، آدرس اتصال، پورت اتصال، پورت سمت سرور و ضریب را در فرم معمول نود وارد کنید.
   پروتکل‌های overlay می‌توانند بدون آدرس/پورت مستقیم و با تنظیمات شبکهٔ خودشان تعریف شوند.
4. فیلدهای اختصاصی همان پروتکل را کامل کنید. تنظیمات پیشرفتهٔ تو در تو در بخش اختیاری JSON قابل تنظیم است.
5. نود در **همان جدول مدیریت نودها** ظاهر می‌شود و ویرایش، نمایش/پنهان‌کردن، کپی، حذف و ترتیب آن از همان‌جا انجام می‌شود.
   کپی جدید همیشه مخفی ساخته می‌شود.

خروجی اشتراک از `flag=mihomo`، `flag=meta`، `flag=verge` یا `flag=nyanpasu`
پشتیبانی می‌کند. نام و آدرس و پورت خروجی با اطلاعات ذخیره‌شدهٔ نود هماهنگ است.
مقادیر متنی `{{uuid}}` و `{{user_id}}` در تنظیمات اختصاصی برای هر مشترک جایگزین
می‌شوند. قالب اطلاعات حجم و تاریخ انقضا هم نام‌های معتبر و مجزا تولید می‌کند.

**دامنهٔ این تغییر پنل Irboard و سابسکریپشن است.** پیاده‌سازی این پروتکل‌ها در
هستهٔ سرور V2bX، دریافت کاربر/تنظیمات نود و گزارش مصرف آن‌ها مرحلهٔ بعد است؛ در
این تغییر فایلی از V2bX ویرایش نمی‌شود. تا آن مرحله، نود جدید گزارش سلامت/مصرف
از V2bX ندارد. انتخاب پروتکل در پنل به‌تنهایی سرویس سمت سرور را راه‌اندازی نمی‌کند.

## Implementation and compatibility

The shared `v2_server_mihomo` table stores common node fields and each selected
protocol's nested `outbound_settings`. The native management endpoint returns
these nodes together with existing protocols. Native mixed sorting, group counts,
group-deletion guards and subscription group filtering include them. There is
no separate profile-management section.

The panel validates common fields and required protocol settings. Advanced
options retain their original JSON types and credential whitespace. For protocols
with endpoints, the form's common host and port are authoritative. Full option
examples are in [Mihomo's reference config](https://github.com/MetaCubeX/mihomo/blob/v1.19.33/docs/config.yaml).
Use a compatible Mihomo build and verify complete subscriptions with
`mihomo -t -f subscription.yaml`; older cores may reject new types. Other client
exporters keep their supported protocol set.

Existing TrustTunnel nodes now export to Mihomo using UUID for both username and
password, matching V2bX. Their certificate hostname/custom SNI, self-signed TLS
mode and explicit HTTP/3 choice are preserved.

The upgrade creates the new table idempotently. Existing subscriptions continue
working if the code is updated before the schema; new protocol saves report that
an upgrade is needed. Server heartbeat monitoring skips these new protocols until
server-core integration exists.

## Verification

```sh
python3 scripts/patch_admin_mihomo_nodes.py --check
vendor/bin/phpunit --filter 'MihomoNodesTest|MihomoSubscriptionTest'
node --test tests/AdminMihomoNodesBrowserTest.cjs
IRBOARD_MIHOMO_CONTRACT_DIR=/tmp/mihomo-contract vendor/bin/phpunit --filter MihomoSubscriptionTest
python3 tests/ValidateMihomoConfig.py --binary /path/to/mihomo --profiles /tmp/mihomo-contract/ClashMeta.json
```

Browser checks exercise the actual Add Node menu and forms in both shipped admin
bundles. Core checks parse all 15 added types and TrustTunnel with the pinned
v1.19.33 binary and disposable test keys. They do not assert live VPN connectivity
or a V2bX implementation for these types.

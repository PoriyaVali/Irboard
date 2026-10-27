# پروتکل‌های جدید در مدیریت نود Irboard

۱۵ پروتکل زیر در **همان منوی + و جدول مدیریت نودها** تعریف می‌شوند. مبنا،
[parser رسمی Mihomo v1.19.33](https://github.com/MetaCubeX/mihomo/blob/v1.19.33/adapter/parser.go)
در commit `3f27ef39f8c96bbfbc8b0b9bb10f5c9ef60149d8` و ساختار Option هر پروتکل است.
Shadowsocks، VMess، VLESS، Trojan، Hysteria 1/2، TUIC، AnyTLS و TrustTunnel از قبل
وجود داشتند. `direct`، `dns`، `reject` و `rematch` عمل مسیریابی هستند.

## جدول‌ها و فیلدهای اختصاصی

هر ردیف زیر مدل Eloquent و کنترلر مستقل و مسیر
`/server/<type>/{save,update,copy,drop}` دارد. مکانیزم‌های مشترک CRUD در
`ProtocolNodeController` و نگاشت دقیق گزینه‌ها در `ProtocolRegistry` قرار دارند.

| پروتکل / type پنل | جدول | نمونهٔ فیلدهای اختصاصی |
| --- | --- | --- |
| ShadowsocksR / `ssr` | `v2_server_ssr` | cipher، password، ssr_protocol، obfs، protocol_param |
| SOCKS5 / `socks5` | `v2_server_socks5` | username، password، tls، udp، certificate |
| HTTP / `http` | `v2_server_http` | username، password، tls، sni، headers |
| Snell / `snell` | `v2_server_snell` | psk، version، reuse، obfs_opts |
| WireGuard و AmneziaWG / `wireguard` | `v2_server_wireguard` | ip، ipv6، private_key، public_key، peers، amnezia_wg_option |
| ShadowQUIC / `shadowquic` | `v2_server_shadowquic` | username، password، sni، alpn، quic_versions |
| GOST Relay / `gostrelay` | `v2_server_gostrelay` | forward، tls، mux، username، password |
| SSH / `ssh` | `v2_server_ssh` | username، password، private_key، host_key |
| Mieru / `mieru` | `v2_server_mieru` | transport، port_range، username، password، multiplexing |
| Sudoku / `sudoku` | `v2_server_sudoku` | key، aead_method، padding_min/max، table_type، httpmask |
| MASQUE / `masque` | `v2_server_masque` | private_key، public_key، ip، ipv6، uri، network |
| OpenVPN / `openvpn` | `v2_server_openvpn` | ca، cert، key، username، password، proto، tls_crypt |
| Tailscale و Headscale / `tailscale` | `v2_server_tailscale` | auth_key، control_url، hostname، exit_node، accept_routes |
| ZeroTier / `zerotier` | `v2_server_zerotier` | network، identity_secret، orbit، tcp_fallback_mode |
| EasyTier / `easytier` | `v2_server_easytier` | network_name/secret، peers، listeners، secure_mode |

جدول‌ها مانند پروتکل‌های قبلی، ستون‌های نام، گروه، مسیر، والد، برچسب، آدرس،
پورت اتصال، پورت سرویس، ضریب، نمایش و ترتیب دارند. هرکدام توالی شناسهٔ مستقل
دارد؛ هویت نود در مرتب‌سازی، کش و آمار، **type + id** است.

تمام گزینه‌های اختصاصی و گزینه‌های مشترک BasicOption نسخهٔ مرجع ستون مشخص دارند.
نوع‌های boolean و عدد و آرایه در مدل cast می‌شوند. فقط ساختارهای واقعاً تو در تو،
مانند `peers` و `obfs-opts`، JSON هستند. تنظیمات اصلی در یک blob یا جدول مشترک
ذخیره نمی‌شوند. در نام ستون‌ها `-` به `_` تبدیل می‌شود؛ `protocol` در SSR به
`ssr_protocol` و type داخلی `gostrelay` در خروجی به `gost-relay` نگاشت می‌شود.

## استفاده و رفتار پنل

1. ارتقای معمول پنل و دیتابیس را با `php artisan v2board:update` اجرا کنید.
2. در مدیریت نودها، از منوی + پروتکل را انتخاب و گروه دسترسی را مشخص کنید.
3. فیلدهای عادی و اختصاصی پروتکل را کامل کنید. گزینه‌های کمتر رایج در بخش
   تنظیمات پیشرفتهٔ همان پروتکل قرار دارند و هرکدام فیلد مستقل دارند.
4. نود در همان جدول ثبت می‌شود. نمایش/پنهان‌کردن، کپی، حذف، فیلتر و مرتب‌سازی
   از همان‌جا انجام می‌شود. کپی جدید همیشه مخفی است.

والد باید در جدول همین پروتکل وجود داشته باشد. انتخاب خود نود یا زنجیرهٔ چرخه‌ای
مجاز نیست و والد دارای فرزند حذف نمی‌شود. فرزند تنظیمات پروتکل را از ریشهٔ
زنجیره می‌گیرد و نام، گروه، ضریب و آدرس اتصال خودش را حفظ می‌کند. در حالت
چندهمتایی WireGuard، آدرس‌های اتصال در فهرست peers قرار دارند. تغییر تنظیمات
والد نیز اعتبار فرزندهای وابسته را دوباره بررسی می‌کند.

Tailscale، ZeroTier و EasyTier آدرس/پورت مستقیم لازم ندارند. Mieru باید **پورت یا
بازهٔ پورت** داشته باشد. WireGuard با peers می‌تواند آدرس/پورت مستقیم نداشته باشد.
مقدار خالی گزینهٔ boolean اختیاری، پیش‌فرض خود پروتکل را حفظ می‌کند؛ مقدار false
به‌صورت false ذخیره و صادر می‌شود. فاصله و خط جدید در رمزها و PEM حفظ می‌شود.

## اعتبارسنجی و خروجی

علاوه بر قواعد مشترک و وجود گروه/مسیر/والد، اعتبارسنجی شامل تنظیمات الزامی،
نوع فیلدها، گزینه‌های enum، ناسازگاری UDP با Snell 1/2، بازهٔ پورت Mieru،
کلیدها و شبکه‌های WireGuard، کلیدهای EC با قالب DER/Base64 در MASQUE، گواهی PEM
و جفت cert/key در OpenVPN، padding و HTTP mask در Sudoku و شناسهٔ شبکهٔ ZeroTier
است. فیلد متعلق به پروتکل دیگر و گزینهٔ ناشناخته رد می‌شود.

خروجی‌های `mihomo`، `meta`، `verge` و `nyanpasu` از این پروتکل‌ها پشتیبانی می‌کنند.
آدرس و پورت از فیلدهای نود و نوع از کنترلر همان پروتکل گرفته می‌شود. مقادیر متنی
`{{uuid}}` و `{{user_id}}` برای هر مشترک جایگزین می‌شوند؛ تنظیمات خام والد یا
قالب رمزها همراه پاسخ کاربر ارسال نمی‌شود. نام proxy با نام جاری نود، از جمله
نام‌های اطلاعات حجم/انقضا، هماهنگ می‌ماند. گروه‌های عادی، اعطاشده و منقضی‌شده
از همان منطق دسترسی موجود Irboard استفاده می‌کنند.

TrustTunnel موجود نیز با نام کاربری و رمز UUID، مطابق V2bX، صادر می‌شود. SNI،
حالت گواهی و انتخاب HTTP/3 آن حفظ می‌شود. خروجی‌های کلاینت‌های دیگر مجموعهٔ
پروتکل‌های پشتیبانی‌شدهٔ خود را حفظ می‌کنند.

**دامنهٔ تغییر، مدیریت Irboard و سابسکریپشن است.** پیاده‌سازی هستهٔ سرور V2bX
مرحلهٔ بعد است. API تنظیمات سرور برای این typeهای جدید صریحاً 501 می‌دهد؛ پنل
برای آن‌ها وضعیت آنلاین ساختگی یا هشدار آفلاین تولید نمی‌کند. فایلی از V2bX در
این تغییر ویرایش نشده است. تنظیم موفق اشتراک، آزمون اتصال به یک سرویس زنده نیست.
فایل‌های محلی کلاینت، پذیرش کلید در سرویس مقصد و قابلیت‌های build کلاینت باید
در همان محیط کلاینت بررسی شوند.

## نصب و بررسی

`install.sql` و `update.sql` هر ۱۵ جدول را با ستون‌های یکسان می‌سازند.
به‌روزرسانی با `CREATE TABLE IF NOT EXISTS` تکرارپذیر است و دادهٔ نودها را حفظ
می‌کند. اگر جدول هنوز نصب نشده باشد، اشتراک‌های قبلی ادامه می‌یابند و ذخیرهٔ
پروتکل جدید با پیام لزوم ارتقا، 503 می‌دهد. این تغییر برای ارتقا از ساختار اصلی
Irboard است؛ دادهٔ جدول آزمایشی شاخه‌های منتشرنشده حذف یا منتقل نمی‌شود.

```sh
python3 scripts/patch_admin_mihomo_nodes.py --check
vendor/bin/phpunit --filter 'MihomoNodesTest|MihomoSubscriptionTest'
node --test tests/AdminMihomoNodesBrowserTest.cjs
IRBOARD_MIHOMO_CONTRACT_DIR=/tmp/mihomo-contract vendor/bin/phpunit --filter MihomoSubscriptionTest
python3 tests/ValidateMihomoConfig.py --binary /path/to/mihomo --profiles /tmp/mihomo-contract/ClashMeta.json
```

تست مرورگر هر دو bundle واقعی را اجرا می‌کند. metadata فیکسچر مرورگر با endpoint
واقعی مقایسه می‌شود. خروجی ۱۵ نوع جدید و TrustTunnel با باینری pinned نسخهٔ
v1.19.33 بررسی می‌شود؛ ابزار تست کلید و گواهی خروجی را تعویض یا اصلاح نمی‌کند.

گزارش شبیه‌سازی مصرف، سیاست سرعت، دستگاه‌ها، مسدودی و پردازش گزارش‌ها در
[گزارش چرخهٔ نود](node-lifecycle-simulation.md) آمده است.

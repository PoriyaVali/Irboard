# Mihomo client profiles

Irboard can distribute the network protocols parsed by **MetaCubeX/mihomo
v1.19.33**, commit `3f27ef39f8c96bbfbc8b0b9bb10f5c9ef60149d8`:
[parser](https://github.com/MetaCubeX/mihomo/blob/v1.19.33/adapter/parser.go),
[full option examples](https://github.com/MetaCubeX/mihomo/blob/v1.19.33/docs/config.yaml).

| Already managed by Irboard | Added as external client profiles |
| --- | --- |
| Shadowsocks, VMess, VLESS, Trojan | ShadowsocksR, SOCKS5, HTTP/HTTPS, Snell |
| Hysteria 1/2, TUIC, AnyTLS | WireGuard/AmneziaWG, ShadowQUIC, GOST Relay |
| TrustTunnel (Mihomo export now included) | SSH, Mieru, Sudoku, MASQUE, OpenVPN |
| | Tailscale/Headscale, ZeroTier, EasyTier |

`direct`, `dns`, `reject`, and `rematch` are local routing actions rather than
server protocols. They can be configured in `resources/rules/custom.clash.yaml`.
HTTP with `tls: true` is HTTPS; AmneziaWG uses WireGuard's `amnezia-wg-option`.
The existing custom AnyTLS/REALITY behavior is retained.

## استفاده در پنل

پس از به‌روزرسانی معمول پنل و اجرای مسیر ارتقای پایگاه داده (`php artisan
v2board:update`)، در پایین صفحهٔ **مدیریت نودها**، بخش **نودهای Mihomo** دیده
می‌شود. جدول جدید `v2_server_mihomo` با `CREATE TABLE IF NOT EXISTS` ساخته می‌شود؛
نودهای قبلی تغییری نمی‌کنند. تا پیش از ارتقای دیتابیس، سابسکریپشن‌های قبلی کار
می‌کنند و ویرایشگر جدید پیام نیاز به به‌روزرسانی می‌دهد.

1. «افزودن نود Mihomo» را بزنید و پروتکل، نام و گروه‌های دسترسی را انتخاب کنید.
2. تنظیمات JSON را با اطلاعات سرویس واقعی کامل کنید. فیلدهای خالی در نمونه
   اطلاعات اتصال محسوب نمی‌شوند. نام و نوع پروتکل از فیلدهای بالای فرم می‌آید.
3. گزینه‌های اختصاصی و تو در تو، مانند `peers`، `obfs-opts`، `httpmask`،
   `amnezia-wg-option` و تنظیمات TLS، با همان ساختار حفظ می‌شوند.
4. ترتیب، برچسب و نمایش را تنظیم و ذخیره کنید. کپی نود همیشه مخفی ساخته می‌شود.
5. لینک اشتراک را با `flag=mihomo` یا `flag=meta` به کلاینت بدهید. خروجی‌های
   `verge` و `nyanpasu` نیز این پروفایل‌ها را دارند. اگر لینک از قبل پارامتر
   دارد از `&flag=mihomo` و در غیر این صورت از `?flag=mihomo` استفاده کنید.

مقادیر متنی `{{uuid}}` و `{{user_id}}` برای هر مشترک جایگزین می‌شوند؛ برای مثال
`"username": "{{user_id}}", "password": "{{uuid}}"`. سرویس مستقل باید از قبل
همین اطلاعات ورود را بشناسد. این جایگزینی کاربر یا peer جدید روی سرور نمی‌سازد.

این بخش **پروفایل کلاینت برای سرویس مستقل** است: نصب و راه‌اندازی سرور، گزارش
ترافیک، محدودیت هم‌زمانی/سرعت و لغو اعتبار سمت سرور به یکپارچه‌سازی جداگانه نیاز
دارد. پنهان‌کردن نود یا انقضای حساب، دریافت اشتراک جدید را متوقف می‌کند؛ اطلاعات
اتصالی که قبلاً دریافت شده باید در خود سرویس هم لغو شود. کلید خصوصی WireGuard،
گواهی OpenVPN و کلید ثبت دستگاه overlay باید متناسب با کاربران دریافت‌کننده
تهیه شود. تنظیمات ثابت بین اعضای همان گروه به اشتراک گذاشته می‌شوند.

## Export and compatibility

The complete outbound object is stored as JSON. The panel checks the selected
protocol, endpoint/port, required credentials, group membership, and several
protocol constraints. Advanced options are passed through to Mihomo; its parser
is the final authority. Use a core build that contains the selected protocol and
validate the resulting subscription with `mihomo -t -f subscription.yaml`.
Older clients may reject newer protocol types. Classic Clash, General, Sing-box
and other unrelated exporters do not receive these Mihomo-only profiles.

Profiles follow existing hidden-node, group, active-grant and subscription expiry
rules. Stored templates are admin-only; user-facing node responses contain only
the current subscriber's rendered configuration. Identical addresses on different
protocols, or with different credentials/options, are kept. External profiles have
no heartbeat, so their online state is unknown. The native-node table remains
separate; group counts and group-deletion guards include both kinds of node.

TrustTunnel uses the same UUID for username and password, matching
`V2bX/core/trusttunnel/user.go`. The certificate hostname is used as SNI unless a
custom SNI is configured; self-signed mode enables `skip-cert-verify`, and an
explicit `http3` selection enables QUIC.

## Checks

```sh
vendor/bin/phpunit --filter 'MihomoNodesTest|MihomoSubscriptionTest'
node --test tests/AdminMihomoNodesBrowserTest.cjs
IRBOARD_MIHOMO_CONTRACT_DIR=/tmp/mihomo-contract vendor/bin/phpunit --filter MihomoSubscriptionTest
python3 tests/ValidateMihomoConfig.py --binary /path/to/mihomo --profiles /tmp/mihomo-contract/ClashMeta.json
```

The final check uses v1.19.33 and ephemeral fictional cryptographic material to
parse all 15 added types plus TrustTunnel. These are configuration and browser
checks, not live connectivity tests against production VPN endpoints.

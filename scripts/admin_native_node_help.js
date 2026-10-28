/* Native node guidance for the existing Umi/Ant Design render trees.
 * No DOM observers, mutable form defaults or replacement request lifecycle.
 * Controls are explicitly annotated by patch_admin_native_node_help.py. */
var imnNativeNodeGuides = {
    "common": {
        "name": [
            "نامی که کاربر در فهرست نودها می‌بیند؛ نام قابل تشخیص مثل کشور و نوع اتصال بنویسید.",
            "The name subscribers see; use a recognizable location and connection name."
        ],
        "rate": [
            "ضریب محاسبهٔ مصرف است، نه محدودیت سرعت. با مقدار ۱٫۵، هر ۱ گیگابایت ترافیک ۱٫۵ گیگابایت حساب می‌شود.",
            "Usage multiplier, not a speed limit. At 1.5, each 1 GB of traffic counts as 1.5 GB."
        ],
        "group_id": [
            "گروه‌های دسترسی پلن‌های مجاز را انتخاب کنید؛ فقط کاربران عضو این گروه‌ها نود را در اشتراک می‌بینند.",
            "Select the access groups used by eligible plans; only their subscribers receive this node."
        ],
        "route_id": [
            "قانون‌های مسیریابی تعریف‌شده در پنل را انتخاب کنید؛ ترتیب و پشتیبانی آن‌ها باید با هستهٔ سرور سازگار باشد.",
            "Choose panel routing rules supported by the node core; check their order and core compatibility."
        ],
        "parent_id": [
            "برای اشتراک تنظیمات، والد هم‌پروتکل را انتخاب کنید. نود فرزند تنظیمات اتصال را از والد می‌گیرد؛ برای نود مستقل خالی بماند.",
            "Choose a same-protocol parent to inherit connection settings; leave empty for an independent node."
        ],
        "host": [
            "دامنه یا IP عمومی اتصال کاربران، بدون http:// و بدون مسیر؛ نمونه: node.example.com.",
            "Public hostname or IP used by subscribers, without http:// or a path, for example node.example.com."
        ],
        "port": [
            "پورت اتصال کاربران؛ در حالت معمول همان پورت سرویس است. در NAT یا CDN باید پورت ورودی قابل دسترس را وارد کنید.",
            "Subscriber-facing port; normally the service port. Behind NAT or a CDN, use the reachable external port."
        ],
        "server_port": [
            "پورت واقعی شنود روی سرور نود. بدون NAT یا تغییر پورت، با پورت اتصال یکسان وارد کنید.",
            "Actual listener port on the node server; match the connection port unless port forwarding changes it."
        ],
        "tags": [
            "برچسب‌های نمایشی مثل کشور یا نوع شبکه؛ پس از هر برچسب Enter بزنید. این برچسب‌ها دسترسی ایجاد نمی‌کنند.",
            "Display tags such as country or network type; press Enter after each. Tags do not grant access."
        ],
        "sort": [
            "عدد کوچک‌تر بالاتر نمایش داده می‌شود؛ ترتیب نمایش را می‌توانید در جدول نودها نیز تغییر دهید.",
            "Smaller numbers appear first; the node table also lets you reorder nodes."
        ],
        "show": [
            "با فعال‌بودن، نود برای کاربران واجد شرایط در اشتراک نمایش داده می‌شود. تنظیمات و گروه دسترسی را پیش از نمایش بررسی کنید.",
            "Include the node in eligible subscriptions; verify its settings and access groups before enabling."
        ],
        "tunnel_host": [
            "فقط اگر اتصال کاربران از سرور تونل جداگانه عبور می‌کند آن را پر کنید؛ در اتصال مستقیم خالی بماند.",
            "Fill only when clients connect through a separate tunnel host; leave empty for direct connections."
        ],
        "listen_ip": [
            "آدرس محلی شنود سرویس؛ باید روی خود سرور موجود باشد. دامنهٔ عمومی کاربران را در آدرس نود وارد کنید.",
            "Local listener address on the node server. Put the public subscriber hostname in the node address field."
        ],
        "network": [
            "نوع انتقال باید در پنل، سرور و کلاینت یکسان باشد؛ مانند TCP، WebSocket یا gRPC.",
            "Use the same transport on the panel, server and clients, such as TCP, WebSocket or gRPC."
        ],
        "network_settings": [
            "تنظیمات انتقال انتخاب‌شده، مانند مسیر WebSocket یا نام سرویس gRPC؛ JSON و مقدارها باید با سرور یکسان باشند.",
            "Transport options such as a WebSocket path or gRPC service name; valid JSON must match the server."
        ],
        "networkSettings": [
            "تنظیمات انتقال انتخاب‌شده، مانند مسیر WebSocket یا نام سرویس gRPC؛ JSON و مقدارها باید با سرور یکسان باشند.",
            "Transport options such as a WebSocket path or gRPC service name; valid JSON must match the server."
        ],
        "tls": [
            "TLS را مطابق شنود سرور انتخاب کنید. برای TLS معتبر، نام دامنه و گواهی باید تطبیق داشته باشند؛ REALITY روش جداگانه‌ای است.",
            "Match the server TLS mode. Verified TLS needs a matching hostname and certificate; REALITY is a separate mode."
        ],
        "tls_settings": [
            "در TLS معمولی، نام دامنه و گواهی سرور را هماهنگ کنید. در REALITY، نام مقصد پوششی و جفت‌کلید X25519 را تنظیم کنید؛ آدرس اتصال کاربران جداگانه در فرم اصلی نود وارد می‌شود.",
            "For ordinary TLS, match the server certificate hostname. For REALITY, configure the cover hostname and X25519 key pair. The subscriber endpoint is configured separately in the main node form."
        ],
        "tlsSettings": [
            "نام دامنه و تنظیمات TLS را مطابق سرور وارد کنید؛ از غیرفعال‌کردن اعتبارسنجی برای پنهان‌کردن خطای دامنه استفاده نکنید.",
            "Match the server TLS settings; do not disable verification to hide a hostname mismatch."
        ],
        "server_name": [
            "نام SNI و نام مورد انتظار در گواهی کلاینت؛ بدون پورت وارد کنید. دامنهٔ صدور گواهی V2bX را جداگانه در CertDomain سرور تنظیم کنید.",
            "Client SNI and expected certificate hostname, without a port. Configure the V2bX certificate ownership domain separately in server CertDomain."
        ],
        "serverName": [
            "نام SNI و نام مورد انتظار در گواهی کلاینت؛ بدون پورت وارد کنید. دامنهٔ صدور گواهی سرور تنظیم جداگانه‌ای دارد.",
            "Client SNI and expected certificate hostname, without a port; the server certificate issuance domain is configured separately."
        ],
        "insecure": [
            "اعتبارسنجی گواهی را خاموش می‌کند؛ برای استفادهٔ معمول خاموش بماند. ابتدا مشکل دامنه یا زنجیرهٔ گواهی را اصلاح کنید.",
            "Disables certificate verification. Keep off for normal use; fix hostname or certificate-chain errors instead."
        ],
        "allow_insecure": [
            "اعتبارسنجی گواهی را خاموش می‌کند؛ برای استفادهٔ معمول خاموش بماند. این گزینه خطای گواهی را اصلاح نمی‌کند.",
            "Disables certificate verification. Keep off for normal use; this option does not fix a bad certificate."
        ],
        "allowInsecure": [
            "اعتبارسنجی گواهی را خاموش می‌کند؛ برای استفادهٔ معمول خاموش بماند. این گزینه خطای گواهی را اصلاح نمی‌کند.",
            "Disables certificate verification. Keep off for normal use; this option does not fix a bad certificate."
        ],
        "cipher": [
            "روش رمزگذاری باید با سرویس و کلاینت سازگار باشد؛ نوع کلید روش‌های ۲۰۲۲ با روش‌های قدیمی تفاوت دارد.",
            "Match a cipher supported by the server and clients; 2022 ciphers require different keys from legacy ciphers."
        ],
        "obfs": [
            "استتار را فقط مطابق پیکربندی سرور انتخاب کنید؛ فعال‌کردن یک‌طرفه باعث قطع اتصال می‌شود.",
            "Enable obfuscation only when configured on the server; mismatched settings prevent connections."
        ],
        "obfs_password": [
            "رمز استتار سمت سرور را عیناً وارد کنید؛ این رمز با شناسه یا رمز مستقل هر کاربر تفاوت دارد.",
            "Use the exact server obfuscation secret; it is distinct from each subscriber identity or password."
        ],
        "up_mbps": [
            "پهنای باند آپلود مورد استفادهٔ پروتکل بر حسب مگابیت‌برثانیه؛ این گزینه جای محدودیت سرعت پلن را نمی‌گیرد.",
            "Protocol upload bandwidth in megabits per second; this does not replace the plan speed limit."
        ],
        "down_mbps": [
            "پهنای باند دانلود مورد استفادهٔ پروتکل بر حسب مگابیت‌برثانیه؛ مقدار متناسب با اتصال واقعی انتخاب کنید.",
            "Protocol download bandwidth in megabits per second; choose a value appropriate to the real connection."
        ],
        "disable_sni": [
            "ارسال SNI را غیرفعال می‌کند؛ فقط با پشتیبانی صریح سرور و کلاینت تغییر دهید. به‌طور معمول خاموش بماند.",
            "Disables SNI transmission; change only when both server and client explicitly support it. Normally keep off."
        ],
        "udp_relay_mode": [
            "حالت انتقال UDP باید با سرور و کلاینت سازگار باشد؛ native از دیتاگرام و حالت‌های دیگر از مسیر جایگزین استفاده می‌کنند.",
            "Match the server and client UDP relay mode; native uses datagrams and other modes use an alternate path."
        ],
        "congestion_control": [
            "کنترل ازدحام QUIC؛ گزینهٔ پشتیبانی‌شدهٔ سرور را انتخاب کنید. تغییر آن محدودیت سرعت پلن را عوض نمی‌کند.",
            "QUIC congestion control; choose a supported server algorithm. It does not change the plan speed limit."
        ],
        "zero_rtt_handshake": [
            "شروع سریع‌تر نشست در کلاینت‌های پشتیبانی‌شده؛ تا زمانی که هر دو سمت به آن نیاز ندارند خاموش بماند.",
            "Faster session setup for supporting clients; leave off unless both sides are configured to use it."
        ],
        "padding_scheme": [
            "الگوی padding اختصاصی AnyTLS؛ فقط قالب پشتیبانی‌شده و هماهنگ با سرور وارد کنید. بدون نیاز خاص، تنظیم پیش‌فرض هسته را نگه دارید.",
            "Custom AnyTLS padding scheme; use a supported format matching the server. Otherwise keep the core default."
        ],
        "flow": [
            "Flow ویژهٔ VLESS را مطابق سرور و نوع انتقال انتخاب کنید؛ برای اتصال عادی خالی بماند.",
            "Choose a VLESS flow matching the server and transport; leave empty for ordinary connections."
        ],
        "encryption": [
            "روش رمزگذاری VLESS را فقط با پشتیبانی هسته و کلاینت فعال کنید؛ مقدار پیش‌فرض اتصال عادی را بدون نیاز خاص تغییر ندهید.",
            "Enable VLESS encryption only with matching core and client support; retain ordinary defaults unless specifically required."
        ],
        "encryption_settings": [
            "کلیدها و تنظیمات رمزگذاری VLESS باید در سرور و خروجی اشتراک با همان حالت سازگار باشند؛ مقادیر نمونه را ذخیره نکنید.",
            "VLESS encryption keys and options must match the selected mode on the server and subscription; never save example keys."
        ],
        "protocol": [
            "پروتکل واقعی این نود را مطابق هستهٔ سرور انتخاب کنید؛ تغییر آن به تنظیمات سازگار همان پروتکل نیاز دارد.",
            "Select the actual protocol used by the server core; changing it requires the corresponding server configuration."
        ],
        "version": [
            "نسخهٔ Hysteria باید با سرویس و کلاینت یکسان باشد؛ تنظیمات نسخهٔ ۱ و ۲ قابل جایگزینی نیستند.",
            "Match Hysteria version on the server and clients; version 1 and version 2 settings are not interchangeable."
        ],
        "cert_mode": [
            "روش آماده‌سازی گواهی را مطابق سرور انتخاب کنید. فایل موجود را ابزار بیرونی تمدید می‌کند؛ خودامضا به اعتماد جداگانهٔ کلاینت نیاز دارد.",
            "Choose the server certificate method. External tools renew file certificates; self-signed certificates need separate client trust."
        ],
        "provider": [
            "شناسهٔ ارائه‌دهندهٔ DNS برای روش DNS-01، مثل cloudflare؛ باید توسط مدیر گواهی سرور پشتیبانی شود.",
            "DNS provider identifier for DNS-01, for example cloudflare; the server certificate manager must support it."
        ],
        "dns_env": [
            "فقط متغیرهای احراز هویت DNS مورد نیاز ارائه‌دهنده را با قالب نام=مقدار وارد کنید؛ این اطلاعات محرمانه‌اند.",
            "Enter the provider-required DNS credentials as name=value variables; these values are confidential."
        ],
        "cert_file": [
            "مسیر فایل زنجیرهٔ کامل گواهی روی خود سرور نود؛ این فیلد محتوای PEM یا مسیر روی رایانهٔ ادمین نیست.",
            "Path to the full certificate chain on the node server; not PEM contents or a path on your computer."
        ],
        "key_file": [
            "مسیر کلید خصوصیِ جفت همان گواهی روی سرور؛ فایل باید برای سرویس قابل خواندن باشد و منتشر نشود.",
            "Path to that certificate’s matching private key on the server; make it readable by the service and keep it private."
        ],
        "dest": [
            "دامنه یا IP مقصد پوششی REALITY را بدون https:// و بدون پورت وارد کنید. پورت مقصد در Server Port همین پنجره تنظیم می‌شود؛ نام SNI باید با گواهی مقصد پوششی سازگار باشد.",
            "REALITY cover destination hostname or IP, without https:// or a port. Set its port in this dialog's Server Port field; the SNI must match the cover destination certificate."
        ],
        "xver": [
            "نسخهٔ PROXY protocol مقصد REALITY؛ فقط اگر مقصد آن را پشتیبانی می‌کند تغییر دهید. مقدار ۰ یعنی غیرفعال.",
            "PROXY protocol version for the REALITY destination; change only if supported. Zero disables it."
        ],
        "private_key": [
            "کلید خصوصی REALITY روی سرور؛ از ابزار تولید کلید همان هسته بگیرید و آن را محرمانه نگه دارید.",
            "Server REALITY private key; generate it with the selected core’s key tool and keep it secret."
        ],
        "public_key": [
            "کلید عمومی متناظر REALITY برای کلاینت‌ها؛ با کلید خصوصی سرور از یک جفت تولید شود.",
            "REALITY client public key; it must be paired with the server private key."
        ],
        "short_id": [
            "شناسهٔ کوتاه REALITY با قالب هگز معتبرِ مورد قبول هسته؛ در سرور و کلاینت یکسان تنظیم شود.",
            "A valid core-supported hexadecimal REALITY short ID; match it on server and client."
        ],
        "fingerprint": [
            "اثر انگشت TLS کلاینت پشتیبانی‌شده، مانند chrome؛ این گزینه جای اعتبارسنجی گواهی را نمی‌گیرد.",
            "A supported client TLS fingerprint such as chrome; it does not replace certificate verification."
        ],
        "reject_unknown_sni": [
            "ردکردن SNI ناشناخته در هسته‌های پشتیبانی‌شده؛ تنها پس از تنظیم نام‌های مجاز سرور فعال کنید.",
            "Reject unknown SNI in supporting cores; enable only after configuring permitted server names."
        ],
        "ech": [
            "حالت ECH باید با پشتیبانی سرور، کلاینت و تنظیمات DNS سازگار باشد؛ در صورت نبود این زیرساخت خاموش بماند.",
            "Match ECH support across server, client and DNS configuration; keep off without that setup."
        ],
        "ech_server_name": [
            "نام پوششی بیرونی ECH؛ این نام با نام داخلی اتصال و دامنهٔ مالکیت گواهی تفاوت دارد.",
            "ECH outer cover hostname; it is distinct from the inner connection name and certificate ownership domain."
        ],
        "ech_key": [
            "کلید خصوصی ECH سمت سرور؛ فقط کلید تولیدشده برای همان پیکربندی ECH را وارد کنید.",
            "Server ECH private key; use the key generated for this exact ECH configuration."
        ],
        "ech_config": [
            "پیکربندی عمومی ECH برای کلاینت؛ باید از همان کلید و مجموعهٔ ECH سرور استخراج شود.",
            "Public client ECH configuration, derived from the corresponding server ECH key and settings."
        ],
        "dnsSettings": [
            "تنظیمات DNS سمت سرور برای هسته‌های پشتیبانی‌شده؛ این بخش DNS دستگاه ادمین را تغییر نمی‌دهد.",
            "Server DNS configuration for supporting cores; this does not change your admin device’s DNS."
        ],
        "ruleSettings": [
            "قوانین فیلتر دامنه یا پروتکل را مطابق پشتیبانی هسته تنظیم کنید؛ روی کاربران این نود اثر می‌گذارند.",
            "Configure domain/protocol filters supported by the core; these apply to this node’s subscribers."
        ],
        "hostname": [
            "دامنهٔ واقعی گواهی سرویس TrustTunnel، بدون پورت؛ باید به سرور اشاره کند و تحت کنترل شما باشد.",
            "Actual TrustTunnel certificate hostname, without a port; it must point to the server and be under your control."
        ],
        "cert_type": [
            "روش گواهی TrustTunnel را مطابق سرور تعیین کنید. خودامضا به اعتماد جداگانهٔ کلاینت نیاز دارد؛ گواهی آماده را ابزار بیرونی تمدید می‌کند.",
            "Choose the TrustTunnel certificate method. Self-signed needs client trust; external tools renew provided certificates."
        ],
        "acme_email": [
            "ایمیل معتبر مالک دامنه برای حساب ACME؛ برای دریافت پیام‌های مربوط به گواهی از ایمیل در دسترس استفاده کنید.",
            "Valid domain-owner email for the ACME account; use an address you can access for certificate notices."
        ],
        "cert_chain_path": [
            "مسیر زنجیرهٔ گواهی TrustTunnel روی سرور؛ گواهی باید برای دامنهٔ سرویس معتبر باشد.",
            "TrustTunnel certificate-chain path on the server; the certificate must be valid for the service hostname."
        ],
        "cert_key_path": [
            "مسیر کلید خصوصیِ متناظر زنجیرهٔ گواهی روی سرور؛ محتوای کلید را در این فیلد نچسبانید.",
            "Path to the matching private key on the server; do not paste private-key contents here."
        ],
        "custom_sni": [
            "SNI ارسالی کلاینت TrustTunnel؛ معمولاً خالی بماند تا نام دامنهٔ گواهی استفاده شود. این گزینه دامنهٔ صدور را تغییر نمی‌دهد.",
            "TrustTunnel client SNI; normally leave empty to use the certificate hostname. It does not change the issuance domain."
        ],
        "client_random_prefix": [
            "فیلتر پیشوند ClientHello برای کلاینت‌های سازگار؛ رمز عبور یا شناسهٔ کاربر نیست و جای احراز هویت را نمی‌گیرد.",
            "ClientHello-prefix filter for supporting clients; it is not a user password or identity and does not replace authentication."
        ],
        "upstream_protocol": [
            "انتقال TrustTunnel مطابق پشتیبانی شبکه و کلاینت؛ HTTP/3 به دسترسی UDP نیاز دارد.",
            "TrustTunnel transport matching client/network support; HTTP/3 requires reachable UDP."
        ],
        "anti_dpi": [
            "قابلیت ضد DPI اختصاصی کلاینت TrustTunnel؛ فقط با کلاینت پشتیبانی‌شده فعال کنید.",
            "TrustTunnel client anti-DPI option; enable only for a supporting client."
        ],
        "has_ipv6": [
            "فقط اگر مسیر IPv6 سرویس واقعاً قابل استفاده است فعال کنید؛ داشتن رکورد DNS به‌تنهایی کافی نیست.",
            "Enable only when the service IPv6 path actually works; a DNS record alone is insufficient."
        ],
        "dns_upstreams": [
            "DNSهای بالادستی TrustTunnel را طبق قالب پشتیبانی‌شده وارد کنید؛ هر مقدار باید از شبکهٔ سرویس قابل دسترس باشد.",
            "Enter TrustTunnel DNS upstreams in a supported format; each must be reachable from the service network."
        ],
        "encryption_method": [
            "روش رمزگذاری MDNS باید در سرور و کلاینت یکسان باشد. ChaCha20-Poly1305 و AES-GCM احراز اصالت دارند؛ XOR یا بدون رمز برای استفادهٔ عادی مناسب نیستند.",
            "Match MDNS encryption on server and client. ChaCha20-Poly1305 and AES-GCM authenticate traffic; XOR/none are unsuitable for normal use."
        ],
        "encryption_key": [
            "کلید انتقال MDNS را مطابق سرور وارد کنید؛ خالی‌بودن هنگام ذخیره کلید تازه می‌سازد، پس برای حفظ نود موجود کلید آن را پاک نکنید.",
            "Match the MDNS transport key to the server; saving an empty field generates a new key, so do not clear an existing key to preserve it."
        ],
        "domain": [
            "دامنه‌ها یا زیردامنه‌های مسیر DNS نود MDNS؛ باید در DNS به‌درستی برای این سرویس واگذار شده باشند.",
            "MDNS carrier domains/subdomains; their DNS delegation must be configured for this service."
        ],
        "obfs.host": [
            "نام پوششی HTTP obfuscation مطابق سرور؛ آدرس نود را همچنان در فیلد آدرس اتصال نگه دارید.",
            "HTTP-obfuscation cover host matching the server; keep the actual endpoint in the connection address field."
        ],
        "obfs.path": [
            "مسیر HTTP obfuscation مطابق سرور، با / آغاز شود؛ این مقدار مسیر صفحهٔ پنل نیست.",
            "Server-matching HTTP-obfuscation path beginning with /; not the panel URL path."
        ]
    },
    "protocols": {
        "shadowsocks": {
            "obfs": [
                "استتار HTTP فقط وقتی سرور Shadowsocks آن را دارد فعال شود؛ بدون پیکربندی سمت سرور «ندارد» بماند.",
                "Enable HTTP obfuscation only when the Shadowsocks server supports it; otherwise choose none."
            ]
        },
        "vmess": {},
        "trojan": {},
        "vless": {},
        "hysteria": {},
        "tuic": {},
        "anytls": {},
        "mdns": {},
        "trusttunnel": {},
        "v2node": {
            "install_command": [
                "این دستور از تنظیمات نود ساخته می‌شود و قابل ویرایش نیست. ابتدا دامنه، پورت و پروتکل را بررسی کنید؛ سپس دستور را روی سرور همان نود اجرا کنید، نه روی سرور پنل. اطلاعات دسترسی داخل دستور را منتشر نکنید.",
                "This read-only command is generated from the node settings. Review the hostname, port and protocol, then run it on that node's server. The panel server does not run it. Keep embedded access credentials private."
            ]
        },
        "dns": {
            "address": [
                "نشانی resolver سمت سرور؛ مانند 1.1.1.1 یا قالب DNS پشتیبانی‌شدهٔ هسته، نه دامنهٔ نود.",
                "Server-side resolver address, such as 1.1.1.1 or a core-supported DNS URL, not the node hostname."
            ],
            "port": [
                "پورت همان resolver؛ برای DNS معمولی غالباً ۵۳ است و باید با سرویس انتخاب‌شده سازگار باشد.",
                "Port of this resolver; conventional DNS usually uses 53. Match the selected resolver service."
            ],
            "domains": [
                "محدودهٔ دامنه‌های استفاده‌کننده از این resolver؛ هر خط یک مورد طبق قالب قانون DNS همان هسته.",
                "Domains using this resolver; one core-supported DNS domain rule per line."
            ]
        },
        "rules": {
            "domain": [
                "دامنه‌های هدف قانون فیلتر؛ هر خط یک مورد با قالب پشتیبانی‌شدهٔ هسته. اثر قانون را پیش از نمایش نود بررسی کنید.",
                "Filter target domains, one core-supported pattern per line; check the rule’s effect before publishing the node."
            ],
            "protocol": [
                "پروتکل‌های هدف فیلتر؛ هر خط یک مورد از نام‌های شناخته‌شدهٔ هسته. این بخش پروتکل اتصال نود را تغییر نمی‌دهد.",
                "Filter target protocols, one core-recognized name per line; this does not change the node connection protocol."
            ]
        },
        "tls": {
            "server_name": [
                "برای REALITY، نام SNI سرویس مقصد پوششی را بدون پورت وارد کنید؛ مانند www.google.com. برای دامنهٔ پوششی گواهی صادر نمی‌کنید. در TLS معمولی، نام باید با گواهی نود مطابقت داشته باشد و CertDomain در V2bX جداگانه تنظیم می‌شود.",
                "For REALITY, enter the cover service SNI without a port, such as www.google.com; you do not issue a certificate for that cover domain. For ordinary TLS, match the node certificate and configure V2bX CertDomain separately."
            ],
            "server_port": [
                "پورت مقصد پوششی REALITY، معمولاً ۴۴۳؛ این فیلد پورت شنود نود نیست. برای مقصد www.google.com:443، در Server Address فقط www.google.com و در این فیلد 443 وارد کنید. پورت سرویس نود در فرم اصلی تنظیم می‌شود.",
                "REALITY cover destination port, usually 443. This is not the node listener port. For www.google.com:443, enter www.google.com in Server Address and 443 here. Configure the node listener in the main form."
            ]
        },
        "encryption": {
            "mode": [
                "حالت رمزگذاری VLESS مطابق سرور و کلاینت؛ تغییر حالت به کلیدها و تنظیمات سازگار همان حالت نیاز دارد.",
                "VLESS encryption mode matching server and client; each mode requires compatible keys and options."
            ],
            "rtt": [
                "نوع راه‌اندازی نشست رمزگذاری VLESS؛ گزینهٔ پشتیبانی‌شدهٔ هر دو سمت را انتخاب کنید.",
                "VLESS encrypted-session setup mode; choose a value supported by both endpoints."
            ],
            "ticket": [
                "مدت اعتبار ticket مطابق هسته، مانند 600s؛ قالب و بازه باید توسط سرور و کلاینت پشتیبانی شود.",
                "Core-supported ticket lifetime, such as 600s; match the format and range on server and client."
            ],
            "server_padding": [
                "الگوی padding سمت سرور؛ فقط قالب پشتیبانی‌شدهٔ این حالت را وارد کنید و بدون نیاز خاص خالی بگذارید.",
                "Server padding scheme; use the selected mode’s supported format and otherwise leave empty."
            ],
            "client_padding": [
                "الگوی padding سمت کلاینت؛ باید با قابلیت‌های همان حالت رمزگذاری سازگار باشد.",
                "Client padding scheme, compatible with the selected encryption mode."
            ],
            "private_key": [
                "کلید خصوصی همان حالت رمزگذاری VLESS؛ با ابزار همان هسته تولید شود و با کلید TLS یا REALITY جایگزین نشود.",
                "Private key for the selected VLESS encryption mode; generate with its core tool, not a TLS or REALITY key."
            ],
            "password": [
                "مقدار رمز یا کلید عمومی بخش کلاینت باید با تنظیمات همین حالت در سرور متناظر باشد؛ از مقدار نمونه استفاده نکنید.",
                "The client password/public value must correspond to this server encryption mode; do not use example values."
            ]
        }
    },
    "intro": {
        "shadowsocks": [
            "رمزگذاری، استتار و پورت‌ها باید با سرویس Shadowsocks سرور یکسان باشند.",
            "Match the cipher, obfuscation and ports to the Shadowsocks server."
        ],
        "vmess": [
            "تنظیمات انتقال و TLS باید در سرور و اشتراک VMess یکسان باشند.",
            "Match VMess transport and TLS settings on the server and subscription."
        ],
        "vless": [
            "پروتکل VLESS، انتقال، TLS/REALITY و Flow را هماهنگ با هستهٔ سرور تنظیم کنید.",
            "Coordinate VLESS transport, TLS/REALITY and flow with the server core."
        ],
        "trojan": [
            "Trojan به تنظیمات معتبر TLS و نام دامنهٔ سازگار با سرور نیاز دارد.",
            "Trojan needs valid TLS settings and a hostname matching the server."
        ],
        "hysteria": [
            "نسخه و گزینه‌های Hysteria را مطابق سرور انتخاب کنید؛ مسیر UDP باید قابل دسترس باشد.",
            "Match Hysteria version and options to the server and ensure UDP is reachable."
        ],
        "tuic": [
            "برای TUIC، دامنهٔ معتبر TLS و مسیر UDP قابل دسترس لازم است.",
            "TUIC requires a valid TLS hostname and reachable UDP."
        ],
        "anytls": [
            "AnyTLS به TLS معتبر نیاز دارد؛ الگوی padding اختیاری را فقط هماهنگ با سرور تغییر دهید.",
            "AnyTLS requires verified TLS; change optional padding only to match the server."
        ],
        "mdns": [
            "این بخش برای نود MasterDnsVPN و مسیرهای DNS آن است؛ دامنه‌های واگذار‌شده و کلید انتقال را مطابق سرور تنظیم کنید.",
            "Configure MasterDnsVPN carrier domains and transport encryption to match the server."
        ],
        "trusttunnel": [
            "نام دامنهٔ گواهی، روش گواهی و انتقال TrustTunnel باید با endpoint سرور مطابقت داشته باشند.",
            "Match the certificate hostname, certificate method and transport to the TrustTunnel server endpoint."
        ],
        "v2node": [
            "نوع پروتکل و گزینه‌های متناظر آن را مطابق هستهٔ سرور انتخاب کنید؛ آدرس اتصال و آدرس محلی شنود نقش متفاوتی دارند.",
            "Select the server protocol and matching options; the public connection address differs from the local listener address."
        ]
    }
};
var imnNativeHelpOwnerIds = new WeakMap();
var imnNativeHelpSequence = 0;
function imnNativeHelp(React, tree, protocol, owner) {
    var locale = 'fa';
    try { locale = localStorage.getItem('umi_locale') || 'fa'; } catch (_) {}
    var language = /^fa/i.test(locale) ? 0 : 1;
    var number = owner && imnNativeHelpOwnerIds.get(owner);
    if (!number) { number = ++imnNativeHelpSequence; if (owner) imnNativeHelpOwnerIds.set(owner, number); }
    var prefix = 'native-node-help-' + number + '-';
    var specific = imnNativeNodeGuides.protocols[protocol] || {};
    var introduced = false;
    var occurrences = Object.create(null);
    function guide(field) { return specific[field] || imnNativeNodeGuides.common[field]; }
    function group(element) { return React.isValidElement(element) && /(?:^|\s)form-group(?:\s|$)/.test(element.props.className || ''); }
    function marked(children, result) {
        React.Children.forEach(children, function (child) {
            if (!React.isValidElement(child) || group(child)) return;
            var field = child.props['data-node-field'];
            if (field && guide(field)) result.push({field: field, id: child.props['data-native-node-help-id']});
            marked(child.props.children, result);
        });
    }
    function list(children) { return Array.isArray(children) ? children.slice() : children == null ? [] : [children]; }
    function map(children, visit) { return Array.isArray(children) ? children.map(function (child) { return map(child, visit); }) : visit(children); }
    function help(field, id) {
        return React.createElement('small', {
            key: id, id: id,
            className: 'form-text text-muted', dir: language === 0 ? 'rtl' : 'ltr', lang: language === 0 ? 'fa' : 'en', 'data-native-node-help': field
        }, guide(field)[language]);
    }
    function walk(element, inGroup) {
        if (!React.isValidElement(element)) return element;
        var isGroup = group(element), field = element.props['data-node-field'];
        var addIntro = !introduced && element.props.id === 'server' && imnNativeNodeGuides.intro[protocol];
        if (addIntro) introduced = true;
        var children = map(element.props.children, function (child) { return walk(child, inGroup || isGroup); });
        var props = {};
        if (field && guide(field)) {
            var described = element.props['aria-describedby'];
            var occurrence = occurrences[field] = (occurrences[field] || 0) + 1;
            var id = prefix + field.replace(/[^a-zA-Z0-9_-]/g, '-') + '-' + occurrence;
            props['data-native-node-help-id'] = id;
            props['aria-describedby'] = described ? described + ' ' + id : id;
        }
        if (isGroup) {
            var fields = []; marked(children, fields);
            if (fields.length) children = list(children).concat(fields.map(function (item) { return help(item.field, item.id); }));
        }
        if (addIntro) {
            children = [React.createElement('p', {
                key: 'node-guide-intro', className: 'text-muted', role: 'note', dir: language === 0 ? 'rtl' : 'ltr', lang: language === 0 ? 'fa' : 'en',
                'data-native-node-intro': protocol, 'data-native-node-protocol': protocol
            }, imnNativeNodeGuides.intro[protocol][language])].concat(list(children));
        }
        var result = React.cloneElement(element, props, children);
        if (field && guide(field) && !inGroup && !isGroup) return React.createElement(React.Fragment, null, result, help(field, props['data-native-node-help-id']));
        return result;
    }
    return walk(tree, false);
}

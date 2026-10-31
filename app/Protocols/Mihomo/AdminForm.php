<?php

namespace App\Protocols\Mihomo;

/** Presentation metadata for the protected administrator form only.
 * Defaults describe a NEW draft. They are never applied to stored nodes,
 * request validation, user subscriptions or the server's runtime settings.
 */
class AdminForm
{
    private static function text(string $fa, string $en): array
    {
        return ['fa' => $fa, 'en' => $en];
    }

    private static function item(string $faLabel, string $enLabel, string $faHelp, string $enHelp): array
    {
        return ['label' => self::text($faLabel, $enLabel), 'help' => self::text($faHelp, $enHelp)];
    }

    public static function catalog(array $catalog): array
    {
        $copy = self::copy();
        foreach ($catalog as $type => &$definition) {
            $definition['form'] = [
                'description' => self::descriptions()[$type],
                // Shown instead while V2bX manages the node: nothing about it
                // has to match a service elsewhere.
                'managed_description' => ManagedServer::supports($type) ? self::text(
                    'این نود را V2bX روی سرور خودتان اجرا می‌کند: کاربران، مصرف، محدودیت‌ها و کلیدها از پنل می‌آیند و تنظیمات پروتکل برای سرور و اشتراک کاربران یکسان اعمال می‌شود. فقط نام، گروه، آدرس و پورت لازم است؛ بقیه پیش‌فرض‌های آمادهٔ کار دارند.',
                    'V2bX runs this node on your own server: users, usage, limits and keys come from the panel, and the protocol settings apply to the server and to every subscription alike. Only the name, groups, address and port are needed; everything else has working defaults.'
                ) : null,
                'capability' => self::capability($type),
                'endpoint' => !in_array($type, ['tailscale', 'zerotier', 'easytier'], true),
                'defaults' => self::defaults($type),
                'common' => self::common(),
                'sections' => [
                    ['key' => 'connection', 'label' => self::text('اتصال و انتقال', 'Connection and transport'), 'help' => self::text('نوع انتقال و تنظیمات اتصال باید با سرویس مقصد یکسان باشند.', 'Match the transport and connection settings to the destination service.'),
                        'managed_help' => self::text('V2bX همین تنظیمات را روی سرور و در اشتراک کاربران اعمال می‌کند؛ پیش‌فرض‌ها کار می‌کنند.', 'V2bX applies these settings to the server and to the subscriptions alike; the defaults work.')],
                    ['key' => 'credentials', 'label' => self::text('مشخصات ورود', 'Authentication'), 'help' => self::text('اطلاعات ورود یا کلید پروفایل را از همان سرویس بگیرید؛ نمونه‌ها مقدار واقعی نیستند.', 'Obtain credentials or profile keys from that service; examples are not real values.'),
                        'managed_help' => self::text('هر کاربر مشخصات ورود جداگانهٔ خودش را از پنل می‌گیرد.', 'Each subscriber gets their own credentials from the panel.')],
                    $type === 'ssh'
                        ? ['key' => 'security', 'label' => self::text('تأیید هویت سرور SSH', 'SSH server verification'), 'help' => self::text('کلید میزبان سرور را وارد کنید تا کلاینت به سرور جعلی وصل نشود؛ بدون آن هر کلید میزبانی پذیرفته می‌شود.', 'Pin the server host key so clients refuse an impostor server; without it any host key is accepted.')]
                        : ['key' => 'security', 'label' => self::text('TLS و امنیت اتصال', 'TLS and connection security'), 'help' => self::text('اعتبارسنجی گواهی را فعال نگه دارید؛ تنظیمات گواهی کلاینت با گواهی سرور متفاوت است.', 'Keep certificate validation enabled; client certificates differ from server certificates.'),
                            'managed_help' => self::text('گواهی سرور را V2bX با تنظیم CertConfig خودش می‌گیرد و تمدید می‌کند؛ دامنهٔ گواهی همین آدرس یا SNI نود است.', 'V2bX obtains and renews the server certificate through its own CertConfig; the certificate is for this node\'s address or SNI.')],
                    ['key' => 'advanced', 'label' => self::text('تنظیمات پیشرفته', 'Advanced settings'), 'help' => self::text('فقط گزینه‌هایی را تغییر دهید که سرویس مقصد لازم دارد. گزینهٔ خالی به اشتراک اضافه نمی‌شود.', 'Change only options required by the destination service. Empty options are omitted from the subscription.'),
                        'managed_help' => self::text('فقط برای نیاز مشخصی تغییر دهید؛ V2bX و اشتراک‌ها همین مقادیر را می‌گیرند. مشخصات ورود هر کاربر و کلید Sudoku خودکار ساخته می‌شوند.', 'Change only for a specific need; V2bX and the subscriptions take the same values. Each subscriber\'s credentials and the Sudoku key are generated for you.')],
                ],
            ];
            foreach ($definition['fields'] as $column => &$field) {
                $option = $field['option'];
                // A missing entry is an implementation error, not raw option
                // text or a generic hint silently shown to administrators.
                if (!isset($copy[$option])) throw new \LogicException('Missing administrator guidance: ' . $option);
                $field += $copy[$option];
                $field['section'] = self::section($option);
                $field['required'] = in_array($option, self::required()[$type] ?? [], true);
                $field = array_replace($field, self::overrides($type, $option));
                if (ManagedServer::supports($type) && in_array($option, ['username', 'password'], true)) {
                    $field['managed_credential'] = true;
                    $field['required_external'] = in_array($type, ['mieru', 'shadowquic'], true);
                    $field['help'] = self::text('در حالت مدیریت با V2bX خالی بگذارید یا {{uuid}} بنویسید تا برای هر کاربر جدا ساخته شود. برای سرویس بیرونی، مقدار همان سرویس را وارد کنید.', 'When V2bX manages users, leave blank or enter {{uuid}} for an individual subscriber credential. For an external service, enter that service\'s credential.');
                }
                if ($type === 'snell' && $option === 'psk') {
                    $field['managed_credential'] = true;
                    $field['required'] = false;
                    $field['required_external'] = true;
                    $field['help'] = self::text('در حالت مدیریت با V2bX خالی بگذارید یا {{uuid}} بنویسید تا هر کاربر کلید خودش را داشته باشد؛ همین کلید رمز ShadowTLS او هم هست. برای سرویس بیرونی، PSK همان سرویس را کپی کنید.', 'When V2bX manages users, leave blank or enter {{uuid}} so each subscriber has their own key, which is also their ShadowTLS password. For an external service, copy that service\'s PSK.');
                }
                if ($type === 'sudoku' && $option === 'key') {
                    $field['managed_auto'] = true;
                    $field['required'] = false;
                    $field['required_external'] = true;
                    $field['help'] = self::text('در حالت مدیریت با V2bX، کلید خصوصی اصلی (۶۴ رقم hex) است؛ خالی بگذارید تا هنگام ذخیره ساخته شود. هر کاربر تکهٔ جداگانه‌ای از آن را در اشتراک می‌گیرد و با حذف او همان تکه از کار می‌افتد. برای سرویس بیرونی، کلید همان سرویس را کپی کنید.', 'When V2bX manages users this is the master private key (64 hex digits); leave blank to generate one on save. Each subscriber receives their own split of it, which stops working when they are removed. For an external service, copy that service\'s key.');
                }
                if ($type === 'snell' && $option === 'obfs-opts') {
                    $field['help'] = self::text('شیء JSON استتار. در حالت مدیریت با V2bX فقط ShadowTLS نسخهٔ ۳ کاربران را از هم جدا می‌کند: {"mode":"shadow-tls","version":3,"host":"دامنهٔ یک سایت TLS 1.3"} بدون password. برای سرویس بیرونی، mode می‌تواند http، tls، shadow-tls، restls یا jls باشد.', 'The obfuscation JSON object. When V2bX manages users, only ShadowTLS v3 tells them apart: {"mode":"shadow-tls","version":3,"host":"a TLS 1.3 site"} without a password. For an external service, mode may be http, tls, shadow-tls, restls or jls.');
                    $field['placeholder'] = self::text('{"mode":"shadow-tls","version":3,"host":"www.example.com"}', '{"mode":"shadow-tls","version":3,"host":"www.example.com"}');
                }
                if ($field['kind'] === 'json' && !isset($field['placeholder'])) {
                    $field['placeholder'] = self::text(self::jsonPlaceholder($type, $option), self::jsonPlaceholder($type, $option));
                }
                if (in_array($option, ['handshake-timeout', 'tran-window', 'ping', 'ping-restart', 'persistent-keepalive', 'workers', 'mtu', 'physical-mtu'], true)) $field['min'] = 0;
                if (in_array($option, ['padding-min', 'padding-max'], true)) { $field['min'] = 0; $field['max'] = 100; }
                $choices = self::choices($type)[$option] ?? null;
                if ($choices !== null) $field['choices'] = array_map(function ($value) { return ['value' => $value, 'label' => self::text((string)$value, (string)$value)]; }, $choices);
            }
            unset($field);
        }
        unset($definition);
        return $catalog;
    }

    private static function defaults(string $type): array
    {
        $defaults = ['name' => '', 'host' => '', 'rate' => 1, 'group_id' => [], 'route_id' => [], 'tags' => [], 'parent_id' => null, 'show' => false, 'sort' => 0];
        if (!in_array($type, ['tailscale', 'zerotier', 'easytier'], true)) {
            $port = ['ssh' => 22, 'wireguard' => 51820, 'openvpn' => 1194][$type] ?? 443;
            $defaults['port'] = $port;
            // No listen port of its own: a blank one follows the connection
            // port when saved. A default 443 here stayed behind when the admin
            // changed only the connection port, and V2bX listened where no
            // client connected.
        }
        if (ManagedServer::supports($type)) $defaults['managed'] = true;
        $protocol = [
            'ssr' => ['cipher' => 'chacha20-ietf', 'ssr_protocol' => 'auth_sha1_v4', 'obfs' => 'tls1.2_ticket_auth', 'udp' => true],
            'http' => ['tls' => true, 'skip_cert_verify' => false],
            'socks5' => ['tls' => true, 'udp' => true, 'skip_cert_verify' => false],
            // A managed Snell node needs ShadowTLS v3; the administrator adds the host.
            'snell' => ['version' => 3, 'udp' => true, 'obfs_opts' => ['mode' => 'shadow-tls', 'version' => 3, 'host' => '']],
            'wireguard' => ['udp' => true],
            'shadowquic' => ['zero_rtt' => false],
            'gostrelay' => ['udp' => true, 'skip_cert_verify' => false],
            'mieru' => ['transport' => 'TCP', 'udp' => true],
            'sudoku' => ['aead_method' => 'chacha20-poly1305', 'padding_min' => 10, 'padding_max' => 30, 'table_type' => 'prefer_ascii'],
            'masque' => ['udp' => true, 'skip_cert_verify' => false],
            'openvpn' => ['proto' => 'udp', 'dev' => 'tun', 'udp' => true],
            'tailscale' => ['udp' => true], 'zerotier' => ['udp' => true], 'easytier' => ['udp' => true],
        ];
        return $defaults + ($protocol[$type] ?? []);
    }

    private static function common(): array
    {
        $items = [
            'name' => self::item('نام نود', 'Node name', 'نامی قابل تشخیص برای ادمین و کاربران بنویسید؛ این نام در اشتراک نمایش داده می‌شود.', 'Use a recognizable name for administrators and subscribers; it is displayed in subscriptions.'),
            'host' => self::item('آدرس اتصال', 'Connection address', 'دامنه یا IP قابل دسترس برای کاربر را بدون https://، پورت یا مسیر وارد کنید. در حالت TLS، دامنه باید با گواهی مطابقت داشته باشد.', 'Enter the subscriber-reachable domain or IP without https://, a port or a path. With TLS, the certificate must match the domain.'),
            'port' => self::item('پورت اتصال کاربر', 'Subscriber connection port', 'پورتی که کلاینت به آن وصل می‌شود؛ اگر NAT، CDN یا انتقال پورت دارید، پورت بیرونی را بنویسید.', 'The port clients connect to; with NAT, a CDN or port forwarding, enter the public-facing port.'),
            'server_port' => self::item('پورت شنود سرویس', 'Service listening port', 'در نود مدیریت‌شده، V2bX روی این پورت گوش می‌دهد؛ آن را در فایروال باز کنید. خالی بگذارید تا همان پورت اتصال باشد؛ فقط وقتی NAT یا انتقال پورت دارید عدد دیگری بنویسید.', 'For a managed node, V2bX listens on this port; allow it through the firewall. Leave blank to use the connection port; enter another only behind NAT or port forwarding.'),
            'rate' => self::item('ضریب مصرف', 'Traffic multiplier', '۱: مصرف واقعی؛ ۱٫۵: مصرف ×۱٫۵؛ ۰: رایگان. محدودیت سرعت نیست.', '1 = actual usage; 1.5 = 50% more; 0 = unmetered. Not a speed limit.'),
            'group_id' => self::item('گروه دسترسی', 'Access groups', 'حداقل یک گروه انتخاب کنید. فقط کاربران پلن‌هایی که به این گروه دسترسی دارند این نود را در اشتراک می‌بینند.', 'Select at least one group. Only subscribers whose plans grant access to these groups receive this node.'),
            'route_id' => self::item('قواعد مسیریابی', 'Routing rules', 'قواعد موجود پنل را انتخاب کنید؛ خالی یعنی قاعدهٔ اضافه‌ای از این فهرست به نود اختصاص ندارد. اعمال هر نوع قاعده به پشتیبانی هسته نیاز دارد.', 'Select existing panel rules; empty assigns no additional rules from this list. Applying a rule requires support in the selected core.'),
            'tags' => self::item('برچسب‌ها', 'Tags', 'برای دسته‌بندی نود، برچسب کوتاه بنویسید؛ مانند کشور یا نوع سرویس. برچسب اطلاعات ورود یا دامنهٔ اتصال نیست.', 'Add short classification tags, such as a country or service type. Tags are not credentials or connection domains.'),
            'parent_id' => self::item('نود والد', 'Parent node', 'فقط نود همین پروتکل را انتخاب کنید. مشخصات پروتکل و حالت مدیریت از والد می‌آیند؛ آدرس، گروه و ضریب این ردیف جدا هستند. نود اصلی را در V2bX ثبت کنید.', 'Choose a parent of the same protocol. Protocol settings and management mode are inherited; this row keeps its own endpoint, groups and multiplier. Register the root node in V2bX.'),
            'managed' => self::item('مدیریت کاربران با V2bX', 'Manage users with V2bX', 'برای سرور خودتان روشن بگذارید و نوع همین نود و ID نود اصلی را در V2bX با هستهٔ mihomo انتخاب کنید. برای معرفی یک سرویس بیرونی خاموش کنید؛ در آن حالت سرویس و حساب‌ها را بیرون پنل آماده کنید.', 'Keep enabled for your own server and configure this node type and root ID in V2bX with the mihomo core. Disable for an external service, whose server and accounts must be provisioned separately.'),
            'show' => self::item('نمایش در اشتراک', 'Visible in subscriptions', 'پس از آماده‌سازی و تست اتصال روشن کنید. خاموش بودن، نود را از اشتراک مخفی می‌کند و به‌تنهایی سرویس سرور را متوقف نمی‌کند.', 'Enable after provisioning and testing the connection. Hiding removes it from subscriptions; it does not by itself stop the server service.'),
            'sort' => self::item('ترتیب نمایش', 'Display order', 'عدد کوچک‌تر زودتر نمایش داده می‌شود؛ جابه‌جایی ردیف‌ها در جدول نیز ترتیب نودها را تنظیم می‌کند.', 'Lower numbers appear first; dragging rows in the node table also sets their order.'),
        ];
        $items['name']['placeholder'] = self::text('مثلاً آلمان — سرویس اصلی', 'For example: Germany — primary');
        $items['host']['placeholder'] = self::text('node.example.com', 'node.example.com');
        foreach (['port', 'server_port'] as $key) { $items[$key]['min'] = 1; $items[$key]['max'] = 65535; }
        $items['rate'] += ['min' => 0, 'max' => 99999999.99, 'step' => 0.01];
        $items['sort'] += ['min' => 0, 'max' => 2147483647];
        return $items;
    }

    private static function descriptions(): array
    {
        return [
            'ssr' => self::text('اتصال ShadowsocksR؛ رمزگذاری، پروتکل و استتار باید دقیقاً با سرور SSR یکسان باشند.', 'A ShadowsocksR connection; cipher, protocol and obfuscation must exactly match the SSR server.'),
            'socks5' => self::text('پراکسی SOCKS5 برای TCP و، در صورت فعال‌بودن، UDP؛ TLS اتصال کلاینت به سرویس را رمز می‌کند.', 'A SOCKS5 proxy for TCP and optional UDP; TLS encrypts the client-to-proxy connection.'),
            'http' => self::text('پراکسی HTTP/HTTPS برای TCP و CONNECT؛ روشن‌بودن TLS یعنی اتصال امن HTTPS به خود پراکسی.', 'An HTTP/HTTPS proxy for TCP and CONNECT; TLS secures the connection to the proxy itself.'),
            'snell' => self::text('اتصال Snell با کلید PSK؛ نسخه و استتار را از تنظیمات سرور بگیرید.', 'A Snell connection using a PSK; obtain the version and obfuscation settings from the server.'),
            'wireguard' => self::text('پروفایل کلاینت WireGuard یا AmneziaWG؛ آدرس تونل و کلیدهای کلاینت/همتا را از مدیر همان شبکه بگیرید.', 'A WireGuard or AmneziaWG client profile; obtain tunnel addresses and client/peer keys from that network administrator.'),
            'shadowquic' => self::text('اتصال QUIC با احراز هویت JLS؛ دامنه و ALPN باید با مقصد پوششی QUIC هماهنگ باشند.', 'A QUIC connection authenticated with JLS; its domain and ALPN must match the QUIC decoy destination.'),
            'gostrelay' => self::text('پروفایل GOST Relay؛ حساب، TLS و قابلیت‌های انتقال را مطابق سرویس GOST تنظیم کنید.', 'A GOST Relay profile; match credentials, TLS and forwarding capabilities to the GOST service.'),
            'ssh' => self::text('اتصال SSH به حساب دارای اجازهٔ TCP forwarding؛ از رمز یا کلید خصوصی کلاینت استفاده کنید.', 'An SSH account with TCP forwarding permission; authenticate using a password or client private key.'),
            'mieru' => self::text('اتصال Mieru با انتقال TCP یا UDP؛ مشخصات ورود و انتقال باید با سرور یکسان باشند.', 'A Mieru connection over TCP or UDP; credentials and transport must match the server.'),
            'sudoku' => self::text('اتصال Sudoku؛ کلید و تنظیمات AEAD، جدول و HTTP mask باید با سرویس مقصد هماهنگ باشند.', 'A Sudoku connection; match its key, AEAD, table and HTTP-mask settings to the destination service.'),
            'masque' => self::text('پروفایل MASQUE؛ کلیدهای EC، آدرس تونل و نوع HTTP/QUIC را از ارائه‌دهندهٔ سرویس دریافت کنید.', 'A MASQUE profile; obtain EC keys, tunnel addresses and the HTTP/QUIC mode from the service provider.'),
            'openvpn' => self::text('تنظیمات کلاینت OpenVPN را از فایل سرویس بگیرید؛ متن CA، گواهی و کلید را در فیلدهای مربوط وارد کنید.', 'Copy the client settings from the service OpenVPN profile; paste CA, certificate and key contents into the matching fields.'),
            'tailscale' => self::text('عضویت کلاینت در Tailscale یا Headscale؛ کلید ثبت و نود خروجی را در کنترلر همان شبکه آماده کنید.', 'Enroll a client in Tailscale or Headscale; prepare its registration key and exit node in that network controller.'),
            'zerotier' => self::text('عضویت کلاینت در شبکهٔ ZeroTier؛ شناسهٔ شبکه را وارد و دستگاه را در کنترلر شبکه مجاز کنید.', 'Join a ZeroTier network; enter its network ID and authorize the device in the network controller.'),
            'easytier' => self::text('عضویت کلاینت در شبکهٔ EasyTier؛ نام و رمز شبکه و نشانی همتاهای واقعی را از همان شبکه بگیرید.', 'Join an EasyTier network; obtain the network name, secret and real peer addresses from that network.'),
        ];
    }

    private static function capability(string $type): array
    {
        if (ManagedServer::supports($type)) {
            if ($type === 'shadowquic') return self::text('برای ساخت سرور، V2bX نسخهٔ 1.12.0 یا جدیدتر با هستهٔ mihomo را نصب و ID نود اصلی را ثبت کنید و پورت UDP را باز کنید؛ کاربران از پنل دریافت می‌شوند. V2bX کاوشگرها را به سایتی که در SNI نود آمده (پورت ۴۴۳) می‌فرستد، پس SNI را دامنهٔ سایتی با QUIC (HTTP/3) بگذارید؛ MihomoOptions.JLSUpstream سرور می‌تواند سایت دیگری تعیین کند.', 'To provision the server, install V2bX 1.12.0 or later with the mihomo core, register the root node ID and open its UDP port; the panel supplies users. V2bX hands probers to the site named in the node SNI on port 443, so set the SNI to a domain whose site serves QUIC (HTTP/3); the server MihomoOptions.JLSUpstream can name another.');
            if ($type === 'sudoku') return self::text('برای ساخت سرور، V2bX نسخهٔ 1.12.0 یا جدیدتر با هستهٔ mihomo را نصب و ID نود اصلی را ثبت کنید؛ کاربران، مصرف و محدودیت‌ها از پنل می‌آیند. کلاینت باید mihomo نسخهٔ 1.19.33 یا جدیدتر باشد؛ فرمت Sudoku در این نسخه عوض شد. V2bX کاوشگرها را به سایت پوششی خودش می‌برد. TLS ندارد؛ برای http-mask-tls یک CDN یا پراکسی TLS جلوی نود لازم است.', 'To provision the server, install V2bX 1.12.0 or later with the mihomo core and register the root node ID; the panel supplies users, usage and limits. Clients need Mihomo v1.19.33 or later, which changed the Sudoku wire format. V2bX hands probers to its own cover site. The node has no TLS: http-mask-tls needs a CDN or TLS proxy in front of it.');
            if ($type === 'snell') return self::text('برای ساخت سرور، V2bX نسخهٔ 1.12.0 یا جدیدتر با هستهٔ mihomo را نصب و ID نود اصلی را ثبت کنید؛ کاربران، مصرف و محدودیت‌ها از پنل می‌آیند. دست‌دهی ShadowTLS به host:443 در obfs-opts می‌رود، یا به MihomoOptions.ShadowTLSHandshake سرور؛ آن سرور باید گواهی همان host را داشته باشد. کلاینت باید Snell روی ShadowTLS را پشتیبانی کند، مثل Surge یا mihomo نسخهٔ 1.19.33.', 'To provision the server, install V2bX 1.12.0 or later with the mihomo core and register the root node ID; the panel supplies users, usage and limits. The ShadowTLS handshake goes to the obfs-opts host on port 443, or to the server\'s MihomoOptions.ShadowTLSHandshake, which must serve that host\'s certificate. Clients must support Snell over ShadowTLS, as Surge and Mihomo v1.19.33 do.');
            return self::text('برای ساخت سرور، V2bX نسخهٔ 1.12.0 یا جدیدتر با هستهٔ mihomo را نصب و نوع و ID نود اصلی را ثبت کنید؛ کاربران، مصرف و محدودیت‌ها از پنل می‌آیند. در صورت فعال‌بودن TLS، V2bX گواهی دامنهٔ نود را با CertConfig خودش می‌گیرد و تمدید می‌کند.', 'To provision the server, install V2bX 1.12.0 or later with the mihomo core and register the root node type and ID. The panel supplies users, usage and limits. With TLS enabled, V2bX obtains and renews the node domain certificate through its CertConfig.');
        }
        return self::text('ابتدا سرویس یا شبکهٔ مقصد و اطلاعات کلاینت را بیرون V2bX آماده کنید و سپس پروفایل آن را اینجا وارد کنید. این نوع در حال حاضر ورودی سرور چندکاربرهٔ مدیریت‌شده ندارد؛ ذخیرهٔ این فرم به‌تنهایی سرور، حساب یا محدودیت سمت سرویس ایجاد نمی‌کند.', 'Provision the destination service or network and client credentials outside V2bX first, then enter its profile here. This type currently has no managed multi-user server inbound; saving this form alone does not create its server, accounts or service-side limits.');
    }

    private static function required(): array
    {
        return ['ssr' => ['cipher', 'password', 'protocol', 'obfs'], 'snell' => ['psk'], 'wireguard' => ['private-key'], 'ssh' => ['username'], 'mieru' => ['transport'], 'sudoku' => ['key'], 'masque' => ['private-key', 'public-key'], 'openvpn' => ['ca'], 'zerotier' => ['network'], 'easytier' => ['network-name']];
    }

    private static function section(string $option): string
    {
        if (in_array($option, ['username', 'password', 'psk', 'key', 'private-key', 'private-key-passphrase', 'public-key', 'pre-shared-key', 'auth-key', 'identity-secret', 'network-secret'], true)) return 'credentials';
        if (in_array($option, ['tls', 'sni', 'skip-cert-verify', 'name-cert-verify', 'fingerprint', 'certificate', 'ca', 'cert', 'tls-auth', 'tls-crypt', 'tls-crypt-v2', 'key-direction', 'host-key', 'host-key-algorithms'], true)) return 'security';
        if (in_array($option, ['udp', 'transport', 'proto', 'cipher', 'protocol', 'obfs', 'version', 'ip', 'ipv6', 'port-range', 'hostname', 'exit-node', 'control-url', 'network', 'network-name', 'peers', 'listeners', 'aead-method', 'table-type', 'padding-min', 'padding-max'], true)) return 'connection';
        return 'advanced';
    }

    private static function overrides(string $type, string $option): array
    {
        $data = [];
        if ($option === 'private-key') {
            if ($type === 'wireguard') $data['help'] = self::text('کلید خصوصی پروفایل کلاینت WireGuard، دقیقاً ۳۲ بایت در قالب Base64؛ کلید خصوصی سرور را وارد نکنید. این مقدار در پروفایل کاربران این نود توزیع می‌شود.', 'The WireGuard client profile private key: exactly 32 bytes encoded as Base64. Do not enter the server private key. This value is distributed in this node\'s subscriber profiles.');
            elseif ($type === 'masque') $data['help'] = self::text('کلید خصوصی EC کلاینت در قالب DER و Base64، مطابق پروفایل ارائه‌دهنده؛ متن PEM یا کلید سرور V2bX را اینجا وارد نکنید.', 'The client EC private key in Base64-encoded DER, matching the provider profile; do not enter PEM or a V2bX server private key here.');
            elseif ($type === 'ssh') $data['help'] = self::text('متن کامل کلید خصوصی حساب SSH کلاینت را از خط BEGIN تا END جای‌گذاری کنید (Ed25519، ECDSA یا RSA)؛ مسیر فایل پذیرفته نمی‌شود. اگر رمز ورود دارید می‌توانید خالی بگذارید. کلید عمومیِ متناظر باید در authorized_keys همان حساب روی سرور باشد.', 'Paste the whole SSH client private key from BEGIN to END (Ed25519, ECDSA or RSA); file paths are not accepted. Leave blank if using a login password. Its public key must be in that account\'s authorized_keys on the server.');
        }
        if ($option === 'public-key') $data['help'] = $type === 'wireguard'
            ? self::text('کلید عمومی سرور/همتای WireGuard، دقیقاً ۳۲ بایت در قالب Base64؛ از تنظیمات همان همتا بگیرید.', 'The WireGuard server/peer public key: exactly 32 bytes encoded as Base64, obtained from that peer.')
            : self::text('کلید عمومی EC سرویس MASQUE در قالب DER و Base64؛ آن را دقیقاً از ارائه‌دهنده بگیرید.', 'The MASQUE service EC public key in Base64-encoded DER; obtain the exact value from the provider.');
        if ($type === 'openvpn' && $option === 'key') $data['help'] = self::text('متن کامل کلید خصوصی کلاینت OpenVPN در قالب PEM؛ باید با گواهی کلاینت در cert تطابق داشته باشد. با ورود نام کاربری/رمز می‌توان cert و key را خالی گذاشت.', 'The complete OpenVPN client private key in PEM; it must match cert. When using username/password authentication, cert and key may be left blank.');
        if ($type === 'zerotier' && $option === 'network') $data['help'] = self::text('شناسهٔ شبکهٔ ساخته‌شده در کنترلر ZeroTier، دقیقاً ۱۶ رقم هگزادسیمال؛ مانند نمونهٔ قالب. دستگاه جدید را نیز در همان کنترلر مجاز کنید.', 'The network ID from the ZeroTier controller, exactly 16 hexadecimal characters; authorize newly joined devices in that controller.');
        if ($type === 'masque' && $option === 'network') $data['help'] = self::text('نوع سرویس ارائه‌دهنده را انتخاب کنید: h2 برای HTTP/2، h3 برای HTTP/3 یا h3-l4proxy. برای h3-l4proxy آدرس ip/ipv6 اختیاری است.', 'Select the provider mode: h2 for HTTP/2, h3 for HTTP/3, or h3-l4proxy. Client ip/ipv6 is optional for h3-l4proxy.');
        if ($type === 'zerotier' && $option === 'mtu') { $data['help'] = self::text('MTU شبکهٔ مجازی به بایت؛ صفر یا خالی برای پیش‌فرض سرویس، و مقدار دستی بین ۱۲۸۰ تا ۱۰۰۰۰ است. مقدار را از مدیر شبکه بگیرید.', 'Virtual-network MTU in bytes: zero/blank uses the service default; a manual value must be 1280 to 10000. Obtain it from the network administrator.'); $data['max'] = 10000; }
        if ($type === 'zerotier' && $option === 'physical-mtu') { $data['help'] = self::text('MTU مسیر زیرین به بایت؛ صفر یا خالی برای پیش‌فرض هسته، و مقدار دستی بین ۵۱۰ تا ۱۰۳۲۴ است؛ با MTU مجازی شبکه تفاوت دارد.', 'Underlying-path MTU in bytes: zero/blank uses the core default; a manual value must be 510 to 10324. This differs from virtual-network MTU.'); $data['max'] = 10324; }
        if ($type === 'zerotier' && $option === 'remote-trace-level') $data += ['min' => 0, 'max' => 30];
        if ($type === 'zerotier' && in_array($option, ['primary-port', 'secondary-port'], true)) $data += ['min' => 0, 'max' => 65535];
        if ($type === 'wireguard' && $option === 'peers') $data['help'] = self::text('برای چند همتا، آرایه‌ای از اشیای server، port، public-key و allowed-ips وارد کنید. با پرکردن این فهرست، آدرس و کلید عمومی تک‌همتا کنار گذاشته می‌شوند؛ private-key و آدرس تونل همچنان لازم‌اند.', 'For multiple peers enter objects containing server, port, public-key and allowed-ips. This replaces the single-peer endpoint/public key; the client private key and tunnel address remain required.');
        if ($type === 'wireguard' && $option === 'ip') $data['help'] = self::text('IPv4 اختصاص‌یافته به همین کلاینت در تونل، با یا بدون CIDR؛ این IP عمومی سرور نیست. حداقل ip یا ipv6 را از پروفایل واقعی سرویس وارد کنید.', 'The tunnel IPv4 assigned to this client, optionally with CIDR; not the server public IP. Supply at least ip or ipv6 from the actual service profile.');
        if ($type === 'easytier' && $option === 'peers') $data['help'] = self::text('آرایهٔ نشانی URI همتاهای موجود شبکه را وارد کنید، مثلاً tcp://node.example.com:11010. حداقل یک peers یا listeners لازم است، مگر no-listener را صریحاً خاموش کرده باشید.', 'Enter an array of existing peer URIs, for example tcp://node.example.com:11010. Supply peers or listeners unless no-listener is explicitly disabled.');
        if ($type === 'ssh' && $option === 'password') $data['help'] = self::text('رمز ورود همان حساب SSH؛ اگر private-key را وارد می‌کنید می‌توانید خالی بگذارید. حساب سرور باید اجازهٔ TCP forwarding داشته باشد.', 'That SSH account\'s login password; leave blank when using private-key. The server account must permit TCP forwarding.');
        if ($type === 'openvpn' && in_array($option, ['username', 'password'], true)) $data['help'] = self::text('برای ورود با حساب OpenVPN، نام کاربری و رمز هر دو لازم‌اند؛ برای ورود با گواهی، cert و key معتبر را پر کنید و این دو فیلد را خالی بگذارید.', 'Username/password authentication requires both values. For certificate authentication, provide valid cert and key and leave these two fields blank.');
        if ($option === 'version') $data += ['min' => 1, 'max' => 5];
        if ($option === 'ip' || $option === 'ipv4') $data['placeholder'] = self::text('آدرس اختصاص‌یافته توسط سرویس', 'Address assigned by the service');
        if ($option === 'sni') $data['placeholder'] = self::text('خالی: استفاده از آدرس اتصال', 'Blank: use the connection address');
        if ($option === 'port-range') $data['placeholder'] = self::text('22000-22010', '22000-22010');
        if ($type === 'zerotier' && $option === 'network') $data['placeholder'] = self::text('0123456789abcdef', '0123456789abcdef');
        return $data;
    }

    private static function jsonPlaceholder(string $type, string $option): string
    {
        if ($option === 'peers') return $type === 'wireguard' ? '[{"server":"node.example.com","port":51820,"public-key":"BASE64_PUBLIC_KEY","allowed-ips":["0.0.0.0/0"]}]' : '["tcp://node.example.com:11010"]';
        if ($option === 'headers') return '{"X-Header":"value"}';
        if ($option === 'reserved') return '[0,0,0]';
        if ($option === 'allowed-ips') return '["0.0.0.0/0","::/0"]';
        if ($option === 'alpn') return '["h3"]';
        if ($option === 'quic-versions') return '["v1","v2"]';
        if ($option === 'orbit') return '[{"world":"8056c2e21c000001","seed":"0f1b56d269"}]';
        if ($option === 'listeners') return '["tcp://0.0.0.0:11010"]';
        if ($option === 'host-key') return '["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA…"]';
        if ($option === 'host-key-algorithms') return '["ssh-ed25519"]';
        if ($option === 'ip-stack') return '{"mode":"auto"}';
        if (in_array($option, ['headers', 'obfs-opts', 'amnezia-wg-option', 'httpmask', 'peer-info'], true)) return '{}';
        return '[]';
    }

    private static function choices(string $type): array
    {
        $all = [
            'ssr' => ['cipher' => ['none', 'dummy', 'aes-128-ctr', 'aes-192-ctr', 'aes-256-ctr', 'aes-128-cfb', 'aes-192-cfb', 'aes-256-cfb', 'rc4-md5', 'chacha20', 'chacha20-ietf', 'xchacha20'], 'protocol' => ['origin', 'auth_sha1_v4', 'auth_aes128_md5', 'auth_aes128_sha1', 'auth_chain_a', 'auth_chain_b'], 'obfs' => ['plain', 'http_simple', 'http_post', 'random_head', 'tls1.2_ticket_auth', 'tls1.2_ticket_fastauth']],
            'snell' => ['version' => [1, 2, 3, 4, 5]],
            'mieru' => ['transport' => ['TCP', 'UDP'], 'multiplexing' => ['MULTIPLEXING_DEFAULT', 'MULTIPLEXING_OFF', 'MULTIPLEXING_LOW', 'MULTIPLEXING_MIDDLE', 'MULTIPLEXING_HIGH'], 'handshake-mode' => ['HANDSHAKE_DEFAULT', 'HANDSHAKE_STANDARD', 'HANDSHAKE_NO_WAIT']],
            'sudoku' => ['aead-method' => ['aes-128-gcm', 'chacha20-poly1305', 'none'], 'table-type' => ['prefer_ascii', 'prefer_entropy', 'up_ascii_down_entropy', 'up_entropy_down_ascii'], 'http-mask-mode' => ['legacy', 'stream', 'poll', 'auto', 'ws'], 'multiplex' => ['off', 'on', 'auto'], 'http-mask-multiplex' => ['off', 'on', 'auto']],
            'openvpn' => ['proto' => ['udp', 'udp4', 'tcp', 'tcp-client', 'tcp4', 'tcp4-client'], 'dev' => ['tun'], 'key-direction' => ['0', '1']],
            'masque' => ['network' => ['h2', 'h3', 'h3-l4proxy']],
            'zerotier' => ['tcp-fallback-mode' => ['auto', 'force', 'disable']],
        ];
        return $all[$type] ?? [];
    }

    private static function copy(): array
    {
        // Each row explicitly explains one real Mihomo option. Keep protocol-
        // specific meanings in overrides() rather than guessing from its name.
        $rows = [
            'username' => ['نام کاربری', 'Username', 'نام کاربری صادرشده توسط سرویس مقصد را وارد کنید؛ فاصله‌های ابتدا و انتها بخشی از مقدار هستند.', 'Enter the username issued by the destination service; leading and trailing spaces are preserved.'],
            'password' => ['رمز عبور', 'Password', 'رمز همان سرویس را وارد کنید؛ این رمز در تنظیمات اشتراک کلاینت قرار می‌گیرد و رمز ورود پنل نیست.', 'Enter that service\'s password. It is included in the client subscription and is not the panel login password.'],
            'psk' => ['کلید مشترک Snell', 'Snell PSK', 'کلید PSK تنظیم‌شده روی سرویس Snell را دقیقاً کپی کنید؛ با نام کاربری یا رمز پنل جایگزین نکنید.', 'Copy the exact PSK configured on the Snell service; do not use a panel username or password.'],
            'cipher' => ['روش رمزگذاری', 'Cipher', 'روش رمزگذاری را دقیقاً مطابق تنظیم سرور انتخاب کنید؛ تغییر یک‌طرفه باعث قطع اتصال می‌شود.', 'Match the server encryption method exactly; changing one side alone breaks connectivity.'],
            'protocol' => ['پروتکل SSR', 'SSR protocol', 'روش احراز هویت/انتقال SSR را از تنظیمات سرور انتخاب کنید؛ با نوع نود پنل متفاوت است.', 'Select the SSR authentication/transport protocol from the server configuration; this is not the panel node type.'],
            'protocol-param' => ['پارامتر پروتکل SSR', 'SSR protocol parameter', 'پارامتر تکمیلی پروتکل SSR را فقط اگر سرویس داده است وارد کنید؛ برای پروتکل بدون پارامتر خالی بگذارید.', 'Enter the additional SSR protocol parameter only if the service specifies one; otherwise leave blank.'],
            'obfs' => ['روش استتار SSR', 'SSR obfuscation', 'روش obfs را مطابق سرور SSR انتخاب کنید؛ نام دامنه یا مقدار پارامتر در obfs-param قرار می‌گیرد.', 'Match the SSR server obfs method; domain/parameter values belong in obfs-param.'],
            'obfs-param' => ['پارامتر استتار SSR', 'SSR obfuscation parameter', 'مقدار obfs-param اعلام‌شده توسط سرور، مثلاً نام میزبان استتار؛ اگر لازم نیست خالی بگذارید.', 'The server-provided obfs-param, such as the obfuscation host; leave blank when unused.'],
            'version' => ['نسخهٔ Snell', 'Snell version', 'نسخهٔ واقعی سرور Snell را انتخاب کنید؛ پیش‌فرض فرم ۳ و سازگار با UDP است. نسخه‌های ۱ و ۲ UDP ندارند؛ نسخهٔ ۵ با روش سازگار نسخهٔ ۴ متصل می‌شود.', 'Select the actual Snell server version; this form defaults to UDP-capable version 3. Versions 1 and 2 do not support UDP; version 5 uses v4-compatible connections.'],
            'udp' => ['انتقال UDP', 'UDP forwarding', 'در صورت پشتیبانی سرور و نیاز برنامه‌ها به UDP روشن کنید؛ این گزینه پورت شنود TCP/UDP سرور را به‌تنهایی تغییر نمی‌دهد.', 'Enable when the service supports UDP and applications need it; this alone does not change the server listening transport.'],
            'tls' => ['اتصال با TLS', 'TLS connection', 'برای رمزکردن اتصال کلاینت به پراکسی روشن کنید. گواهی معتبر برای دامنه لازم است؛ مسیر کلید و گواهی سرور در V2bX تنظیم می‌شود.', 'Enable to encrypt the client-to-proxy connection. A valid domain certificate is required; configure server key/certificate paths in V2bX.'],
            'sni' => ['نام دامنهٔ TLS / SNI', 'TLS server name / SNI', 'دامنه‌ای که سرویس در TLS انتظار دارد، بدون پورت و https://؛ خالی یعنی استفاده از آدرس اتصال. گواهی باید همین نام را پوشش دهد.', 'The domain expected by the TLS service, without a port or https://; blank uses the connection address. The certificate must cover this name.'],
            'skip-cert-verify' => ['صرف‌نظر از اعتبارسنجی گواهی', 'Skip certificate verification', 'خاموش نگه دارید تا اعتبار و نام گواهی بررسی شود. روشن‌کردن، اعتبارسنجی TLS را حذف می‌کند و فقط برای سرویس آگاهانهٔ فاقد گواهی معتبر مناسب است.', 'Keep disabled to verify the certificate and hostname. Enabling removes TLS verification and is only appropriate for a deliberately configured untrusted-certificate service.'],
            'name-cert-verify' => ['نام مورد انتظار گواهی', 'Expected certificate name', 'نام مجاز گواهی را فقط برای سیاست بررسی نام سفارشی وارد کنید؛ در حالت عادی خالی بگذارید تا نام اتصال/SNI بررسی شود.', 'Set only for a custom certificate-name verification policy; normally leave blank to verify the connection/SNI name.'],
            'fingerprint' => ['اثر انگشت گواهی', 'Certificate fingerprint', 'اثر انگشت SHA-256 گواهی سرویس برای pinning، ۶۴ رقم Hex با یا بدون دونقطه؛ این گزینه با شبیه‌سازی مرورگر در client-fingerprint تفاوت دارد.', 'The SHA-256 fingerprint of the service certificate for pinning: 64 hex digits, colons optional. This differs from browser emulation in client-fingerprint.'],
            'client-fingerprint' => ['الگوی TLS کلاینت', 'Client TLS fingerprint', 'الگوی TLS مورد نیاز سرویس، مانند chrome؛ برای رفتار پیش‌فرض هسته خالی بگذارید. این مقدار اثر انگشت گواهی نیست.', 'The client TLS profile required by the service, such as chrome; leave blank for core defaults. This is not a certificate fingerprint.'],
            'certificate' => ['گواهی کلاینت TLS', 'TLS client certificate', 'متن کامل PEM گواهی کلاینت برای mTLS همراه با private-key متناظر؛ مسیر فایل پذیرفته نمی‌شود چون روی دستگاه کاربران وجود ندارد. گواهی سرور V2bX نیست و در اشتراک توزیع می‌شود.', 'The full PEM client certificate for mTLS, together with its private-key. File paths are not accepted because subscribers\' devices do not have them. This is not the V2bX server certificate and is distributed in subscriptions.'],
            'private-key' => ['کلید خصوصی کلاینت', 'Client private key', 'متن کامل PEM کلید خصوصی بدون رمز متناظر با certificate برای mTLS؛ مسیر فایل پذیرفته نمی‌شود. کلید خصوصی سرور V2bX را اینجا وارد نکنید.', 'The full unencrypted PEM private key matching certificate for mTLS; file paths are not accepted. Do not enter the V2bX server private key here.'],
            'private-key-passphrase' => ['عبارت عبور کلید SSH', 'SSH private-key passphrase', 'فقط برای کلید PEM رمزدار قدیمی (BEGIN RSA یا EC PRIVATE KEY با Proc-Type) لازم است؛ کلید OpenSSH رمزدار پذیرفته نمی‌شود، پس رمز آن را بردارید. برای کلید بدون رمز خالی بگذارید.', 'Needed only for a legacy encrypted PEM key (BEGIN RSA or EC PRIVATE KEY with Proc-Type). Encrypted OpenSSH keys are not accepted, so remove their passphrase. Leave blank for an unencrypted key.'],
            'public-key' => ['کلید عمومی سرویس', 'Service public key', 'کلید عمومی سرویس مقصد را دقیقاً از پروفایل همان سرویس کپی کنید؛ کلید خصوصی را در این فیلد نگذارید.', 'Copy the destination service public key exactly from its profile; do not enter a private key here.'],
            'pre-shared-key' => ['کلید مشترک WireGuard', 'WireGuard pre-shared key', 'PSK اختیاری WireGuard را در قالب Base64 با طول رمزگشایی‌شدهٔ ۳۲ بایت وارد کنید؛ باید با همتای مقصد یکسان باشد.', 'Optional WireGuard PSK, Base64 encoding exactly 32 decoded bytes; it must match the destination peer.'],
            'ip' => ['IPv4 کلاینت در تونل', 'Client tunnel IPv4', 'IPv4 اختصاص‌یافته به کلاینت را از پروفایل سرویس وارد کنید؛ آدرس عمومی سرور در آدرس اتصال قرار می‌گیرد.', 'Enter the client-assigned tunnel IPv4 from the service profile; the public server IP belongs in the connection address.'],
            'ipv4' => ['IPv4 دستگاه در شبکه', 'Device network IPv4', 'IPv4/CIDR مجاز برای این دستگاه در شبکهٔ EasyTier را وارد کنید؛ اگر DHCP شبکه آدرس می‌دهد خالی بگذارید.', 'The IPv4/CIDR assigned to this EasyTier device; leave blank if the network uses DHCP.'],
            'ipv6' => ['IPv6 کلاینت در تونل', 'Client tunnel IPv6', 'IPv6 اختصاص‌یافته به این کلاینت را طبق پروفایل سرویس بنویسید؛ برای تونل صرفاً IPv4 خالی بگذارید.', 'Enter this client\'s assigned tunnel IPv6 from the service profile; leave blank for an IPv4-only tunnel.'],
            'reserved' => ['بایت‌های Reserved', 'Reserved bytes', 'فقط اگر ارائه‌دهنده خواسته، آرایهٔ دقیقاً سه عدد صحیح ۰ تا ۲۵۵ وارد کنید؛ در اتصال معمولی WireGuard خالی بگذارید.', 'Only if the provider requires it, enter exactly three integers from 0 to 255; leave blank for normal WireGuard.'],
            'allowed-ips' => ['شبکه‌های مجاز همتا', 'Peer allowed IPs', 'آرایهٔ شبکه‌ها با CIDR که از همتا عبور می‌کنند؛ 0.0.0.0/0 و ::/0 مسیر همهٔ IPv4/IPv6 را به همان همتا می‌دهند.', 'An array of CIDR networks routed through the peer; 0.0.0.0/0 and ::/0 route all IPv4/IPv6 traffic to it.'],
            'peers' => ['همتاهای شبکه', 'Network peers', 'آرایهٔ همتاهای واقعی شبکه را طبق قالب پروتکل وارد کنید؛ نمونهٔ قالب، سرویس قابل اتصال ایجاد نمی‌کند.', 'Enter actual network peers in the protocol-specific array format; the example shape does not provision a reachable service.'],
            'workers' => ['تعداد workerها', 'Worker count', 'تعداد workerهای WireGuard در کلاینت؛ صفر یا خالی انتخاب خودکار هسته است. فقط با نیاز و منابع مشخص تغییر دهید.', 'The client WireGuard worker count; zero or blank uses the core automatic choice. Change only for a known resource requirement.'],
            'mtu' => ['MTU تونل', 'Tunnel MTU', 'اندازهٔ بستهٔ تونل به بایت؛ مقدار سرویس را وارد کنید. برای انتخاب پیش‌فرض هسته خالی بگذارید؛ مقدار زیاد می‌تواند اتصال را دچار قطعه‌قطعه‌شدن کند.', 'Tunnel packet size in bytes; use the service value. Leave blank for the core default; excessive values can cause fragmentation.'],
            'persistent-keepalive' => ['دورهٔ Keepalive WireGuard', 'WireGuard keepalive interval', 'فاصلهٔ keepalive به ثانیه برای حفظ NAT؛ مقدار مورد نیاز همتا را وارد کنید و برای پیش‌فرض سرویس خالی بگذارید.', 'Keepalive interval in seconds for NAT mappings; use the peer-required value or leave blank for the service default.'],
            'ip-stack' => ['پشتهٔ IP', 'IP stack', 'شیء JSON تنظیمات پشتهٔ شبکهٔ کلاینت؛ mode می‌تواند auto، gvisor یا mips باشد. برای انتخاب خودکار خالی بگذارید.', 'A JSON object for the client network stack; mode may be auto, gvisor or mips. Leave blank for automatic selection.'],
            'amnezia-wg-option' => ['گزینه‌های AmneziaWG', 'AmneziaWG options', 'شیء JSON پارامترهای استتار AmneziaWG را از پروفایل سرور بگیرید؛ مقادیر دو سمت باید هماهنگ باشند. برای WireGuard معمولی خالی بگذارید.', 'Copy the AmneziaWG obfuscation JSON object from the server profile; both sides must agree. Leave blank for standard WireGuard.'],
            'remote-dns-resolve' => ['حل DNS در تونل', 'Resolve DNS through the tunnel', 'برای حل نام مقصد از مسیر شبکهٔ تونل فعال کنید؛ در این حالت فهرست dns را طبق سرویس تنظیم کنید.', 'Enable to resolve destination names through the tunnel network; configure dns according to the service.'],
            'dns' => ['سرورهای DNS تونل', 'Tunnel DNS servers', 'آرایهٔ نشانی DNSهای قابل دسترس از تونل؛ مقدار را از مدیر شبکه بگیرید. خالی از رفتار پیش‌فرض هسته استفاده می‌کند.', 'An array of DNS addresses reachable through the tunnel; obtain them from the network administrator. Blank uses core defaults.'],
            'refresh-server-ip-interval' => ['دورهٔ تازه‌سازی IP سرور', 'Server IP refresh interval', 'دورهٔ حل مجدد نام میزبان سرور WireGuard به ثانیه؛ فقط برای سرویس دارای IP متغیر تنظیم کنید، وگرنه خالی بگذارید.', 'Interval in seconds for re-resolving the WireGuard server hostname; set for a service with changing IPs, otherwise leave blank.'],
            'alpn' => ['فهرست ALPN', 'ALPN list', 'آرایهٔ پروتکل‌های TLS مانند ["h3"]؛ در ShadowQUIC باید با مقصد پوششی و تنظیم سرور یکسان باشد. خالی پیش‌فرض هسته را نگه می‌دارد.', 'An array of TLS protocols such as ["h3"]; for ShadowQUIC it must match the decoy and server settings. Blank keeps core defaults.'],
            'quic-versions' => ['نسخه‌های QUIC', 'QUIC versions', 'آرایهٔ نسخه‌های QUIC مانند ["v1","v2"]؛ فقط نسخه‌های پشتیبانی‌شدهٔ سرویس را انتخاب کنید. خالی از انتخاب هسته استفاده می‌کند.', 'An array of QUIC versions such as ["v1","v2"]; select versions supported by the service. Blank uses core selection.'],
            'udp-over-stream' => ['UDP روی stream', 'UDP over streams', 'برای انتقال بسته‌های UDP در streamهای قابل اعتماد QUIC روشن کنید؛ در غیر این صورت datagram استفاده می‌شود. باید با رفتار سرویس سازگار باشد.', 'Enable to carry UDP packets over reliable QUIC streams instead of datagrams; the service must support this mode.'],
            'zero-rtt' => ['دادهٔ اولیهٔ 0-RTT', '0-RTT early data', 'پس از دریافت ticket در اتصال قبلی، تأخیر شروع را کم می‌کند. فقط اگر سرویس این سیاست را پذیرفته است روشن کنید؛ پیش‌فرض فرم خاموش است.', 'Reduces startup latency after a previous connection supplies a session ticket. Enable only when the service permits this policy; the form defaults to disabled.'],
            'keep-alive-interval' => ['فاصلهٔ Keepalive QUIC', 'QUIC keepalive interval', 'فاصلهٔ بسته‌های keepalive به میلی‌ثانیه؛ فقط اگر NAT یا سرویس نیاز دارد تنظیم کنید. خالی رفتار پیش‌فرض هسته است.', 'Keepalive packet interval in milliseconds; set only when required by NAT or the service. Blank uses core defaults.'],
            'congestion-controller' => ['کنترل ازدحام', 'Congestion controller', 'الگوریتم کنترل ازدحام مورد پشتیبانی همین سرویس را وارد کنید؛ بدون نیاز مشخص خالی بگذارید تا پیش‌فرض هسته اعمال شود.', 'Enter a congestion algorithm supported by this service; otherwise leave blank to use the core default.'],
            'up' => ['نرخ uplink پروتکل', 'Protocol uplink rate', 'نرخ تخمینی آپلود با واحد، مانند 100 Mbps، برای تنظیم کنترل ازدحام؛ محدودیت سرعت پلن یا ضریب مصرف پنل نیست.', 'Estimated upload rate with a unit, for example 100 Mbps, for congestion tuning; not the plan speed limit or traffic multiplier.'],
            'down' => ['نرخ downlink پروتکل', 'Protocol downlink rate', 'نرخ تخمینی دانلود با واحد، مانند 100 Mbps، برای تنظیم کنترل ازدحام؛ با ظرفیت واقعی سرویس هماهنگ کنید.', 'Estimated download rate with a unit, for example 100 Mbps, for congestion tuning; match the actual service capacity.'],
            'cwnd' => ['پنجرهٔ ازدحام', 'Congestion window', 'اندازهٔ پنجرهٔ ازدحام پروتکل؛ فقط با نیاز الگوریتم انتخابی تنظیم کنید. خالی پیش‌فرض هسته را نگه می‌دارد.', 'The protocol congestion-window size; set only for the selected algorithm\'s requirements. Blank keeps core defaults.'],
            'bbr-profile' => ['پروفایل BBR', 'BBR profile', 'پروفایل BBR سازگار با سرویس و هسته را وارد کنید؛ وقتی الگوریتم دیگری دارید یا مطمئن نیستید خالی بگذارید.', 'Enter a BBR profile supported by the service and core; leave blank for another algorithm or when unsure.'],
            'recv-window-conn' => ['پنجرهٔ دریافت stream', 'Stream receive window', 'حد پنجرهٔ دریافت هر stream در این پیاده‌سازی، به بایت؛ نام گزینهٔ Mihomo recv-window-conn است. برای تنظیم خودکار خالی بگذارید.', 'Per-stream receive window in bytes in this implementation; the Mihomo option is recv-window-conn. Leave blank for automatic sizing.'],
            'recv-window' => ['پنجرهٔ دریافت اتصال', 'Connection receive window', 'حد مجموع پنجرهٔ دریافت اتصال QUIC به بایت؛ با حافظه و ظرفیت سرویس هماهنگ کنید یا خالی بگذارید.', 'Total QUIC connection receive window in bytes; match available memory and service capacity, or leave blank.'],
            'disable-mtu-discovery' => ['غیرفعال‌کردن کشف MTU', 'Disable path MTU discovery', 'فقط برای مسیری که کشف MTU مشکل دارد روشن کنید؛ در حالت معمول خاموش بماند تا QUIC اندازهٔ بسته را تشخیص دهد.', 'Enable only on a path with broken MTU discovery; normally keep disabled so QUIC can determine packet size.'],
            'max-datagram-frame-size' => ['حد اندازهٔ datagram', 'Maximum datagram frame size', 'حد اندازهٔ فریم datagram به بایت؛ در ShadowQUIC خالی یا صفر از پیش‌فرض ۱۴۰۰ استفاده می‌کند.', 'Maximum datagram frame size in bytes; ShadowQUIC uses 1400 when omitted or zero.'],
            'max-open-streams' => ['حد streamهای باز', 'Maximum open streams', 'حد streamهای QUIC در کلاینت؛ خالی یا صفر پیش‌فرض ۱۰۲۴ است. این گزینه محدودیت اتصال کاربر در پنل نیست.', 'Client QUIC stream limit; omitted or zero uses 1024. This is not the panel per-user connection limit.'],
            'forward' => ['حالت Forward در GOST', 'GOST forwarding mode', 'حالت forward را فقط برای سرویس GOST که همین قابلیت را انتظار دارد روشن کنید؛ باید با سمت مقصد هماهنگ باشد.', 'Enable forwarding mode only when the GOST service expects it; match the destination configuration.'],
            'mux' => ['تجمیع اتصال GOST', 'GOST multiplexing', 'برای چند stream روی یک اتصال GOST روشن کنید، مشروط به فعال‌بودن پشتیبانی mux در سرویس مقصد.', 'Enable multiple streams over a GOST connection only if the destination service supports mux.'],
            'host-key' => ['کلیدهای میزبان SSH', 'SSH host keys', 'آرایهٔ کلیدهای عمومی میزبان سرور، هر کدام با قالب «ssh-ed25519 AAAA…»، مانند محتوای /etc/ssh/ssh_host_ed25519_key.pub یا یک خط ssh-keyscan. اگر host-key-algorithms خالی باشد، پنل آن را از نوع همین کلیدها می‌سازد تا سرور همان کلید را ارائه دهد. خالی، بررسی کلید میزبان را حذف می‌کند؛ این کلید ورود کلاینت نیست.', 'An array of server host public keys, each like "ssh-ed25519 AAAA…", such as /etc/ssh/ssh_host_ed25519_key.pub or one ssh-keyscan line. When host-key-algorithms is blank, the panel derives it from these key types so the server presents a pinned key. Leaving it blank disables host-key verification; these are not client login keys.'],
            'host-key-algorithms' => ['الگوریتم‌های کلید میزبان SSH', 'SSH host-key algorithms', 'معمولاً خالی بگذارید؛ با کلید میزبان، پنل آن را خودکار می‌سازد. اگر همراه کلید میزبان پر کنید، فقط الگوریتم کلیدهای واردشده (مثل ssh-ed25519 یا rsa-sha2-256) مجاز است، چون با الگوریتم دیگر سرور کلید دیگری ارائه می‌دهد.', 'Usually leave blank; with host keys, the panel fills it in. If set alongside host keys, only those keys\' algorithms (such as ssh-ed25519 or rsa-sha2-256) are allowed, because another algorithm makes the server present a different key.'],
            'transport' => ['نوع انتقال Mieru', 'Mieru transport', 'TCP یا UDP مطابق شنوندهٔ سرور؛ اگر UDP انتخاب شد پورت UDP سرویس نیز باید باز باشد.', 'TCP or UDP matching the server listener; when selecting UDP, the service UDP port must be reachable.'],
            'port-range' => ['بازهٔ پورت Mieru', 'Mieru port range', 'برای سرویس بیرونی بازهٔ ابتدا-انتها بنویسید؛ در این حالت پورت اتصال تکی باید خالی باشد. نود مدیریت‌شدهٔ V2bX از یک server_port استفاده می‌کند و این فیلد باید خالی بماند.', 'For an external service enter first-last and clear the single connection port. A V2bX-managed node uses one server_port; leave this range blank.'],
            'multiplexing' => ['سطح multiplexing در Mieru', 'Mieru multiplexing level', 'سطح تجمیع اتصال‌های Mieru را انتخاب کنید؛ برای رفتار عادی MULTIPLEXING_DEFAULT یا مقدار خالی را نگه دارید.', 'Select the Mieru connection multiplexing level; keep MULTIPLEXING_DEFAULT or leave blank for normal behavior.'],
            'handshake-mode' => ['حالت دست‌دهی Mieru', 'Mieru handshake mode', 'روش handshake را فقط مطابق سرویس تغییر دهید؛ حالت DEFAULT رفتار عادی هسته را نگه می‌دارد.', 'Change the handshake mode only to match the service; DEFAULT keeps normal core behavior.'],
            'traffic-pattern' => ['الگوی ترافیک Mieru', 'Mieru traffic pattern', 'رشتهٔ Base64 الگوی ترافیک را همان‌طور که mieru تولید کرده و با سرویس توافق شده است وارد کنید؛ برای سرویس بدون الگوی سفارشی خالی بگذارید.', 'Enter the Base64 traffic pattern generated by mieru and agreed with the service, unchanged; leave blank when no custom pattern is used.'],
            'key' => ['کلید پروتکل', 'Protocol key', 'کلید مشترک Sudoku را دقیقاً از سرویس مقصد بگیرید؛ دو سمت باید یک کلید داشته باشند. مقدار نمونه یا رمز پنل را وارد نکنید.', 'Obtain the exact Sudoku shared key from the destination; both sides must match. Do not use a sample or panel password.'],
            'aead-method' => ['رمزگذاری AEAD', 'AEAD encryption', 'روش AEAD سرویس Sudoku را انتخاب کنید؛ chacha20-poly1305 پیش‌فرض هسته است و none رمزگذاری این لایه را غیرفعال می‌کند.', 'Match the Sudoku service AEAD; chacha20-poly1305 is the core default and none disables encryption at this layer.'],
            'padding-min' => ['حداقل Padding', 'Minimum padding', 'کمترین نسبت درج padding از ۰ تا ۱۰۰؛ پیش‌فرض هسته ۱۰ است و نباید از padding-max بیشتر باشد.', 'Minimum padding insertion ratio from 0 to 100; the core default is 10 and it must not exceed padding-max.'],
            'padding-max' => ['حداکثر Padding', 'Maximum padding', 'بیشترین نسبت درج padding از ۰ تا ۱۰۰؛ پیش‌فرض هسته ۳۰ است و باید حداقل به اندازهٔ padding-min باشد.', 'Maximum padding insertion ratio from 0 to 100; the core default is 30 and it must be at least padding-min.'],
            'table-type' => ['نوع جدول Sudoku', 'Sudoku table type', 'روش ساخت جدول استتار را مطابق سرور انتخاب کنید؛ حالت prefer_ascii انتخاب پیش‌فرض است.', 'Match the server obfuscation-table type; prefer_ascii is the default selection.'],
            'enable-pure-downlink' => ['دانلود Pure Downlink', 'Pure downlink', 'شیوهٔ pure downlink در Sudoku را فقط مطابق قابلیت سرویس روشن یا خاموش کنید؛ خالی رفتار پیش‌فرض هسته را نگه می‌دارد.', 'Change Sudoku pure-downlink behavior only to match service capabilities; blank keeps the core default.'],
            'http-mask' => ['استتار HTTP در Sudoku', 'Sudoku HTTP mask', 'HTTP mask را مطابق تنظیم سرور فعال کنید؛ گزینه‌های http-mask-mode و host باید با همان سرویس هماهنگ باشند.', 'Enable HTTP masking to match the server; its mode and host options must agree with that service.'],
            'http-mask-mode' => ['حالت HTTP mask', 'HTTP-mask mode', 'حالت HTTP mask سرویس را انتخاب کنید؛ تغییر یک‌طرفهٔ legacy، stream، poll یا ws اتصال را ناسازگار می‌کند.', 'Select the service HTTP-mask mode; changing legacy, stream, poll or ws on one side alone can break compatibility.'],
            'http-mask-tls' => ['TLS برای HTTP mask', 'HTTP-mask TLS', 'در صورت انتظار TLS روی سرویس HTTP mask فعال کنید؛ باید با listener و گواهی همان سرویس سازگار باشد.', 'Enable if the HTTP-mask service expects TLS; match its listener and certificate configuration.'],
            'http-mask-host' => ['میزبان HTTP mask', 'HTTP-mask host', 'نام Host مورد انتظار سرویس استتار HTTP را بدون scheme یا مسیر بنویسید؛ مقدار را از سرویس بگیرید.', 'Enter the Host name expected by the HTTP-mask service without a scheme or path; obtain it from the service.'],
            'path-root' => ['ریشهٔ مسیر HTTP mask', 'HTTP-mask path root', 'فقط یک قطعهٔ مسیر با حروف، عدد، _ یا - وارد کنید؛ نام مسیر باید با سرویس یکسان باشد و URL کامل نیست.', 'Enter one path segment using letters, digits, _ or -; match the service path. This is not a complete URL.'],
            'multiplex' => ['تجمیع streamهای Sudoku', 'Sudoku stream multiplexing', 'حالت off، on یا auto را مطابق سرویس انتخاب کنید؛ اگر نیاز خاصی ندارید خالی بگذارید.', 'Select off, on or auto according to the service; leave blank unless a specific mode is needed.'],
            'http-mask-multiplex' => ['تجمیع اتصال HTTP mask', 'HTTP-mask multiplexing', 'تنظیم multiplex در HTTP mask؛ با multiplex عادی تداخل مفهومی دارد، بنابراین فقط تنظیم اعلام‌شدهٔ سرویس را وارد کنید.', 'HTTP-mask multiplexing mode; it overlaps the general multiplex option, so enter only the service-specified setting.'],
            'httpmask' => ['گزینه‌های کامل HTTP mask', 'Complete HTTP-mask options', 'شیء JSON با disable، mode، tls، host، path-root و multiplex؛ مقادیر این شیء بر فیلدهای تکی HTTP mask اولویت دارند.', 'A JSON object with disable, mode, tls, host, path-root and multiplex; its values override individual HTTP-mask fields.'],
            'custom-table' => ['جدول سفارشی Sudoku', 'Custom Sudoku table', 'الگوی جدول سفارشی سرویس را دقیقاً کپی کنید؛ اگر سرویس از table-type استاندارد استفاده می‌کند خالی بگذارید.', 'Copy the exact custom table pattern from the service; leave blank when it uses a standard table-type.'],
            'custom-tables' => ['فهرست جدول‌های سفارشی Sudoku', 'Custom Sudoku tables', 'آرایهٔ الگوهای جدول‌های سفارشی سرور؛ فقط مقادیر واقعی و سازگار با همان سرویس را وارد کنید.', 'An array of custom server table patterns; supply only actual patterns compatible with that service.'],
            'uri' => ['URI سرویس MASQUE', 'MASQUE service URI', 'مسیر یا URI مورد انتظار سرویس MASQUE را از ارائه‌دهنده بگیرید؛ در حالت عادی خالی بگذارید تا پیش‌فرض شبکه انتخاب شود.', 'Obtain the MASQUE request path/URI from the provider; normally leave blank for the selected network default.'],
            'network' => ['نوع شبکه', 'Network mode', 'نوع شبکه یا سرویس را مطابق توضیح پروتکل انتخاب کنید؛ این مقدار به نوع همان پروتکل وابسته است.', 'Select the network/service mode according to this protocol\'s instructions; its meaning depends on the protocol.'],
            'handshake-timeout' => ['مهلت دست‌دهی', 'Handshake timeout', 'مهلت handshake به ثانیه؛ فقط با نیاز سرویس تغییر دهید و برای پیش‌فرض هسته خالی بگذارید.', 'Handshake deadline in seconds; change only for service requirements and leave blank for the core default.'],
            'proto' => ['انتقال OpenVPN', 'OpenVPN transport', 'UDP یا TCP را دقیقاً مطابق remote/proto فایل OpenVPN انتخاب کنید؛ پورت اتصال نیز باید با همان انتقال سرویس باشد.', 'Match the OpenVPN file remote/proto transport exactly; the connection port must use that service transport.'],
            'dev' => ['نوع دستگاه OpenVPN', 'OpenVPN device type', 'این هسته از tun پشتیبانی می‌کند؛ آن را با پروفایل سرویس هماهنگ نگه دارید. tap در این فرم پشتیبانی نمی‌شود.', 'This core supports tun; keep it consistent with the service profile. tap is not supported by this form.'],
            'data-ciphers' => ['رمزهای مذاکرهٔ OpenVPN', 'OpenVPN negotiated ciphers', 'آرایهٔ نام رمزهای data-ciphers را به همان ترتیب پروفایل سرویس وارد کنید؛ نام‌ها را خودسرانه تغییر ندهید.', 'Enter the profile data-ciphers names in their specified order; do not substitute arbitrary names.'],
            'data-ciphers-fallback' => ['رمز جایگزین OpenVPN', 'OpenVPN fallback cipher', 'مقدار data-ciphers-fallback پروفایل را فقط برای سازگاری با سرورهای نیازمند آن وارد کنید؛ وگرنه خالی بگذارید.', 'Copy data-ciphers-fallback only for compatibility with servers requiring it; otherwise leave blank.'],
            'auth' => ['تابع احراز پیام OpenVPN', 'OpenVPN authentication digest', 'الگوریتم auth، مانند SHA256، را مطابق فایل سرویس بنویسید؛ این گزینه نام کاربری/رمز نیست.', 'Match the profile auth digest, such as SHA256; this is not username/password authentication.'],
            'comp-lzo' => ['فشرده‌سازی OpenVPN', 'OpenVPN compression', 'فقط مقدار comp-lzo اعلام‌شدهٔ سرویس را وارد کنید؛ برای سرویس بدون فشرده‌سازی خالی بگذارید.', 'Use only the service-specified comp-lzo value; leave blank for a service without compression.'],
            'ca' => ['گواهی CA سرویس OpenVPN', 'OpenVPN service CA certificate', 'متن کامل گواهی CA در قالب PEM، از BEGIN CERTIFICATE تا END CERTIFICATE؛ مسیر فایل سرور یا صرف نام دامنه معتبر نیست.', 'Paste the full CA PEM from BEGIN CERTIFICATE to END CERTIFICATE; a server file path or domain name is not sufficient.'],
            'cert' => ['گواهی کلاینت OpenVPN', 'OpenVPN client certificate', 'برای ورود با گواهی، متن PEM گواهی کلاینت و key متناظر را وارد کنید؛ گواهی سرور نیست.', 'For certificate authentication, paste the client PEM certificate and matching key; this is not the server certificate.'],
            'tls-auth' => ['کلید tls-auth', 'tls-auth key', 'محتوای کلید tls-auth فایل OpenVPN را کپی کنید؛ هم‌زمان با tls-crypt یا tls-crypt-v2 قابل استفاده نیست.', 'Copy the OpenVPN tls-auth key contents; it cannot be combined with tls-crypt or tls-crypt-v2.'],
            'key-direction' => ['جهت کلید tls-auth', 'tls-auth key direction', 'مقدار ۰ یا ۱ را طبق پروفایل کلاینت OpenVPN انتخاب کنید؛ اگر پروفایل ندارد خالی بگذارید.', 'Choose 0 or 1 exactly as the OpenVPN client profile specifies; leave blank if absent.'],
            'tls-crypt' => ['کلید tls-crypt', 'tls-crypt key', 'محتوای tls-crypt پروفایل را کپی کنید؛ فقط یکی از tls-auth، tls-crypt و tls-crypt-v2 را پر کنید.', 'Copy the profile tls-crypt contents; supply only one of tls-auth, tls-crypt and tls-crypt-v2.'],
            'tls-crypt-v2' => ['کلید tls-crypt-v2', 'tls-crypt-v2 key', 'محتوای کلید نسخهٔ ۲ را دقیقاً از پروفایل کپی کنید؛ با دو روش کلید کنترل دیگر هم‌زمان نشود.', 'Copy the exact v2 key contents from the profile; do not combine it with the other control-channel key modes.'],
            'peer-info' => ['اطلاعات Peer در OpenVPN', 'OpenVPN peer information', 'شیء JSON اطلاعات peer-info را فقط طبق نیاز سرور وارد کنید؛ برای سرویس معمولی خالی بگذارید.', 'Enter the peer-info JSON object only when required by the server; otherwise leave blank.'],
            'ping' => ['دورهٔ Ping OpenVPN', 'OpenVPN ping interval', 'فاصلهٔ keepalive پینگ به ثانیه طبق پروفایل سرویس؛ برای پیش‌فرض هسته خالی بگذارید.', 'Keepalive ping interval in seconds from the service profile; leave blank for the core default.'],
            'ping-restart' => ['مهلت راه‌اندازی مجدد OpenVPN', 'OpenVPN ping restart timeout', 'مدت بی‌پاسخ‌ماندن پینگ به ثانیه که پس از آن اتصال دوباره ساخته می‌شود؛ با مقدار سرویس هماهنگ کنید.', 'Seconds without ping responses before reconnecting; match the service profile value.'],
            'tran-window' => ['پنجرهٔ انتقال کلید OpenVPN', 'OpenVPN key-transition window', 'مدت پنجرهٔ انتقال کلید به ثانیه را فقط مطابق پروفایل سرویس وارد کنید؛ در غیر این صورت خالی بگذارید.', 'Key-transition window in seconds; copy the service profile value or leave blank.'],
            'auth-key' => ['کلید ثبت دستگاه', 'Device registration key', 'کلید ثبت واقعی Tailscale/Headscale را از کنترلر بگیرید؛ باید سیاست ثبت دستگاه‌های کلاینت و اعتبار لازم را داشته باشد.', 'Obtain a real Tailscale/Headscale registration key from the controller with the required client-enrollment policy and validity.'],
            'hostname' => ['نام دستگاه', 'Device hostname', 'نامی یکتا برای دستگاه در شبکه بنویسید؛ {{user_id}} به شناسهٔ مشترک تبدیل می‌شود. بدون نیاز خاص خالی بگذارید.', 'Choose a unique device name in the network; {{user_id}} expands to the subscriber ID. Leave blank unless a specific name is needed.'],
            'exit-node' => ['نود خروجی Tailscale', 'Tailscale exit node', 'شناسه یا نام exit node مجاز شبکه را بنویسید؛ auto:any انتخاب خودکار یک exit node است و نیاز به وجود نود مجاز در شبکه دارد.', 'Enter an approved network exit-node ID/name; auto:any automatically selects an available approved exit node.'],
            'control-url' => ['نشانی کنترلر Headscale', 'Headscale controller URL', 'برای Headscale، URL کامل کنترلر را با https:// وارد کنید؛ برای کنترلر استاندارد Tailscale خالی بگذارید.', 'For Headscale, enter the complete controller URL including https://; leave blank for standard Tailscale control.'],
            'state-dir' => ['مسیر وضعیت در کلاینت', 'Client state directory', 'مسیر نسبی نگهداری هویت/وضعیت داخل پوشهٔ پیکربندی کلاینت، مانند tailscale/node1؛ کلاینت مسیر مطلق یا دارای .. را رد می‌کند. این مسیر در اشتراک می‌آید و مسیر روی سرور پنل یا V2bX نیست.', 'A relative state/identity directory inside the client\'s configuration folder, such as tailscale/node1; clients refuse absolute paths or "..". It is distributed in subscriptions and is not a path on the panel or V2bX server.'],
            'ephemeral' => ['دستگاه موقت Tailscale', 'Ephemeral Tailscale device', 'اگر سیاست شبکه دستگاه موقت می‌خواهد روشن کنید تا کنترلر پس از غیرفعال‌شدن دستگاه بتواند آن را پاک کند.', 'Enable if the network requires an ephemeral device that the controller may remove after inactivity.'],
            'accept-routes' => ['پذیرش مسیرهای Tailscale', 'Accept Tailscale routes', 'برای استفاده از subnet routeهای اعلام‌شدهٔ شبکه روشن کنید؛ لازم است مسیرها در کنترلر نیز مجاز باشند.', 'Enable to accept network-advertised subnet routes; those routes must also be approved in the controller.'],
            'exit-node-allow-lan-access' => ['دسترسی LAN هنگام خروج', 'LAN access with an exit node', 'برای باقی‌ماندن دسترسی شبکهٔ محلی هنگام استفاده از exit node روشن کنید؛ مطابق سیاست شبکه انتخاب کنید.', 'Enable to retain local-network access while using an exit node; follow the network policy.'],
            'identity-secret' => ['هویت محرمانهٔ ZeroTier', 'ZeroTier secret identity', 'هویت محرمانهٔ کلاینت را فقط برای بازیابی همان دستگاه وارد کنید؛ معمولاً خالی است تا هویت کلاینت ساخته شود. این مقدار در اشتراک توزیع می‌شود.', 'Enter a secret client identity only to restore that device; normally leave blank so the client creates one. This value is distributed in subscriptions.'],
            'planet' => ['فایل Planet در کلاینت ZeroTier', 'ZeroTier client planet file', 'مسیر نسبی فایل planet اختصاصی در پوشهٔ پیکربندی روی دستگاه هر کلاینت، نه مسیری روی سرور پنل؛ پنل این فایل را نمی‌فرستد و اگر روی دستگاه کاربری نباشد، کل پروفایل او بارگذاری نمی‌شود. برای ZeroTier عادی خالی بگذارید.', 'A relative path to a custom planet file in the configuration folder on each client device, not a panel-server path. The panel does not deliver the file, and a subscriber without it cannot load any of their profile. Leave blank for normal ZeroTier.'],
            'physical-mtu' => ['MTU شبکهٔ زیرین', 'Physical-network MTU', 'MTU شبکهٔ زیرین ZeroTier به بایت؛ با MTU تونل تفاوت دارد و معمولاً باید خالی بماند.', 'Underlying ZeroTier network MTU in bytes; this differs from tunnel MTU and should normally remain blank.'],
            'low-bandwidth' => ['حالت پهنای باند کم', 'Low-bandwidth mode', 'در شبکهٔ کم‌ظرفیت برای کاهش سربار ZeroTier روشن کنید؛ بدون نیاز مشخص رفتار پیش‌فرض را نگه دارید.', 'Enable to reduce ZeroTier overhead on low-capacity networks; otherwise keep the default behavior.'],
            'encrypted-hello' => ['Hello رمز‌شدهٔ ZeroTier', 'Encrypted ZeroTier hello', 'روش Hello رمز‌شده را فقط در صورت پشتیبانی و نیاز شبکه فعال کنید؛ باید با تنظیمات زیرساخت هماهنگ باشد.', 'Enable encrypted hello only when supported and required by the network; match its infrastructure settings.'],
            'primary-port' => ['پورت اصلی ZeroTier', 'ZeroTier primary port', 'پورت UDP اصلی روی کلاینت ZeroTier؛ این پورت سرویس عمومی نود پنل نیست. برای انتخاب خودکار خالی بگذارید.', 'The primary UDP port on the ZeroTier client; not the panel node public service port. Leave blank for automatic selection.'],
            'secondary-port' => ['پورت دوم ZeroTier', 'ZeroTier secondary port', 'پورت UDP دوم کلاینت را فقط برای نیاز NAT یا سیاست شبکه تنظیم کنید؛ در حالت عادی خالی بگذارید.', 'Set the secondary client UDP port only for a NAT or network policy requirement; otherwise leave blank.'],
            'tcp-fallback-mode' => ['حالت TCP fallback', 'TCP fallback mode', 'auto پس از ناموفق‌بودن UDP از TCP استفاده می‌کند؛ force فقط relay TCP را به‌کار می‌گیرد؛ disable بدون TCP است. خالی یعنی auto.', 'auto engages TCP after UDP fails; force uses only the TCP relay; disable prevents TCP fallback. Blank means auto.'],
            'tcp-fallback-relay' => ['Relay جایگزین TCP', 'TCP fallback relay', 'نشانی relay TCP اعلام‌شده توسط مدیر ZeroTier؛ وقتی شبکه relay اختصاصی ندارد خالی بگذارید.', 'The TCP relay address supplied by the ZeroTier administrator; leave blank when no custom relay is used.'],
            'orbit' => ['ماه‌های ZeroTier', 'ZeroTier moons', 'آرایهٔ اشیای world و seed؛ world شناسهٔ ۱۶رقمی هگزادسیمال moon و seed آدرس ۱۰رقمی گره آن است. هر دو را از مدیر شبکه بگیرید؛ معمولاً خالی است.', 'An array of objects with world (16-hex-digit moon ID) and seed (10-hex-digit node address). Obtain both from the administrator; normally leave blank.'],
            'remote-trace-target' => ['مقصد رهگیری ZeroTier', 'ZeroTier trace target', 'آدرس ۱۰رقمی هگزادسیمال گره ZeroTier دریافت‌کنندهٔ رهگیری؛ IP یا URL نیست. فقط برای عیب‌یابی مورد تأیید مدیر شبکه وارد کنید.', 'The 10-hex-digit ZeroTier node address receiving traces, not an IP or URL. Set only for administrator-approved diagnostics.'],
            'remote-trace-level' => ['سطح رهگیری ZeroTier', 'ZeroTier trace level', 'سطح جزئیات رهگیری از ۰ تا ۳۰؛ مقدار بیشتر جزئیات بیشتری تولید می‌کند. فقط همراه مقصد رهگیری و برای عیب‌یابی تنظیم کنید.', 'Trace detail level from 0 to 30; higher values produce more detail. Set with a trace target only for diagnostics.'],
            'network-name' => ['نام شبکهٔ EasyTier', 'EasyTier network name', 'نام دقیق شبکهٔ موجود EasyTier؛ همهٔ اعضای همان شبکه باید این نام را یکسان تنظیم کنند.', 'The exact existing EasyTier network name; every member of that network must use the same value.'],
            'network-secret' => ['رمز شبکهٔ EasyTier', 'EasyTier network secret', 'رمز شبکه را از مدیر EasyTier بگیرید و دقیقاً با همتاها یکسان بگذارید؛ رمز پنل نیست.', 'Obtain the EasyTier network secret from its administrator and match the peers exactly; this is not the panel password.'],
            'dhcp' => ['دریافت خودکار IP در EasyTier', 'EasyTier DHCP', 'برای گرفتن آدرس مجازی از سازوکار DHCP شبکه روشن کنید؛ اگر IP ثابت اختصاص یافته دارید طبق تنظیم شبکه عمل کنید.', 'Enable automatic virtual-address allocation through network DHCP; follow the network configuration when using an assigned static IP.'],
            'listeners' => ['ورودی‌های EasyTier', 'EasyTier listeners', 'آرایهٔ URIهای شنود کلاینت EasyTier؛ فقط نشانی‌هایی را وارد کنید که دستگاه کلاینت اجازه و امکان شنود آن‌ها را دارد.', 'An array of EasyTier client listening URIs; use only addresses the client device is permitted and able to bind.'],
            'no-listener' => ['غیرفعال‌کردن listener در EasyTier', 'Disable EasyTier listeners', 'روشن یعنی کلاینت listener ندارد و باید peers قابل دسترس داشته باشد. خاموشِ صریح اجازهٔ listener پیش‌فرض هسته را می‌دهد.', 'Enabled disables client listeners and requires reachable peers. Explicitly disabling permits the core default listener.'],
            'mapped-listeners' => ['ورودی‌های نگاشت‌شدهٔ EasyTier', 'Mapped EasyTier listeners', 'آرایهٔ URIهای عمومی نگاشت‌شده به listenerهای کلاینت، مطابق NAT یا port forwarding شبکه.', 'An array of public URIs mapped to client listeners, matching the network NAT or port-forwarding setup.'],
            'exit-nodes' => ['نودهای خروجی EasyTier', 'EasyTier exit nodes', 'آرایهٔ نودهای خروجی مجاز شبکه را از مدیر سرویس بگیرید؛ بدون exit node اختصاصی خالی بگذارید.', 'An array of approved network exit nodes supplied by the administrator; leave blank without a designated exit node.'],
            'proxy-networks' => ['شبکه‌های Proxy در EasyTier', 'EasyTier proxy networks', 'آرایهٔ subnet/CIDRهایی که این کلاینت به شبکه اعلام می‌کند؛ فقط شبکه‌هایی که مجاز به ارائهٔ آن‌ها هستید وارد کنید.', 'An array of subnet/CIDRs this client advertises; include only networks it is authorized to expose.'],
            'instance-name' => ['نام نمونهٔ EasyTier', 'EasyTier instance name', 'نام یکتا برای نمونهٔ EasyTier روی کلاینت؛ برای دستگاهی با چند نمونه کاربرد دارد و معمولاً خالی می‌ماند.', 'A unique EasyTier instance name on the client; useful for multiple instances and normally left blank.'],
            'accept-dns' => ['پذیرش DNS شبکهٔ EasyTier', 'Accept EasyTier DNS', 'برای استفاده از DNS اعلام‌شدهٔ شبکه روشن کنید؛ این تصمیم مربوط به کلاینت و اعتماد به شبکهٔ EasyTier است.', 'Enable to use network-advertised DNS; this is a client-side trust decision for that EasyTier network.'],
            'enable-exit-node' => ['ارائهٔ exit node در EasyTier', 'Provide an EasyTier exit node', 'روشن یعنی دستگاه کلاینت می‌تواند خروجی شبکه باشد؛ فقط روی دستگاهی که اجازه و مسیر خروجی مناسب دارد فعال کنید.', 'Enabling lets the client device provide an exit node; use only where it is authorized and has suitable egress.'],
            'enable-encryption' => ['رمزگذاری EasyTier', 'EasyTier encryption', 'رمزگذاری ترافیک EasyTier را طبق سیاست شبکه نگه دارید؛ خاموش‌کردن فقط برای زیرساختی است که عمداً بدون این لایه طراحی شده است.', 'Keep EasyTier encryption consistent with network policy; disable only on infrastructure deliberately designed without this layer.'],
            'encryption-algorithm' => ['الگوریتم رمزگذاری EasyTier', 'EasyTier encryption algorithm', 'الگوریتم مورد پشتیبانی و توافق شبکه را وارد کنید؛ برای انتخاب پیش‌فرض هسته خالی بگذارید.', 'Enter an algorithm supported and agreed by the network; leave blank for the core default.'],
            'private-mode' => ['حالت خصوصی EasyTier', 'EasyTier private mode', 'برای محدودکردن عضویت و رفتار شبکه مطابق سیاست private mode روشن کنید؛ باید با ساختار همان شبکه هماهنگ باشد.', 'Enable private mode according to the network membership/behavior policy; match that network design.'],
            'latency-first' => ['اولویت تأخیر در EasyTier', 'EasyTier latency priority', 'برای ترجیح مسیر با تأخیر کمتر فعال کنید؛ ممکن است مسیر انتخاب‌شده با حالت پیش‌فرض تفاوت کند.', 'Enable to prefer lower-latency paths; selected routes may differ from default behavior.'],
            'disable-p2p' => ['غیرفعال‌کردن P2P در EasyTier', 'Disable EasyTier P2P', 'برای اجبار به مسیرهای غیرمستقیم شبکه روشن کنید؛ فقط وقتی relayهای لازم آماده‌اند فعال شود.', 'Enable to avoid direct peer-to-peer paths; use only when the required relays are available.'],
            'enable-kcp-proxy' => ['Proxy روی KCP در EasyTier', 'EasyTier KCP proxy', 'قابلیت KCP proxy را فقط اگر همتاها و شبکه پشتیبانی می‌کنند فعال کنید.', 'Enable KCP proxy only if peers and the network support it.'],
            'disable-kcp-input' => ['غیرفعال‌کردن ورودی KCP', 'Disable KCP input', 'روشن یعنی کلاینت اتصال ورودی KCP را نمی‌پذیرد؛ با سیاست انتقال شبکه هماهنگ کنید.', 'Enabled prevents the client from accepting KCP input; match the network transport policy.'],
            'enable-quic-proxy' => ['Proxy روی QUIC در EasyTier', 'EasyTier QUIC proxy', 'قابلیت QUIC proxy را فقط در شبکهٔ دارای پشتیبانی سمت همتا فعال کنید.', 'Enable QUIC proxy only on a network with compatible peer support.'],
            'disable-quic-input' => ['غیرفعال‌کردن ورودی QUIC', 'Disable QUIC input', 'روشن یعنی کلاینت اتصال ورودی QUIC را نمی‌پذیرد؛ برای مسیرهای نیازمند آن خاموش بماند.', 'Enabled prevents accepting QUIC input; keep disabled for paths requiring it.'],
            'tld-dns-zone' => ['ناحیهٔ DNS داخلی EasyTier', 'EasyTier DNS zone', 'پسوند یا ناحیهٔ نام داخلی شبکه را طبق تنظیم DNS سرویس وارد کنید؛ بدون نیاز مشخص خالی بگذارید.', 'The internal network name suffix/zone from its DNS configuration; leave blank without a specific requirement.'],
            'secure-mode' => ['حالت Secure در EasyTier', 'EasyTier secure mode', 'برای استفاده از کلیدهای local-private-key و local-public-key باید فعال باشد؛ تنظیم آن باید با سرویس شبکه هماهنگ باشد.', 'Must be enabled when supplying local-private-key/local-public-key; match the network service configuration.'],
            'local-private-key' => ['کلید خصوصی محلی EasyTier', 'Local EasyTier private key', 'کلید خصوصی هویت کلاینت EasyTier در حالت secure-mode؛ از پروفایل واقعی بگیرید و کلید سرور V2bX را وارد نکنید.', 'The EasyTier client identity private key in secure mode; obtain the actual profile value, not a V2bX server key.'],
            'local-public-key' => ['کلید عمومی محلی EasyTier', 'Local EasyTier public key', 'کلید عمومی متناظر local-private-key؛ بدون کلید خصوصی یا با secure-mode خاموش معتبر نیست.', 'The public key corresponding to local-private-key; invalid without the private key or when secure mode is disabled.'],
            'reuse' => ['استفادهٔ مجدد Snell', 'Snell connection reuse', 'استفادهٔ مجدد از اتصال را فقط مطابق نسخه و قابلیت سرور Snell فعال کنید؛ رفتار نسخه‌های مختلف یکسان نیست.', 'Enable connection reuse only when supported by the selected Snell version and server; behavior differs by version.'],
            'obfs-opts' => ['گزینه‌های استتار Snell', 'Snell obfuscation options', 'شیء JSON گزینه‌های استتار را از پروفایل سرویس کپی کنید؛ mode می‌تواند http، tls، shadow-tls، restls یا jls باشد.', 'Copy the service obfuscation JSON object; mode may be http, tls, shadow-tls, restls or jls.'],
            'headers' => ['هدرهای HTTP پراکسی', 'Proxy HTTP headers', 'شیء JSON با نام و مقدار متنی هدرهای مورد نیاز پراکسی؛ خط جدید مجاز نیست. برای سرویس معمولی خالی بگذارید.', 'A JSON object of string header names and values required by the proxy; newlines are not allowed. Otherwise leave blank.'],
            'tfo' => ['TCP Fast Open', 'TCP Fast Open', 'برای درخواست TFO در کلاینت فعال کنید؛ سیستم‌عامل و مسیر شبکه نیز باید آن را پشتیبانی کنند. به‌تنهایی TFO سرور را فعال نمی‌کند.', 'Request client TCP Fast Open; OS and network support are also required. This does not enable TFO on the server by itself.'],
            'mptcp' => ['Multipath TCP', 'Multipath TCP', 'برای استفاده از MPTCP در کلاینت فعال کنید؛ پشتیبانی سیستم‌عامل و مسیر لازم است و محدودیت سرعت پنل را تغییر نمی‌دهد.', 'Request client Multipath TCP; OS and path support are required. It does not alter panel speed limits.'],
            'interface-name' => ['رابط خروجی کلاینت', 'Client outgoing interface', 'نام رابط شبکه روی دستگاه کلاینت، مانند eth0؛ نام کارت شبکهٔ سرور پنل نیست. برای مسیریابی عادی خالی بگذارید.', 'A network interface on the client device, such as eth0; not the panel server interface. Leave blank for normal routing.'],
            'routing-mark' => ['نشان مسیریابی کلاینت', 'Client routing mark', 'نشان عددی مسیریابی روی سیستم کلاینتِ پشتیبانی‌کننده؛ فقط با policy routing تنظیم‌شده استفاده کنید.', 'A numeric routing mark on a supported client system; use only with an existing policy-routing configuration.'],
            'ip-version' => ['اولویت نسخهٔ IP', 'IP-version preference', 'سیاست IPv4/IPv6 خروجی کلاینت را طبق پشتیبانی Mihomo تعیین کنید؛ برای انتخاب پیش‌فرض خالی بگذارید.', 'The client IPv4/IPv6 egress preference supported by Mihomo; leave blank for the default selection.'],
            'dialer-proxy' => ['پراکسی واسط کلاینت', 'Client dialer proxy', 'نام یک پراکسی یا گروه موجود در تنظیم کلاینت برای اتصال زنجیره‌ای؛ نام باید در همان کانفیگ کلاینت وجود داشته باشد. برای اتصال مستقیم خالی بگذارید.', 'The name of an existing client proxy/group used for chaining; it must exist in that client configuration. Leave blank for direct dialing.'],
        ];
        $copy = [];
        foreach ($rows as $option => $row) $copy[$option] = self::item($row[0], $row[1], $row[2], $row[3]);
        return $copy;
    }
}

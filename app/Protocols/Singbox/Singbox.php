<?php
namespace App\Protocols\Singbox;

use App\Utils\Helper;

class Singbox
{
    public $flag = 'sing';
    private $servers;
    private $user;
    private $config;
    private static $templateCache = [];

    public function __construct($user, $servers, array $options = null)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $appName = config('v2board.app_name', 'V2Board');
        $this->config = $this->loadConfig();
        $proxies = $this->buildProxies();
        $outbounds = $this->addProxies($proxies);
        $this->config['outbounds'] = $outbounds;
        $user = $this->user;

        $response = response(json_encode($this->config, JSON_UNESCAPED_SLASHES), 200)
            ->header('Content-Type', 'application/json')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24')
            ->header('Profile-Title', 'base64:' . base64_encode($appName))
            ->header('Content-Disposition', 'attachment; filename="' . $appName . '"');

        if ($url = $this->appConfigUrl()) {
            $response->header('dm-config-url', $url);
        }

        return $response;
    }

    /**
     * Where the Doctor Mobile app can pick up its settings, or null if this
     * panel does not publish any.
     *
     * Only announced when the file is actually there, so a panel that never
     * set one up sends nothing and clients do not chase a URL that has no
     * document behind it. Clients that do not know this header ignore it.
     */
    protected function appConfigUrl()
    {
        if (!file_exists(base_path('resources/rules/custom.dm-app.json'))
            && !file_exists(base_path('resources/rules/default.dm-app.json'))
        ) {
            return null;
        }
        // The host the client just reached us on. A device that came in through
        // a mirror or tunnel domain stays on it instead of being pointed at one
        // it may not be able to reach.
        return rtrim(request()->getSchemeAndHttpHost(), '/') . '/api/v1/guest/comm/appConfig';
    }

    protected function loadConfig()
    {
        $defaultConfig = base_path('resources/rules/default.sing-box.json');
        $customConfig = base_path('resources/rules/custom.sing-box.json');
        $path = file_exists($customConfig) ? $customConfig : $defaultConfig;
        $mtime = @filemtime($path);
        if (isset(self::$templateCache[$path]) && self::$templateCache[$path]['mtime'] === $mtime) {
            return self::$templateCache[$path]['data'];
        }
        $config = json_decode(file_get_contents($path), true);
        self::$templateCache[$path] = ['mtime' => $mtime, 'data' => $config];
        return $config;
    }

    protected function buildProxies()
    {
        $proxies = [];
    
        foreach ($this->servers as $item) {
            if ($item['type'] === 'v2node') {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'shadowsocks':
                    $ssConfig = $this->buildShadowsocks($this->user['uuid'], $item);
                    $proxies[] = $ssConfig;
                    break;
                case 'trojan':
                    $trojanConfig = $this->buildTrojan($this->user['uuid'], $item);
                    $proxies[] = $trojanConfig;
                    break;
                case 'vmess':
                    $vmessConfig = $this->buildVmess($this->user['uuid'], $item);
                    $proxies[] = $vmessConfig;
                    break;
                case 'vless':
                    $vlessConfig = $this->buildVless($this->user['uuid'], $item);
                    $proxies[] = $vlessConfig;
                    break;
                case 'tuic':
                    $tuicConfig = $this->buildTuic($this->user['uuid'], $item);
                    $proxies[] = $tuicConfig;
                    break;
                case 'anytls':
                    $anytlsConfig = $this->buildAnyTLS($this->user['uuid'], $item);
                    $proxies[] = $anytlsConfig;
                    break;
                case 'hysteria':
                    $hysteriaConfig = $this->buildHysteria($this->user['uuid'], $item, $this->user);
                    $proxies[] = $hysteriaConfig;
                    break;
                case 'hysteria2':
                    $hysteria2Config = $this->buildHysteria2($this->user['uuid'], $item);
                    $proxies[] = $hysteria2Config;
                    break;
            }
        }
    
        return $proxies;
    }

    /**
     * The `ech` block for a sing-box client outbound, or null when ECH cannot
     * work for this node - in which case emitting nothing is the only safe
     * answer, since a client told to use ECH against a server that cannot
     * answer it does not degrade, it fails.
     *
     * $nodeHoldsKey says whether this protocol's UniProxy payload actually
     * carries tls_settings down to the node. Custom ECH is terminated by our own
     * node and is therefore impossible without it: UniProxyController sends
     * tls_settings for vless and anytls, but for vmess and trojan it sends only
     * ports and names, so those two would advertise ECH the node has no key for.
     * The Cloudflare mode is different - the handshake is terminated by
     * Cloudflare, which publishes its own config in DNS, so our node never sees
     * an ECH ClientHello and every protocol may use it.
     *
     * sing-box wants the config as a PEM block of type "ECH CONFIGS"
     * (common/tls/ech.go) and rejects anything else with "invalid ECH configs
     * pem". The panel stores bare base64 because mihomo takes it that way, so
     * the wrapping belongs here, per builder, not in the stored value.
     */
    protected function buildEchConfig(array $tlsSettings, bool $nodeHoldsKey): ?array
    {
        $mode = $tlsSettings['ech'] ?? '';
        if (empty($mode)) return null;

        if ($mode === 'cloudflare') {
            return [
                'enabled' => true,
                'query_server_name' => 'cloudflare-ech.com'
            ];
        }

        if ($mode !== 'custom' || empty($tlsSettings['ech_config']) || !$nodeHoldsKey) {
            return null;
        }

        $config = $tlsSettings['ech_config'];
        if (is_array($config)) $config = implode('', $config);
        $config = preg_replace('/\s+/', '', (string)$config);
        if ($config === '') return null;

        // Already PEM (an operator pasted one in) - pass it through by lines.
        if (strpos($config, '-----BEGIN') !== false) {
            return ['enabled' => true, 'config' => preg_split('/\r\n|\n|\r/', trim((string)$tlsSettings['ech_config']))];
        }

        $lines = array_merge(
            ['-----BEGIN ECH CONFIGS-----'],
            str_split($config, 64),
            ['-----END ECH CONFIGS-----']
        );
        return ['enabled' => true, 'config' => $lines];
    }

    protected function addProxies($proxies)
    {
        $proxyTags = array_column($proxies, 'tag');
        $urltestTags = [];
        foreach ($this->config['outbounds'] as $ob) {
            if (($ob['type'] ?? '') === 'urltest') $urltestTags[] = $ob['tag'];
        }
        foreach ($this->config['outbounds'] as &$outbound) {
            $type = $outbound['type'] ?? '';
            $refsUrltest = ($type === 'selector') && count(array_intersect($outbound['outbounds'] ?? [], $urltestTags)) > 0;
            $isHashGroup = ($type === 'selector') && strpos($outbound['tag'], '#') === 0;
            if ($type === 'urltest' || $refsUrltest || $isHashGroup) {
                array_push($outbound['outbounds'], ...$proxyTags);
            }
        }
        unset($outbound);
        $outbounds = array_merge($this->config['outbounds'], $proxies);
        return $outbounds;
    }

    protected function buildShadowsocks($password, $server)
    {
        if (strpos($server['cipher'], '2022-blake3') !== false) {
            $length = $server['cipher'] === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = Helper::getServerKey($server['created_at'], $length);
            $userKey = Helper::uuidToBase64($password, $length);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'shadowsocks';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['method'] = $server['cipher'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=" . $server['obfs'];
            if (isset($server['obfs-host'])) {
                $plugin_opts_parts[] = "obfs-host=" . $server['obfs-host'];
            }
            if (isset($server['obfs-path'])) {
                $plugin_opts_parts[] = "path=" . $server['obfs-path'];
            }
            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        } else if ((($server['network'] ?? null) == 'http') && isset($server['network_settings']['Host'])) {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=http";
            $networkSettings = $server['network_settings'];
            $plugin_opts_parts[] = "obfs-host=" . $networkSettings['Host'];
            $plugin_opts_parts[] = "path=" . ($networkSettings['path'] ?? '/');

            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        }
        return $array;
    }


    protected function buildVmess($uuid, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['security'] = 'auto';
        $array['alter_id'] = 0;
        $array['transport']= [];
        $array['domain_resolver'] = 'local';

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;
            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? ($tlsSettings['allowInsecure'] ?? 0)) == 1 ? true : false;
            $tlsConfig['server_name'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
            // false: a vmess node is served no tls_settings by UniProxy, so it
            // can hold no ECH key and only the Cloudflare mode can apply.
            $ech = $this->buildEchConfig($tlsSettings, false);
            if ($ech !== null) $tlsConfig['ech'] = $ech;
            $array['tls'] = $tlsConfig;
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') $array['transport']['type'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['transport']['host'] = $tcpSettings['header']['request']['headers']['Host'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['transport']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['transport']['type'] ='ws';
            $wsSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            $array['transport']['path'] = $wsSettings['path'] ?? '/';
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) $array['transport']['headers'] = ['Host' => array($wsSettings['headers']['Host'])];
            $array['transport']['max_early_data'] = 2048;
            $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] ='grpc';
            $grpcSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
        }

        if (empty($array['transport'])) {
            unset($array['transport']);
        }

        return $array;
    }

    protected function buildVless($password, $server)
    {
        $array = [
            "type" => "vless",
            "tag" => $server['name'],
            "server" => $server['host'],
            "server_port" => $server['port'],
            "uuid" => $password,
            "domain_resolver" => "local",
            "packet_encoding" => "xudp"
        ];

        $tlsSettings = $server['tls_settings'] ?? [];

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : "";
            $tlsSettings = $server['tls_settings'] ?? [];
            if (!empty($tlsSettings)) {
                $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false;
                $tlsConfig['server_name'] = $tlsSettings['server_name'] ?? null;
                if ($server['tls'] == 2) {
                    $tlsConfig['reality'] = [
                        'enabled' => true,
                        'public_key' => $tlsSettings['public_key'],
                        'short_id' => $tlsSettings['short_id']
                    ];
                }
                $fingerprints = $tlsSettings['fingerprint'] ?? 'chrome';
                $tlsConfig['utls'] = [
                    "enabled" => true,
                    "fingerprint" => $fingerprints
                ];
                // A vless node IS served tls_settings by UniProxy, so it can
                // hold the ECH key and the custom mode is available. Skipped
                // under REALITY, which sing-box refuses to combine with ECH
                // ("Reality is conflict with ECH") and which already hides the
                // name on the wire.
                $ech = $this->buildEchConfig($tlsSettings, ((int)($server['tls'] ?? 1)) !== 2);
                if ($ech !== null) $tlsConfig['ech'] = $ech;
            }
            $array['tls'] = $tlsConfig;
        }

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') $array['transport']['type'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['transport']['host'] = $tcpSettings['header']['request']['headers']['Host'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['transport']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['transport']['type'] ='ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) $array['transport']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) $array['transport']['headers'] = ['Host' => array($wsSettings['headers']['Host'])];
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] ='grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
            }
        }

        return $array;
    }

    protected function buildTrojan($password, $server) 
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';

        $tlsSettings = $server['tls_settings'] ?? [];
        $tlsConfig = [
            'enabled' => true,
            'insecure' => ($server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'server_name' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? '')
        ];
        // false: a trojan node is served no tls_settings by UniProxy (its case
        // sends host/network/port/server_name only), so it can hold no ECH key
        // and only the Cloudflare mode can apply.
        $ech = $this->buildEchConfig($tlsSettings, false);
        if ($ech !== null) $tlsConfig['ech'] = $ech;
        $array['tls'] = $tlsConfig;

        if(isset($server['network']) && in_array($server['network'], ["grpc", "ws"])){
            $array['transport']['type'] = $server['network'];
            // grpc配置
            if($server['network'] === "grpc" && isset($server['network_settings']['serviceName'])) {
                $array['transport']['service_name'] = $server['network_settings']['serviceName'];
            }
            // ws配置
            if($server['network'] === "ws") {
                if(isset($server['network_settings']['path'])) {
                    $array['transport']['path'] = $server['network_settings']['path'] ?? '/';
                }
                if(isset($server['network_settings']['headers']['Host'])){
                    $array['transport']['headers'] = ['Host' => array($server['network_settings']['headers']['Host'])];
                }
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        };

        return $array;
    }

    protected function buildTuic($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'tuic';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $password;
        $array['password'] = $password;
        $array['congestion_control'] = $server['congestion_control'] ?? 'cubic';
        $array['udp_relay_mode'] = $server['udp_relay_mode'] ?? 'native';
        $array['zero_rtt_handshake'] = $server['zero_rtt_handshake'] ? true : false;
        $array['domain_resolver'] = 'local';

        $tlsSettings = $server['tls_settings'] ?? [];
        $array['tls'] = [
            'enabled' => true,
            'insecure' => ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'alpn' => ['h3'],
            'disable_sni' => $server['disable_sni'] ? true : false,
        ];
        $array['tls']['server_name'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');

        return $array;
    }

    protected function buildAnyTLS($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'anytls';
        $server['network'] = $server['network'] ?? 'tcp';
        $server['network_settings'] = $server['network_settings'] ?? [];
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;
        $array['domain_resolver'] = 'local';

        // anytls multiplexes many streams over one TLS session, so the expensive
        // part is establishing it - after that a request costs nothing. The
        // library defaults this pool to a 30s idle life (sing-anytls
        // session/client.go treats anything <= 5s as unset and substitutes 30s),
        // which means a phone put down for longer pays a full TLS handshake on
        // the next tap. On a disrupted network that handshake is exactly what
        // fails: one client in the node logs re-handshook ~20 times in four
        // minutes. Widening the window to 90s covers the ordinary "check, pocket,
        // check again" gap with a warm session instead.
        //
        // Deliberately NOT raising min_idle_session, which is the bigger-sounding
        // knob: it keeps N sessions alive *indefinitely*, refreshing their idle
        // timer on every sweep. Nothing sends a keepalive - the protocol has
        // cmdHeartRequest but session.go:334 says "Active keepalive checking is
        // not implemented yet" - so such a session eventually loses its carrier
        // NAT mapping silently, with no RST to notice. A session that dies
        // *visibly* removes itself from the pool via its dieHook, but a silent
        // drop leaves it looking healthy, and CreateStream does not fall back to
        // a new session when OpenStream fails on it: the request just fails. A
        // bounded 90s stays far below any plausible NAT timeout, so that window
        // never opens. It also matters that the server sets no idle timeout of
        // its own, so whatever the client holds, the node holds too.
        $array['idle_session_timeout'] = '90s';

        $tlsSettings = $server['tls_settings'] ?? [];
        $tlsConfig = [
            'enabled' => true,
            'insecure' => ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'alpn' => [
                'h2',
                'http/1.1',
            ],
            // Under REALITY the name on the wire must be the BORROWED site, not
            // our own host: the client validates the real certificate that site
            // presents, so preferring the node's own server_name here would
            // fail the handshake outright. Plain TLS keeps the old precedence.
            'server_name' => ((int)($server['tls'] ?? 1) === 2)
                ? ($tlsSettings['server_name'] ?? '')
                : ($server['server_name'] ?? ($tlsSettings['server_name'] ?? ''))
        ];
        if (!empty($tlsSettings)) {
            if ($server['tls'] == 2) {
                $tlsConfig['reality'] = [
                    'enabled' => true,
                    'public_key' => $tlsSettings['public_key'],
                    'short_id' => $tlsSettings['short_id']
                ];
                // REALITY authenticates the borrowed site's real certificate.
                // Leaving insecure on would throw that away and turn the whole
                // point of it - a genuine chain a censor can verify too - into
                // an unauthenticated tunnel.
                $tlsConfig['insecure'] = false;
            }
            // ECH hides the SNI of OUR certificate, so it belongs with plain
            // TLS; on a REALITY node the visible name is already the borrowed
            // one, and sing-box refuses the combination outright ("Reality is
            // conflict with ECH"), so a node configured with both would fail to
            // start its inbound. An anytls node IS served tls_settings by
            // UniProxy, so outside REALITY it can hold the key.
            $ech = $this->buildEchConfig($tlsSettings, ((int)($server['tls'] ?? 1)) !== 2);
            if ($ech !== null) $tlsConfig['ech'] = $ech;
            $tlsConfig['utls'] = [
                "enabled" => true,
                "fingerprint" => $tlsSettings['fingerprint'] ?? 'chrome'
            ];
        }
        $array['tls'] = $tlsConfig;

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') $array['transport']['type'] = $tcpSettings['header']['type'];
            if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['transport']['host'] = $tcpSettings['header']['request']['headers']['Host'];
            if (isset($tcpSettings['header']['request']['path'][0])) $array['transport']['path'] = $tcpSettings['header']['request']['path'][0];
        }
        if ($server['network'] === 'ws') {
            $array['transport']['type'] ='ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) $array['transport']['path'] = $wsSettings['path'];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) $array['transport']['headers'] = ['Host' => array($wsSettings['headers']['Host'])];
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        }
        if ($server['network'] === 'grpc') {
            $array['transport']['type'] ='grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                if (isset($grpcSettings['serviceName'])) $array['transport']['service_name'] = $grpcSettings['serviceName'];
            }
        }
        return $array;
    }

    protected function buildHysteria($password, $server, $user)
    {
        $parts = array_map('trim', explode(',', $server['port']));
        $portConfig = [];
        
        // 检查是否为单端口
        if (count($parts) === 1 && !str_contains($parts[0], '-')) {
            $port = (int)$parts[0];
        } else {
            // 处理多端口情况 舍弃单独的端口 只保留范围端口
            foreach ($parts as $part) {
                if (str_contains($part, '-')) {
                    $portConfig[] = str_replace('-', ':', $part);
                }
            }
        }

        $array = [
            'tag' => $server['name'],
            'server' => $server['host'],
            'domain_resolver' => 'local',
            'tls' => [
                'enabled' => true,
                'insecure' => $server['insecure'] ? true : false,
                'server_name' => $server['server_name']
            ]
        ];

        // 设置端口配置
        if (isset($port)) {
            $array['server_port'] = $port;
        } else {
            $array['server_ports'] = $portConfig;
        }

        if (is_null($server['version']) || $server['version'] == 1) {
            $array['auth_str'] = $password;
            $array['type'] = 'hysteria';
            $array['up_mbps'] = $user->speed_limit ? min($server['down_mbps'], $user->speed_limit) : $server['down_mbps'];
            $array['down_mbps'] = $user->speed_limit ? min($server['up_mbps'], $user->speed_limit) : $server['up_mbps'];
            if (isset($server['obfs']) && isset($server['obfs_password'])) {
                $array['obfs'] = $server['obfs_password'];
            }

            $array['disable_mtu_discovery'] = true;

        } elseif ($server['version'] == 2) {
            $array['password'] = $password;
            $array['type'] = 'hysteria2';
            $array['password'] = $password;

            if (isset($server['obfs'])) {
                $array['obfs']['type'] = $server['obfs'];
                $array['obfs']['password'] = $server['obfs_password'];
            }
        }

        return $array;
    }

    protected function buildHysteria2($password, $server)
    {
        $parts = explode(",",$server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $tlsSettings = $server['tls_settings'] ?? [];
        $array = [
            'server' => $server['host'],
            'server_port' => (int)$firstPort,
            'tls' => [
                'enabled' => true,
                'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
                'server_name' => $tlsSettings['server_name'] ?? ''
            ],
            'domain_resolver' => 'local',
            'password' => $password,
            'tag' => $server['name'],
            'type' => 'hysteria2'
        ];
        if (isset($server['obfs'])) {
            $array['obfs']['type'] = $server['obfs'];
            $array['obfs']['password'] = $server['obfs_password'];
        }
        return $array;
    }
}
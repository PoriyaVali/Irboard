<?php
namespace App\Protocols\Singbox;

use App\Utils\Helper;

class SingboxOld
{
    public $flag = 'sing';
    private $servers;
    private $user;
    private $config;

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

        return response(json_encode($this->config, JSON_UNESCAPED_SLASHES), 200)
            ->header('Content-Type', 'application/json')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
            ->header('profile-update-interval', '24')
            ->header('Profile-Title', 'base64:' . base64_encode($appName))
            ->header('Content-Disposition', 'attachment; filename="' . $appName . '"');
    }

    protected function loadConfig()
    {
        $defaultConfig = base_path('resources/rules/default.sing-box.old.json');
        $customConfig = base_path('resources/rules/custom.sing-box.old.json');
        $jsonData = file_exists($customConfig) ? file_get_contents($customConfig) : file_get_contents($defaultConfig);
        return json_decode($jsonData, true);
    }

    protected function buildProxies()
    {
        $proxies = [];
        foreach ($this->servers as $item) {
            if ($item['type'] === 'v2node') {
                $item['type'] = $item['protocol'];
            }
            $result = null;
            switch ($item['type']) {
                case 'shadowsocks':
                    $result = $this->buildShadowsocks($this->user['uuid'], $item);
                    break;
                case 'trojan':
                    $result = $this->buildTrojan($this->user['uuid'], $item);
                    break;
                case 'vmess':
                    $result = $this->buildVmess($this->user['uuid'], $item);
                    break;
                case 'vless':
                    $result = $this->buildVless($this->user['uuid'], $item);
                    break;
                case 'tuic':
                    $result = $this->buildTuic($this->user['uuid'], $item);
                    break;
                case 'hysteria':
                    if (($item['version'] ?? 1) == 2) {
                        $result = $this->buildHysteria2($this->user['uuid'], $item);
                    } else {
                        $result = $this->buildHysteria($this->user['uuid'], $item, $this->user);
                    }
                    break;
                case 'hysteria2':
                    $result = $this->buildHysteria2($this->user['uuid'], $item);
                    break;
            }
            if ($result) {
                $proxies[] = $result;
            }
        }
        return $proxies;
    }

    protected function addProxies($proxies)
    {
        foreach ($this->config['outbounds'] as &$outbound) {
            if (($outbound['type'] === 'selector' && $outbound['tag'] === config('v2board.app_name'))
                || ($outbound['type'] === 'urltest' && $outbound['tag'] === 'انتخاب خودکار')
                || ($outbound['type'] === 'selector' && strpos($outbound['tag'], '#') === 0)) {
                array_push($outbound['outbounds'], ...array_column($proxies, 'tag'));
            }
        }
        unset($outbound);
        return array_merge($this->config['outbounds'], $proxies);
    }

    protected function buildShadowsocks($password, $server)
    {
        if (strpos($server['cipher'], '2022-blake3') !== false) {
            $length = $server['cipher'] === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = Helper::getServerKey($server['created_at'], $length);
            $userKey = Helper::uuidToBase64($password, $length);
            $password = "{$serverKey}:{$userKey}";
        }

        $array = [
            'tag' => $server['name'],
            'type' => 'shadowsocks',
            'server' => $server['host'],
            'server_port' => $server['port'],
            'method' => $server['cipher'],
            'password' => $password,
        ];

        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs-local';
            $parts = ["obfs=" . $server['obfs']];
            if (isset($server['obfs-host'])) $parts[] = "obfs-host=" . $server['obfs-host'];
            if (isset($server['obfs-path'])) $parts[] = "path=" . $server['obfs-path'];
            $array['plugin_opts'] = implode(';', $parts);
        } elseif ((($server['network'] ?? null) === 'http') && isset($server['network_settings']['Host'])) {
            $array['plugin'] = 'obfs-local';
            $ns = $server['network_settings'];
            $array['plugin_opts'] = "obfs=http;obfs-host=" . $ns['Host'] . ";path=" . ($ns['path'] ?? '/');
        }

        return $array;
    }

    protected function buildVmess($uuid, $server)
    {
        $array = [
            'tag' => $server['name'],
            'type' => 'vmess',
            'server' => $server['host'],
            'server_port' => $server['port'],
            'uuid' => $uuid,
            'security' => 'auto',
            'alter_id' => 0,
            'transport' => [],
        ];

        if (!empty($server['tls'])) {
            $ts = $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
            $array['tls'] = [
                'enabled' => true,
                'insecure' => (($ts['allow_insecure'] ?? ($ts['allowInsecure'] ?? 0)) == 1),
                'server_name' => $ts['server_name'] ?? ($ts['serverName'] ?? ''),
            ];
        }

        $network = $server['network'] ?? null;
        $ns = $server['networkSettings'] ?? ($server['network_settings'] ?? []);

        if ($network === 'tcp' && isset($ns['header']['type']) && $ns['header']['type'] === 'http') {
            $array['transport']['type'] = 'http';
            if (isset($ns['header']['request']['headers']['Host'])) {
                $array['transport']['host'] = $ns['header']['request']['headers']['Host'];
            }
            if (isset($ns['header']['request']['path'][0])) {
                $array['transport']['path'] = $ns['header']['request']['path'][0];
            }
        }

        if ($network === 'ws') {
            $array['transport']['type'] = 'ws';
            $array['transport']['path'] = $ns['path'] ?? '/';
            if (!empty($ns['headers']['Host'])) {
                $array['transport']['headers'] = ['Host' => [$ns['headers']['Host']]];
            }
            $array['transport']['max_early_data'] = 2048;
            $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
        }

        if ($network === 'grpc') {
            $array['transport']['type'] = 'grpc';
            if (isset($ns['serviceName'])) {
                $array['transport']['service_name'] = $ns['serviceName'];
            }
        }
        if ($network === 'httpupgrade') {
            $array['transport']['type'] = 'httpupgrade';
            if (!empty($ns['path'])) $array['transport']['path'] = $ns['path'];
            if (!empty($ns['host'])) $array['transport']['host'] = $ns['host'];
        }

        return $array;
    }

    protected function buildVless($password, $server)
    {
        $array = [
            'type' => 'vless',
            'tag' => $server['name'],
            'server' => $server['host'],
            'server_port' => $server['port'],
            'uuid' => $password,
            'packet_encoding' => 'xudp',
        ];

        $ts = $server['tls_settings'] ?? [];

        if (!empty($server['tls'])) {
            $tlsConfig = ['enabled' => true];
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : '';

            if (!empty($ts)) {
                $tlsConfig['insecure'] = (($ts['allow_insecure'] ?? 0) == 1);
                $tlsConfig['server_name'] = $ts['server_name'] ?? null;

                if ($server['tls'] == 2) {
                    $tlsConfig['reality'] = [
                        'enabled' => true,
                        'public_key' => $ts['public_key'] ?? '',
                        'short_id' => $ts['short_id'] ?? '',
                    ];
                }

                $tlsConfig['utls'] = [
                    'enabled' => true,
                    'fingerprint' => $ts['fingerprint'] ?? 'chrome',
                ];
            }
            $array['tls'] = $tlsConfig;
        }

        $ns = $server['network_settings'] ?? [];

        if (($server['network'] ?? '') === 'tcp' && isset($ns['header']['type']) && $ns['header']['type'] === 'http') {
            $array['transport']['type'] = 'http';
            if (isset($ns['header']['request']['headers']['Host'])) {
                $array['transport']['host'] = $ns['header']['request']['headers']['Host'];
            }
            if (isset($ns['header']['request']['path'][0])) {
                $array['transport']['path'] = $ns['header']['request']['path'][0];
            }
        }

        if (($server['network'] ?? '') === 'ws' && !empty($ns)) {
            $array['transport']['type'] = 'ws';
            if (!empty($ns['path'])) $array['transport']['path'] = $ns['path'];
            if (!empty($ns['headers']['Host'])) {
                $array['transport']['headers'] = ['Host' => [$ns['headers']['Host']]];
            }
            $array['transport']['max_early_data'] = 2048;
            $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
        }

        if (($server['network'] ?? '') === 'grpc' && !empty($ns)) {
            $array['transport']['type'] = 'grpc';
            if (isset($ns['serviceName'])) {
                $array['transport']['service_name'] = $ns['serviceName'];
            }
        }
        if (($server['network'] ?? '') === 'httpupgrade' && !empty($ns)) {
            $array['transport']['type'] = 'httpupgrade';
            if (!empty($ns['path'])) $array['transport']['path'] = $ns['path'];
            if (!empty($ns['host'])) $array['transport']['host'] = $ns['host'];
        }

        return $array;
    }

    protected function buildTrojan($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];

        $array = [
            'tag' => $server['name'],
            'type' => 'trojan',
            'server' => $server['host'],
            'server_port' => $server['port'],
            'password' => $password,
            'tls' => [
                'enabled' => true,
                'insecure' => (($server['allow_insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
                'server_name' => $server['server_name'] ?? ($ts['server_name'] ?? ''),
            ],
        ];

        $ns = $server['network_settings'] ?? [];

        if (isset($server['network']) && in_array($server['network'], ['grpc', 'ws'])) {
            $array['transport']['type'] = $server['network'];

            if ($server['network'] === 'grpc' && isset($ns['serviceName'])) {
                $array['transport']['service_name'] = $ns['serviceName'];
            }

            if ($server['network'] === 'ws') {
                $array['transport']['path'] = $ns['path'] ?? '/';
                if (isset($ns['headers']['Host'])) {
                    $array['transport']['headers'] = ['Host' => [$ns['headers']['Host']]];
                }
                $array['transport']['max_early_data'] = 2048;
                $array['transport']['early_data_header_name'] = 'Sec-WebSocket-Protocol';
            }
        }

        return $array;
    }

    protected function buildTuic($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];

        return [
            'tag' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'server_port' => $server['port'],
            'uuid' => $password,
            'password' => $password,
            'congestion_control' => $server['congestion_control'] ?? 'cubic',
            'udp_relay_mode' => $server['udp_relay_mode'] ?? 'native',
            'zero_rtt_handshake' => !empty($server['zero_rtt_handshake']),
            'tls' => [
                'enabled' => true,
                'insecure' => (($server['insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
                'alpn' => ['h3'],
                'disable_sni' => !empty($server['disable_sni']),
                'server_name' => $server['server_name'] ?? ($ts['server_name'] ?? ''),
            ],
        ];
    }

    protected function buildHysteria($password, $server, $user)
    {
        $parts = explode(',', $server['port']);
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = (int)$range[0];
        } else {
            $firstPort = (int)$firstPart;
        }

        $array = [
            'tag' => $server['name'],
            'type' => 'hysteria',
            'server' => $server['host'],
            'server_port' => $firstPort,
            'auth_str' => $password,
            'up_mbps' => $user->speed_limit ? min($server['down_mbps'], $user->speed_limit) : $server['down_mbps'],
            'down_mbps' => $user->speed_limit ? min($server['up_mbps'], $user->speed_limit) : $server['up_mbps'],
            'disable_mtu_discovery' => true,
            'tls' => [
                'enabled' => true,
                'insecure' => (($server['insecure'] ?? 0) == 1),
                'server_name' => $server['server_name'] ?? $server['host'],
            ],
        ];

        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $array['obfs'] = $server['obfs_password'];
        }

        return $array;
    }

    protected function buildHysteria2($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];
        $parts = explode(',', $server['port']);
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = (int)$range[0];
        } else {
            $firstPort = (int)$firstPart;
        }

        $array = [
            'tag' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'server_port' => $firstPort,
            'password' => $password,
            'tls' => [
                'enabled' => true,
                'insecure' => (($server['insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
                'server_name' => $server['server_name'] ?? ($ts['server_name'] ?? $server['host']),
            ],
        ];

        if (isset($server['obfs'])) {
            $array['obfs'] = [
                'type' => $server['obfs'],
                'password' => $server['obfs_password'] ?? '',
            ];
        }

        return $array;
    }
}

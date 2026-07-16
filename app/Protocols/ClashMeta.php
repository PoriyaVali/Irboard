<?php

namespace App\Protocols;

use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

class ClashMeta
{
    public $flag = 'meta';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');

        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName));

        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }

        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            $result = null;
            switch ($item['type']) {
                case 'shadowsocks':
                    $result = self::buildShadowsocks($user['uuid'], $item);
                    break;
                case 'vmess':
                    $result = self::buildVmess($user['uuid'], $item);
                    break;
                case 'vless':
                    $result = self::buildVless($user['uuid'], $item);
                    break;
                case 'trojan':
                    $result = self::buildTrojan($user['uuid'], $item);
                    break;
                case 'tuic':
                    $result = self::buildTuic($user['uuid'], $item);
                    break;
                case 'anytls':
                    $result = self::buildAnyTLS($user['uuid'], $item);
                    break;
                case 'hysteria':
                    if (($item['version'] ?? 1) == 2) {
                        $result = self::buildHysteria2($user['uuid'], $item);
                    } else {
                        $result = self::buildHysteria($user['uuid'], $item);
                    }
                    break;
                case 'hysteria2':
                    $result = self::buildHysteria2($user['uuid'], $item);
                    break;
            }
            if ($result) {
                $proxy[] = $result;
                $proxies[] = $item['name'];
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ?? [], $proxy);

        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) {
                $config['proxy-groups'][$k]['proxies'] = [];
            }
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src)) continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(
                        array_diff($config['proxy-groups'][$k]['proxies'], [$src])
                    );
                    if ($this->isMatch($src, $dst)) {
                        $config['proxy-groups'][$k]['proxies'][] = $dst;
                    }
                }
                if ($isFilter) continue;
            }
            if ($isFilter) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge(
                $config['proxy-groups'][$k]['proxies'], $proxies
            );
        }

        $config['proxy-groups'] = array_values(array_filter($config['proxy-groups'], function ($group) {
            return !empty($group['proxies']);
        }));

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
    }

    public static function buildShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        }
        if ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }

        $array = [
            'name' => $server['name'],
            'type' => 'ss',
            'server' => $server['host'],
            'port' => $server['port'],
            'cipher' => $server['cipher'],
            'password' => $password,
            'udp' => true,
        ];

        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs';
            $pluginOpts = ['mode' => 'http', 'host' => $server['obfs-host'] ?? ''];
            if (isset($server['obfs-path'])) {
                $pluginOpts['path'] = $server['obfs-path'];
            }
            $array['plugin-opts'] = $pluginOpts;
        } elseif ((($server['network'] ?? null) === 'http') && isset(($server['network_settings'] ?? [])['Host'])) {
            $array['plugin'] = 'obfs';
            $ns = $server['network_settings'];
            $pluginOpts = ['mode' => 'http', 'host' => $ns['Host'] ?? ''];
            if (isset($ns['path'])) {
                $pluginOpts['path'] = $ns['path'];
            }
            $array['plugin-opts'] = $pluginOpts;
        }

        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'vmess',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'alterId' => 0,
            'cipher' => 'auto',
            'udp' => true,
            'client-fingerprint' => 'chrome',
        ];

        if (!empty($server['tls'])) {
            $array['tls'] = true;
            $ts = $server['tlsSettings'] ?? ($server['tls_settings'] ?? []);
            if (!empty($ts['allowInsecure'] ?? $ts['allow_insecure'] ?? false)) {
                $array['skip-cert-verify'] = true;
            }
            $sni = $ts['serverName'] ?? ($ts['server_name'] ?? '');
            if (!empty($sni)) {
                $array['servername'] = $sni;
            }
        }

        $network = $server['network'] ?? null;
        $ns = $server['networkSettings'] ?? ($server['network_settings'] ?? []);

        if ($network === 'tcp' && isset($ns['header']['type']) && $ns['header']['type'] === 'http') {
            $array['network'] = 'http';
            if (isset($ns['header']['request']['headers']['Host'])) {
                $array['http-opts']['headers']['Host'] = $ns['header']['request']['headers']['Host'];
            }
            if (isset($ns['header']['request']['path'])) {
                $array['http-opts']['path'] = $ns['header']['request']['path'];
            }
        }

        if ($network === 'ws') {
            $array['network'] = 'ws';
            $wsOpts = [];
            if (!empty($ns['path'])) {
                $wsOpts['path'] = $ns['path'];
            }
            if (!empty($ns['headers']['Host'])) {
                $wsOpts['headers'] = ['Host' => $ns['headers']['Host']];
            }
            if (!empty($wsOpts)) {
                $array['ws-opts'] = $wsOpts;
            }
        }

        if ($network === 'grpc') {
            $array['network'] = 'grpc';
            if (isset($ns['serviceName'])) {
                $array['grpc-opts'] = ['grpc-service-name' => $ns['serviceName']];
            }
        }
        if (($server['network'] ?? '') === 'httpupgrade') {
            $array['network'] = 'httpupgrade';
            $huOpts = [];
            if (!empty($ns['path'])) $huOpts['path'] = $ns['path'];
            if (!empty($ns['host'])) $huOpts['headers'] = ['Host' => $ns['host']];
            if (!empty($huOpts)) $array['httpupgrade-opts'] = $huOpts;
        }

        return $array;
    }

    public static function buildVless($uuid, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'vless',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $uuid,
            'udp' => true,
            'client-fingerprint' => 'chrome',
        ];

        $ts = $server['tls_settings'] ?? [];

        if (!empty($server['tls'])) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = (($ts['allow_insecure'] ?? 0) == 1);
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : '';
            if (!empty($ts['fingerprint'])) {
                $array['client-fingerprint'] = $ts['fingerprint'];
            }
            if (!empty($ts['server_name'])) {
                $array['servername'] = $ts['server_name'];
            }
            if ($server['tls'] == 2) {
                $array['reality-opts'] = [
                    'public-key' => $ts['public_key'] ?? '',
                    'short-id' => $ts['short_id'] ?? '',
                ];
            }
        }

        $ns = $server['network_settings'] ?? [];

        if (($server['network'] ?? '') === 'tcp' && isset($ns['header']['type']) && $ns['header']['type'] === 'http') {
            $array['network'] = 'http';
            if (isset($ns['header']['request']['headers']['Host'])) {
                $array['http-opts']['headers']['Host'] = $ns['header']['request']['headers']['Host'];
            }
            if (isset($ns['header']['request']['path'])) {
                $array['http-opts']['path'] = $ns['header']['request']['path'];
            }
        }

        if (($server['network'] ?? '') === 'ws') {
            $array['network'] = 'ws';
            $wsOpts = [];
            if (!empty($ns['path'])) {
                $wsOpts['path'] = $ns['path'];
            }
            if (!empty($ns['headers']['Host'])) {
                $wsOpts['headers'] = ['Host' => $ns['headers']['Host']];
            }
            if (!empty($wsOpts)) {
                $array['ws-opts'] = $wsOpts;
            }
        }

        if (($server['network'] ?? '') === 'grpc') {
            $array['network'] = 'grpc';
            if (isset($ns['serviceName'])) {
                $array['grpc-opts'] = ['grpc-service-name' => $ns['serviceName']];
            }
        }

        if (($server['network'] ?? '') === 'httpupgrade') {
            $array['network'] = 'httpupgrade';
            $huOpts = [];
            if (!empty($ns['path'])) $huOpts['path'] = $ns['path'];
            if (!empty($ns['host'])) $huOpts['headers'] = ['Host' => $ns['host']];
            if (!empty($huOpts)) $array['httpupgrade-opts'] = $huOpts;
        }
        if (!empty($server['encryption']) && !empty($server['encryption_settings'])) {
            $es = $server['encryption_settings'];
            $enc = $server['encryption'] ?? 'mlkem768x25519plus';
            $enc .= '.' . ($es['mode'] ?? 'native');
            $enc .= '.' . ($es['rtt'] ?? '1rtt');
            if (!empty($es['client_padding'])) {
                $enc .= '.' . $es['client_padding'];
            }
            $enc .= '.' . ($es['password'] ?? '');
            $array['encryption'] = $enc;
        }

        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'trojan',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'udp' => true,
            'client-fingerprint' => 'chrome',
        ];

        $ts = $server['tls_settings'] ?? [];
        $ns = $server['network_settings'] ?? [];

        if (isset($server['network']) && in_array($server['network'], ['grpc', 'ws'])) {
            $array['network'] = $server['network'];

            if ($server['network'] === 'grpc' && isset($ns['serviceName'])) {
                $array['grpc-opts'] = ['grpc-service-name' => $ns['serviceName']];
            }

            if ($server['network'] === 'ws') {
                $wsOpts = [];
                if (isset($ns['path'])) {
                    $wsOpts['path'] = $ns['path'];
                }
                if (isset($ns['headers']['Host'])) {
                    $wsOpts['headers'] = ['Host' => $ns['headers']['Host']];
                }
                if (!empty($wsOpts)) {
                    $array['ws-opts'] = $wsOpts;
                }
            }
        }

        $array['sni'] = $server['server_name'] ?? ($ts['server_name'] ?? '');
        $array['skip-cert-verify'] = (($server['allow_insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1);

        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];
        return [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $password,
            'password' => $password,
            'alpn' => ['h3'],
            'disable-sni' => !empty($server['disable_sni']),
            'reduce-rtt' => !empty($server['zero_rtt_handshake']),
            'udp-relay-mode' => $server['udp_relay_mode'] ?? 'native',
            'congestion-controller' => $server['congestion_control'] ?? 'cubic',
            'skip-cert-verify' => (($server['insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
            'sni' => $server['server_name'] ?? ($ts['server_name'] ?? $server['host']),
        ];
    }

    public static function buildAnyTLS($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];
        return [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'client-fingerprint' => 'chrome',
            'udp' => true,
            'alpn' => ['h2', 'http/1.1'],
            'sni' => $server['server_name'] ?? ($ts['server_name'] ?? $server['host']),
            'skip-cert-verify' => (($server['insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
        ];
    }

    public static function buildHysteria($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'hysteria',
            'server' => $server['host'],
            'udp' => true,
            'protocol' => 'udp',
            'auth_str' => $password,
            'up' => $server['down_mbps'],
            'down' => $server['up_mbps'],
            'skip-cert-verify' => (($server['insecure'] ?? 0) == 1),
        ];

        $parts = explode(',', $server['port']);
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $array['port'] = (int)$range[0];
        } else {
            $array['port'] = (int)$firstPart;
        }
        if (count($parts) > 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }

        if (isset($server['server_name'])) {
            $array['sni'] = $server['server_name'];
        }
        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $array['obfs'] = $server['obfs_password'];
        }

        return $array;
    }

    public static function buildHysteria2($password, $server)
    {
        $ts = $server['tls_settings'] ?? [];
        $array = [
            'name' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'password' => $password,
            'udp' => true,
            'skip-cert-verify' => (($server['insecure'] ?? ($ts['allow_insecure'] ?? 0)) == 1),
            'sni' => $server['server_name'] ?? ($ts['server_name'] ?? $server['host']),
        ];

        $parts = explode(',', $server['port']);
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $array['port'] = (int)$range[0];
        } else {
            $array['port'] = (int)$firstPart;
        }
        if (count($parts) > 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }

        if (isset($server['obfs'])) {
            $array['obfs'] = $server['obfs'];
            $array['obfs-password'] = $server['obfs_password'] ?? '';
        }

        return $array;
    }

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, '') !== false;
    }
}

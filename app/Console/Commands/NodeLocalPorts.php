<?php

namespace App\Console\Commands;

use App\Services\LocalNodePorts;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Prints, as JSON, the firewall ports the nodes running on this server need.
 *
 * Called by the server-config agent (root), which opens them and closes the
 * ones it opened that no node needs any more. It prints {"ok": false} on any
 * failure, and the agent then changes nothing: a database that is down must
 * not read as "no nodes" and close every port.
 */
class NodeLocalPorts extends Command
{
    protected $signature = 'node:local-ports {--ips= : this server\'s addresses, comma- or space-separated}';

    protected $description = 'List the firewall ports needed by the nodes running on this server';

    public function handle()
    {
        try {
            $ips = preg_split('/[\s,]+/', (string)$this->option('ips'), -1, PREG_SPLIT_NO_EMPTY);
            $servers = (new ServerService())->getAllServers();
            $reporters = [];
            foreach ($servers as $server) {
                $ref = ($server['type'] ?? '') . '#' . ($server['id'] ?? '');
                $ip = Cache::get(CacheKey::get('NODE_REPORTER_IP', $ref));
                if (is_string($ip) && $ip !== '') $reporters[$ref] = $ip;
            }
            $ports = LocalNodePorts::desired($servers, $ips, $reporters, [self::class, 'resolve']);
            $this->line(json_encode(['ok' => true, 'ports' => $ports], JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (\Throwable $e) {
            $this->line(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }

    /** A host name's IPv4 and IPv6 addresses; none when it does not resolve. */
    public static function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
        return $ips;
    }
}

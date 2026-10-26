<?php

namespace Tests\Fixtures;

use App\Protocols\Mihomo\Outbound;

/** Fictional client credentials; these profiles never contact a running service. */
class MihomoProfiles
{
    public static function settings(string $protocol): array
    {
        $settings = Outbound::catalog()[$protocol]['example'];
        foreach (['password', 'username', 'psk', 'key', 'ca', 'network-name', 'network-secret'] as $key) {
            if (array_key_exists($key, $settings)) $settings[$key] = 'fictional-' . $key;
        }
        if (isset($settings['server'])) $settings['server'] = '192.0.2.10';
        if ($protocol === 'sudoku') $settings['key'] = '11111111-2222-3333-4444-555555555555';
        if ($protocol === 'zerotier') $settings['network'] = '0123456789abcdef';
        if ($protocol === 'wireguard' || $protocol === 'masque') {
            $settings['private-key'] = base64_encode(str_repeat(chr(1), 32));
            $settings['public-key'] = base64_encode(str_repeat(chr(2), 32));
        }
        return $settings;
    }
}

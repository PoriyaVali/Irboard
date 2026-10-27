<?php

namespace Tests\Fixtures;

use App\Protocols\Mihomo\Outbound;

/** Fictional client credentials; these profiles never contact a running service. */
class MihomoProfiles
{
    public static function settings(string $protocol): array
    {
        $settings = Outbound::catalog()[$protocol]['example'];
        foreach (['password', 'username', 'psk', 'key', 'network-name', 'network-secret'] as $key) {
            if (array_key_exists($key, $settings)) $settings[$key] = 'fictional-' . $key;
        }
        if (isset($settings['server'])) $settings['server'] = '192.0.2.10';
        if ($protocol === 'sudoku') $settings['key'] = '11111111-2222-3333-4444-555555555555';
        if ($protocol === 'zerotier') $settings['network'] = '0123456789abcdef';
        if ($protocol === 'wireguard') {
            $settings['private-key'] = base64_encode(str_repeat(chr(1), 32));
            $settings['public-key'] = base64_encode(str_repeat(chr(2), 32));
        }
        if ($protocol === 'masque') {
            $settings['private-key'] = 'MHcCAQEEIA352bzEG9XDMUB1yM/pplQll/rsjT+VUE/TEDdk8/iQoAoGCCqGSM49AwEHoUQDQgAEwXYXEhrVB/v3RovrajZgxsBp3ZuV0o/6NmY1LruQ9GzH9GYmVoyG9+m58WXldFgif4W3bR321HNjzRN7vajc6g==';
            $settings['public-key'] = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEwXYXEhrVB/v3RovrajZgxsBp3ZuV0o/6NmY1LruQ9GzH9GYmVoyG9+m58WXldFgif4W3bR321HNjzRN7vajc6g==';
        }
        if ($protocol === 'openvpn') $settings['ca'] = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIBmzCCAUGgAwIBAgIUXhjOWKYAjD1NGhmaXUc3DxU5PcowCgYIKoZIzj0EAwIw
IjEgMB4GA1UEAwwXaXJib2FyZC1maXh0dXJlLmludmFsaWQwIBcNMjYxMDI3MDUw
MTE3WhgPMjEyNjEwMDMwNTAxMTdaMCIxIDAeBgNVBAMMF2lyYm9hcmQtZml4dHVy
ZS5pbnZhbGlkMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEwXYXEhrVB/v3Rovr
ajZgxsBp3ZuV0o/6NmY1LruQ9GzH9GYmVoyG9+m58WXldFgif4W3bR321HNjzRN7
vajc6qNTMFEwHQYDVR0OBBYEFAhanL/SCrJo2cgTViUuxojR24jMMB8GA1UdIwQY
MBaAFAhanL/SCrJo2cgTViUuxojR24jMMA8GA1UdEwEB/wQFMAMBAf8wCgYIKoZI
zj0EAwIDSAAwRQIhAP1wG3iexOkYyT18MjArW2oCuB7+72/nK0tcODQlw6onAiBk
kdoVkXg8LUMn40IWQCY4SJDJKKYlsUvMPbwh1P/D6A==
-----END CERTIFICATE-----
PEM;
        return $settings;
    }
}

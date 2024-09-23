<?php
declare(strict_types=1);

namespace Banzai\Http\Filter;

class ipv4
{
    public static function cidr_to_range(string $cidr): array
    {
        // Assign IP / mask
        list($ip, $mask) = explode("/", $cidr);

        // Sanitize IP
        $iplow = preg_replace('_(\d+\.\d+\.\d+\.\d+).*$_', '$1', "$ip.0.0.0");

        // Calculate range
        $iphigh = long2ip(ip2long($iplow) - 1 + (1 << (32 - $mask)));

        return array('prefix' => $mask, 'iplow' => $iplow, 'iphigh' => $iphigh);
    }

    public static function getIPVersion(string $iptxt): int
    {
        return str_contains($iptxt, ":") ? 6 : 4;
    }

}

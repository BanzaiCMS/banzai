<?php

namespace Banzai\Http\Filter;

class ipv6
{
    /**
     * Expand an IPv6 Address
     * This will take an IPv6 address written in short form and expand it to include all zeros.
     */

    public static function inet6_expand(string $addr): string
    {
        /* Check if there are segments missing, insert if necessary */
        if (str_contains($addr, '::')) {
            $part = explode('::', $addr);
            $part[0] = explode(':', $part[0]);
            $part[1] = explode(':', $part[1]);
            $missing = array();
            for ($i = 0; $i < (8 - (count($part[0]) + count($part[1]))); $i++)
                array_push($missing, '0000');
            $missing = array_merge($part[0], $missing);
            $part = array_merge($missing, $part[1]);
        } else {
            $part = explode(":", $addr);
        } // if .. else
        /* Pad each segment until it has 4 digits */
        foreach ($part as &$p) {
            while (strlen($p) < 4)
                $p = '0' . $p;
        } // foreach
        unset($p);
        /* Join segments */
        $result = implode(':', $part);
        /* Quick check to make sure the length is as expected */
        if (strlen($result) == 39) {
            return $result;
        } else {
            return false;
        } // if .. else
    }

    /**
     * Compress an IPv6 Address
     *
     * This will take an IPv6 address and rewrite it in short form.
     *
     * @param string $addr
     *            A valid IPv6 address
     * @return string The address in short form notation
     */
    public static function inet6_compress(string $addr): string
    {
        /* PHP provides a shortcut for this operation */
        return inet_ntop(inet_pton($addr));
    }

    /**
     * Generate an IPv6 mask from prefix notation
     *
     * This will convert a prefix to an IPv6 address mask (used for IPv6 math)
     *
     * @param integer $prefix
     *            The prefix size, an integer between 1 and 127 (inclusive)
     * @return string The IPv6 mask address for the prefix size
     */
    public static function inet6_prefix_to_mask($prefix): string|bool
    {
        $result = '';

        /* Make sure the prefix is a number between 1 and 127 (inclusive) */
        $prefix = intval($prefix);
        if ($prefix < 0 || $prefix > 128)
            return false;
        $mask = '0b';
        for ($i = 0; $i < $prefix; $i++)
            $mask .= '1';
        for ($i = strlen($mask) - 2; $i < 128; $i++)
            $mask .= '0';
        $mask = gmp_strval(gmp_init($mask), 16);
        for ($i = 0; $i < 8; $i++) {
            $result .= substr($mask, $i * 4, 4);
            if ($i != 7)
                $result .= ':';
        } // for
        return self::inet6_compress($result);
    }

    /**
     * Convert an IPv6 address and prefix size to an address range for the network.
     *
     * This will take an IPv6 address and prefix and return the first and last address available for the network.
     *
     * @param string $addr
     *            A valid IPv6 address
     * @param integer $prefix
     *            The prefix size, an integer between 1 and 127 (inclusive)
     * @return array An array with two strings containing the start and end address for the IPv6 network
     */
    public static function inet6_to_range(string $addr, int $prefix): array
    {

        $start_result = '';
        $end_result = '';

        $size = 128 - $prefix;
        $addr = gmp_init('0x' . str_replace(':', '', self::inet6_expand($addr)));
        $mask = gmp_init('0x' . str_replace(':', '', self::inet6_expand(self::inet6_prefix_to_mask($prefix))));
        $prefix = gmp_and($addr, $mask);
        // $start = gmp_strval(gmp_add($prefix, '0x1'), 16);
        $start = gmp_strval(gmp_add($prefix, '0x0'), 16);
        $end = '0b';
        for ($i = 0; $i < $size; $i++)
            $end .= '1';
        $end = gmp_strval(gmp_add($prefix, gmp_init($end)), 16);
        for ($i = 0; $i < 8; $i++) {
            $start_result .= substr($start, $i * 4, 4);
            if ($i != 7)
                $start_result .= ':';
        } // for
        for ($i = 0; $i < 8; $i++) {
            $end_result .= substr($end, $i * 4, 4);
            if ($i != 7)
                $end_result .= ':';
        } // for
        $result = array(
            self::inet6_compress($start_result),
            self::inet6_compress($end_result)
        );
        return $result;
    }


    /**
     * Convert an IPv6 address to two 64-bit integers.
     *
     * This will translate an IPv6 address into two 64-bit integer values for storage in an SQL database.
     *
     * @param $addr
     * @return array|null
     */
    public static function inet6_to_int64(string $addr): ?array    // TODO Fehlerbehandlung
    {
        /* Expand the address if necessary */
        if (strlen($addr) != 39) {
            $addr = self::inet6_expand($addr);
            if ($addr == false)
                return null;
        } // if
        $addr = str_replace(':', '', $addr);
        $p1 = '0x' . substr($addr, 0, 16);
        $p2 = '0x' . substr($addr, 16);
        $p1 = gmp_init($p1);
        $p2 = gmp_init($p2);
        $result = array(
            gmp_strval($p1),
            gmp_strval($p2)
        );
        return $result;
    }

    /**
     * Convert two 64-bit integer values into an IPv6 address
     *
     * This will translate an array of 64-bit integer values back into an IPv6 address
     *
     * @param array $val
     *            An array containing two strings representing 64-bit integer values
     * @return string An IPv6 address
     */
    public static function int64_to_inet6(array $val): string
    {
        /* Make sure input is an array with 2 numerical strings */
        $result = false;
        if (!is_array($val) || count($val) != 2)
            return $result;
        $p1 = gmp_strval(gmp_init($val[0]), 16);
        $p2 = gmp_strval(gmp_init($val[1]), 16);
        while (strlen($p1) < 16)
            $p1 = '0' . $p1;
        while (strlen($p2) < 16)
            $p2 = '0' . $p2;
        $addr = $p1 . $p2;
        for ($i = 0; $i < 8; $i++) {
            $result .= substr($addr, $i * 4, 4);
            if ($i != 7)
                $result .= ':';
        } // for
        return self::inet6_compress($result);
    }

    /**
     * @param $val
     * @return array
     */
    public static function inet6_split_prefix($val): array
    {
        $ret = array();
        $pos = strpos($val, '/');

        if ($pos === FALSE) {
            $ret['addr'] = $val;
            $ret['prefix'] = '128';
            return $ret;
        }
        $ret['addr'] = substr($val, 0, $pos);
        $ret['prefix'] = substr($val, $pos + 1);
        return $ret;
    }

    public static function inet6_filter_maskinterfacebits(string $addr): string
    {
        $tmp = self::inet6_to_int64($addr);
        $tmp[1] = 0;
        return self::int64_to_inet6($tmp);
    }

}

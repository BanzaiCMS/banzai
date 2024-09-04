<?php

namespace Banzai\Http\Tests\Filter;

use Banzai\Http\Filter\ipv6;
use PHPUnit\Framework\TestCase;

class ipv6Test extends TestCase
{
    public function test_inet6_to_range()
    {
        $ip = '2a00:4e00:2000::';
        $prefix = '36';

        $soll = array(
            0 => '2a00:4e00:2000::',
            1 => '2a00:4e00:2fff:ffff:ffff:ffff:ffff:ffff'
        );

        $result = ipv6::inet6_to_range($ip, $prefix);
        $this->assertEquals($soll, $result);
    }

    public function test_inet6_expand()
    {
        $ip = '2a00:4e00:2000::';
        $soll = '2a00:4e00:2000:0000:0000:0000:0000:0000';

        $result = ipv6::inet6_expand($ip);
        $this->assertEquals($soll, $result);
    }

    public function test_inet6_compress()
    {
        $ip = '2a00:4e00:2000:0000:0000:0000:0000:0000';
        $soll = '2a00:4e00:2000::';

        $result = ipv6::inet6_compress($ip);
        $this->assertEquals($soll, $result);

    }

    public function test_inet6_prefix_to_mask()
    {
        $prefix = 64;
        $soll = 'ffff:ffff:ffff:ffff::';
        $result = ipv6::inet6_prefix_to_mask($prefix);
        $this->assertEquals($soll, $result);

    }

    public function test_inet6_to_int64()
    {
        $ip = '2a00:4e00:2000::';

        $soll = array(
            0 => '3026504712036810752',
            1 => '0'
        );

        $result = ipv6::inet6_to_int64($ip);
        $this->assertEquals($soll, $result);

    }


    public function test_int64_to_inet6()
    {
        $ip = array(
            0 => '3026504712036810752',
            1 => '0'
        );

        $soll = '2a00:4e00:2000::';

        $result = ipv6::int64_to_inet6($ip);

        $this->assertEquals($soll, $result);

    }

    public function test_int64_to_inet6_small()
    {
        $ip = array(
            0 => '0',
            1 => '65535'
        );

        $soll = '::ffff';

        $result = ipv6::int64_to_inet6($ip);

        $this->assertEquals($soll, $result);

    }

    public function test_int64_to_inet6_small2()
    {
        $ip = array(
            0 => '0',
            1 => '65546'
        );

        $soll = '::0.1.0.10';

        $result = ipv6::int64_to_inet6($ip);

        $this->assertEquals($soll, $result);

    }


    public function test_inet6_split_prefix()
    {
        $ip = 'ffff:ffff:ffff:ffff::';

        $soll = array(
            'addr' => 'ffff:ffff:ffff:ffff::',
            'prefix' => '128'
        );

        $result = ipv6::inet6_split_prefix($ip);

        $this->assertEquals($soll, $result);

    }

    public function test_inet6_filter1()
    {
        $ip = '9:8:7:6:5:4:3:2';
        $soll = '9:8:7:6::';

        $result = ipv6::inet6_filter_maskinterfacebits($ip);

        $this->assertEquals($soll, $result);

    }

    public function test_inet6_filter2()
    {
        $ip = '9:8:7:6:5::';
        $soll = '9:8:7:6::';

        $result = ipv6::inet6_filter_maskinterfacebits($ip);

        $this->assertEquals($soll, $result);

    }

}

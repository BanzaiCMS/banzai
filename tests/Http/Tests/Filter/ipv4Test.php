<?php

namespace Banzai\Http\Tests\Filter;

use Banzai\Http\Filter\ipv4;
use PHPUnit\Framework\TestCase;

class ipv4Test extends TestCase
{
    public function test_cidr_to_range_net()
    {
        $ip = '192.129.55.0/24';

        $soll = array(
            'prefix' => '24',
            'iplow' => '192.129.55.0',
            'iphigh' => '192.129.55.255'
        );

        $result = ipv4::cidr_to_range($ip);
        $this->assertEquals($soll, $result);
    }

    public function test_cidr_to_range_host()
    {
        $ip = '192.129.55.1/32';

        $soll = array(
            'prefix' => '32',
            'iplow' => '192.129.55.1',
            'iphigh' => '192.129.55.1'
        );

        $result = ipv4::cidr_to_range($ip);
        $this->assertEquals($soll, $result);
    }
    public function test_cidr_to_range_class_a()
    {
        $ip = '192.0.0.0/8';

        $soll = array(
            'prefix' => '8',
            'iplow' => '192.0.0.0',
            'iphigh' => '192.255.255.255'
        );

        $result = ipv4::cidr_to_range($ip);
        $this->assertEquals($soll, $result);
    }
    public function test_cidr_to_range_class_b()
    {
        $ip = '192.168.0/16';

        $soll = array(
            'prefix' => '16',
            'iplow' => '192.168.0.0',
            'iphigh' => '192.168.255.255'
        );

        $result = ipv4::cidr_to_range($ip);
        $this->assertEquals($soll, $result);
    }

    public function test_ipv4ipv6_detection4()
    {
        $ip = '192.168.0';
        $soll = 4;

        $result = ipv4::getIPVersion($ip);
        $this->assertEquals($soll, $result);
    }

    public function test_ipv4ipv6_detection6()
    {
        $ip = '34:4:3::1';
        $soll = 6;

        $result = ipv4::getIPVersion($ip);
        $this->assertEquals($soll, $result);
    }

}

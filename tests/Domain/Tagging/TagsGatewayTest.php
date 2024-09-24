<?php
declare(strict_types=1);

namespace Banzai\Domain\Tests\Tagging;

use Banzai\Domain\Tagging\TagsGateway;
use Flux\Database\Driver\PDOMySQL;
use Flux\Logger\Logger;
use PHPUnit\Framework\TestCase;

class TagsGatewayTest extends TestCase
{

    public function testLeererEintragTostring()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(
            '',
            $tags->toString(array())
        );
    }


    public function testEinEintragTostring()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(
            'bla',
            $tags->toString(array(array('tagname' => 'bla')))
        );
    }

    public function testDreiEintraegeTostring()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(
            'bla;fasel;blubb',
            $tags->toString(array(array('tagname' => 'bla'), array('tagname' => 'fasel'), array('tagname' => 'blubb')))
        );

    }
}

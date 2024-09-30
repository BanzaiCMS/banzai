<?php
declare(strict_types=1);

namespace Banzai\Domain\Tests\Tagging;

use Flux\Database\Driver\PDOMySQL;
use Flux\Logger\Logger;
use Banzai\Domain\Tagging\TagsGateway;
use LogicException;
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

    public function testupdatetagnameNotFound()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);
        $dbstub->method('get')->willreturn(array());
        $dbstub->expects($this->never())->method('put');

        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(false, $tags->updatetagname('willi', 'article', 'Willi'));

    }

    public function testupdatetagnameNoChange()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);
        $dbstub->method('get')->willreturn(array('tagnameid' => 123, 'visname' => 'Willi'));
        $dbstub->expects($this->never())->method('put');

        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(true, $tags->updatetagname('willi', 'article', 'Willi'));

    }

    public function testupdatetagnameWithChange()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);
        $dbstub->method('get')->willreturn(array('tagnameid' => 123, 'visname' => 'Wonka'));

        $data = array();
        $data['visname'] = 'Willi';
        $data['tagnameid'] = 123;

        $dbstub->expects($this->once())
            ->method('put')
            ->with($this->equalTo('taglist'), $this->equalTo($data), $this->equalTo(array('tagnameid')), $this->equalTo(false))
            ->willReturn(true);

        $tags = new TagsGateway($dbstub, $loggerstub);

        $this->assertEquals(true, $tags->updatetagname('willi', 'article', 'Willi'));

    }

    public function testgettags()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);
        $dbstub->method('get')->willreturn(array('tagnameid' => 123, 'visname' => 'Wonka'));

        $sql = 'SELECT l.tagname FROM taglist l JOIN taggings t ON t.tagnameid=l.tagnameid  WHERE l.objclass=? AND t.objid=?';

        $dbstub->expects($this->once())
            ->method('getlist')
            ->with($this->equalTo($sql), $this->equalTo(array('wonka', 4711)))
            ->willReturn(array());

        $tags = new TagsGateway($dbstub, $loggerstub);
        $tags->gettags(4711, 'wonka');

    }

    public function testaddtagput()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);

        $dbstub->method('timestamp')
            ->willReturn('2024-04-05 01:02:03');


        $sql = 'SELECT * FROM taglist WHERE objclass=? AND tagname=?';

        $tagentry = array(
            'tagnameid' => 815,
            'tagcount' => 13
        );

        $dbstub->expects($this->once())
            ->method('get')
            ->with($this->equalTo($sql), $this->equalTo(array('classy', 'wonka')))
            ->willReturn($tagentry);

        $data = array(
            'tagnameid' => 815,
            'tagcount' => 14
        );

        $dbstub->expects($this->once())
            ->method('put')
            ->with($this->equalTo('taglist'), $this->equalTo($data), $this->equalTo(array('tagnameid')), $this->equalTo(false))
            ->willReturn(true);

        $data = array();
        $data['created'] = '2024-04-05 01:02:03';
        $data['objclass'] = 'classy';
        $data['objid'] = 4711;
        $data['tagnameid'] = 815;

        $dbstub->expects($this->once())
            ->method('add')
            ->with($this->equalTo('taggings'), $this->equalTo($data))
            ->willReturn(123);


        $tags = new TagsGateway($dbstub, $loggerstub);
        $tags->addtag(4711, 'wonka', 'classy', 'Wonka');

    }

    public function testaddtagadd()
    {
        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(PDOMySQL::class);

        $dbstub->method('timestamp')
            ->willReturn('2024-04-05 01:02:03');


        $sql = 'SELECT * FROM taglist WHERE objclass=? AND tagname=?';

        $dbstub->expects($this->once())
            ->method('get')
            ->with($this->equalTo($sql), $this->equalTo(array('classy', 'wonka')))
            ->willReturn(array());

        $dbstub->expects($this->never())
            ->method('put');

        $dbstub->expects($this->exactly(2))
            ->method('add')
            ->willReturnCallback(
                fn(string $table, array $data) => match (true) {
                    $table === 'taglist' && $data == array('tagcount' => 1, 'tagname' => 'wonka', 'visname' => 'Wonka', 'objclass' => 'classy', 'created' => '2024-04-05 01:02:03') => 62,
                    $table === 'taggings' && $data == array('tagnameid' => 62, 'objid' => 4711, 'objclass' => 'classy', 'created' => '2024-04-05 01:02:03') => 12,
                    default => throw new LogicException(print_r($data))
                }
            );


        $tags = new TagsGateway($dbstub, $loggerstub);
        $tags->addtag(4711, 'wonka', 'classy', 'Wonka');

    }


}

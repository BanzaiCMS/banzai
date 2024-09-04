<?php /** @noinspection PhpParamsInspection */
declare(strict_types=1);


namespace INS\Domain\Tests\Pictures;

use Flux\Config\Config;
use Flux\Database\Driver\PDOMySQL;
use Flux\Logger\Logger;
use Banzai\Domain\Pictures\PicturesGateway;
use PHPUnit\Framework\TestCase;


/**
 * Class PicturesGatewayTest
 *
 */
class PicturesGatewayTest extends TestCase
{

    public function testReturnValue0AndLOggermessageIfCatidIs0()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $paramstub = $this->createMock(Config::class);

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);
        $loggerstub->expects($this->once())
            ->method('error')
            ->with($this->equalTo('catid < 1'), $this->anything());


        $result = $pic->getPictureIDFromURL(0, '', '');

        $this->assertEquals(0, $result);
    }

    public function testReturnValue0andLOggerMassageIfContentIsEmpty()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $paramstub = $this->createMock(Config::class);

        $loggerstub->expects($this->once())
            ->method('warning')
            ->with($this->equalTo('content oder ext ist leer'), $this->anything());

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);
        $result = $pic->getPictureIDFromURL(1, '', 'hmtl');

        $this->assertEquals(0, $result);
    }

    public function testReturnValue0andLoggermesageIfExtensionIsEmpty()
    {
        $dbstub = $this->createMock(PDOMySQL::class);
        $loggerstub = $this->createMock(Logger::class);
        $paramstub = $this->createMock(Config::class);

        $loggerstub->expects($this->once())
            ->method('warning')
            ->with($this->equalTo('content oder ext ist leer'), $this->anything());

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);
        $result = $pic->getPictureIDFromURL(1, 'index', '');

        $this->assertEquals(0, $result);
    }

    public function testReturnValue0IfRkatIsEmpty()
    {
        $loggerstub = $this->createMock(Logger::class);
        $dbstub = $this->createMock(PDOMySQL::class);
        $paramstub = $this->createMock(Config::class);


        $dbstub->method('get')->willReturn(array());

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);

        $result = $pic->getPictureIDFromURL(0, 'blub', 'hmtl');
        $this->assertEquals(0, $result);
    }

    public function testReturnValueintIfRkatIsNotEmpty()
    {
        $loggerstub = $this->createMock(Logger::class);
        $dbstub = $this->createMock(PDOMySQL::class);
        $paramstub = $this->createMock(Config::class);

        $dbstub->method('get')->willReturn(array('id' => 2));

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);
        $result = $pic->getPictureIDFromURL(1, 'bla', 'fasel');
        $this->assertEquals(2, $result);
    }

    /**
     * @param $sqlstmt
     * @param $bind
     * @return array
     */
    public function dbgetcallback($sqlstmt, $bind): array
    {
        if ($sqlstmt !== 'SELECT id FROM pictures WHERE categories_id=:catid AND url=:url') return array();

        if (is_array($bind) && isset($bind['catid']) && isset($bind['url'])) {
            if ($bind['catid'] == 0) return array();
            elseif ($bind['catid'] == 1) {
                if ($bind['url'] == '.fasel') return array();
                elseif ($bind['url'] == 'bla.') return array();
                elseif (($bind['url']) == 'bla.fasel') return array('id' => 47);
            } elseif (($bind['catid'] == 2) && ($bind['url'] == 'bla.fasel')) return array();
        } else return array();

        return array();
    }


    /**
     * @return array
     */
    public function testGetCallandReturnArrayneu()
    {

        $loggerstub = $this->createMock(Logger::class);
        $dbstub = $this->createMock(PDOMySQL::class);
        $paramstub = $this->createMock(Config::class);


        //müssen gesetzt werden fuer die verschiedenen Testfälle

        //Werte auf 0, 1 oder 2 setzen
        $catid = 2;
        //auf 'bla' oder '' setzen
        $content = 'bla';
        //auf 'fasel' oder '' setzen
        $ext = '';

        $url = $content . '.' . $ext;

        $bind = array();

        $dbstub->expects($this->any())
            ->method('get')
            ->with($this->equalTo('SELECT id FROM pictures WHERE categories_id=:catid AND url=:url'),
                $this->equalTo(array('catid' => $catid, 'url' => $url)))
            ->willReturnCallback(array($this, 'dbgetcallback'));

        $pic = new PicturesGateway($dbstub, $loggerstub, $paramstub);
        $result = $pic->getPictureIDFromURL($catid, $content, $ext);
        if ($catid == 0)
            $this->assertEquals(0, $result);
        if ($catid == 1) {
            if ($bind['url'] == '.fasel') $this->assertequals(0, $result);
            elseif ($bind['url'] == 'bla.') $this->assertequals(0, $result);
            elseif (($bind['url']) == 'bla.fasel') return array('id' => 47);
        } elseif ($catid == 2) $this->assertequals(0, $result);

        return array();

    }


    //----------------------------------------------------------------------------------------------------------------

    //testen der Methode getRandomPictureID

    /*
    public function testReturnValue0IfRowIsEmpty()
    {
        $loggerstub = $this->createMock(Logger::class);
        $dbstub = $this->createMock(db::class);

        $dbstub->method('get')->willReturn(array());

        $pic = new PicturesGateway($dbstub, $loggerstub);

        $result = $pic->getRandomPictureID(1, 'blub', 'hmtl');

        $this->assertEquals(0, $result);
    }


    public function testReturnValueIntIfRowIsNotEmpty()
    {
        $loggerstub = $this->createMock(Logger::class);
        $dbstub = $this->createMock(db::class);

        $dbstub->method('get')->willReturn(array('id' => 2));

        $pic = new PicturesGateway($dbstub, $loggerstub);
        $result = $pic->getRandomPictureID(1, 'blub', 'hmtl');
        $this->assertEquals(2, $result);
    }


    public function testGetCallandReturnPictureID()
    {

        $loggerstub = $this->createMock(Logger::class);

        $dbstub = $this->createMock(db::class);


        $dbstub->expects($this->once())
            ->method('get')
            ->with($this->equalTo('SELECT id FROM pictures WHERE aktiv="ja" AND gallery_pic="yes"  ORDER BY RAND()'),
                $this->equalTo(array()))
            ->willReturn(array('id' => 17, 'bla' => 'fasel'));


        $pic = new PicturesGateway($dbstub, $loggerstub);

        $result = $pic->getRandomPictureID(0, 'index', 'html');


        $this->assertequals(17, $result);


    }*/


}

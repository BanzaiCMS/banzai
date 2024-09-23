<?php
// here we do not set  declare(strict_types=1), we have to update the mktime/ statements first !!
// TODO strict types conversion and set declare

namespace Banzai\Domain\Pictures;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use Twig\Environment as Twig;
use Flux\Database\DatabaseInterface;
use Flux\Config\Config;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Http\FileResponse;

class PicturesGateway
{
    const string PIC_TABLE = 'pictures';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected Config $params, protected ?Twig $twig = null)
    {
    }

    /**
     * gets the image ID from the database for a category and a URL
     */
    public function getPictureIDFromURL(int $catid = 0, string $content = '', string $ext = ''): int
    {

        $url = $content . '.' . $ext;

        if ($catid < 1) {
            $this->logger->error('catid < 1');
            return 0;
        }

        if (empty($content) || empty($ext)) {
            $this->logger->warning('content oder ext ist leer');
            return 0;
        }

        $sql = 'SELECT id FROM ' . self::PIC_TABLE . ' WHERE categories_id=:catid AND url=:url';
        $binding = array('catid' => $catid, 'url' => $url);

        $rkat = $this->db->get($sql, $binding);

        if (empty ($rkat))
            return 0;
        else
            return (int)$rkat ['id'];
    }


    public function getPictureHeaderData(int $pic_id): array
    {
        $row = $this->db->get('SELECT id,format,image,url,disposition,storagepath FROM ' . self::PIC_TABLE . ' WHERE id=?', array($pic_id));

        if (empty($row))
            return array();

        $row['cache'] = $this->params->get('system.cache.files');
        $row['filepath'] = $this->params->get('path.storage.image') . $row['storagepath'];

        return $row;
    }


    public function getPictureHeader(array $pic): array
    {

        $ret = array();

        $pic_format = $pic ['format'];

        $cache = $pic['cache'];

        if (empty($cache))
            $cache = 3600;

        if ($cache == 'nocache') {
            $ret['Pragma'] = 'no-cache';
            $ret["Cache-Control"] = "private, must-revalidate, post-check=0, pre-check=0";
            $ret["Expires"] = "0";
        } else {

            if ($cache == 'cache')
                $secs = 3600;
            else
                $secs = $cache;


            try {
                $tz = new DateTimeZone('UTC');
                $dt = new DateTime('now', $tz);
                $di = DateInterval::createFromDateString($secs . ' seconds');
                $dt->add($di);
            } catch (Exception $e) {
                $dt = null;
            }

            if (!is_null($dt)) {
                $ret['Expires'] = $dt->format('D, d M Y H:i:s') . ' GMT';
            }

            $ret['Pragma'] = 'public';
            $ret['Cache-Control'] = 'max-age=' . $secs . ', public';

        }

        if ($pic ['disposition'] == 'attachment') {
            $fname = $pic ['url'];
            $ret["Content-type"] = "application/octet-stream";
            $ret['Content-Description'] = '"' . $fname . '"';
            $ret['Content-Disposition'] = 'attachment; filename="' . $fname . '"';
        } else {
            $ret["Content-type"] = "image/" . $pic_format;
        }

        return $ret;

    }


    #[NoReturn]
    public function sendPicture(int $pic_id): void
    {

        $pic = $this->getPictureHeaderData($pic_id);
        if (empty($pic))
            exit (0);

        $header = $this->getPictureHeader($pic);

        // to free session for other requests
        session_write_close();
        @ob_end_flush();

        FileResponse::create($pic['filepath'], $header)->send();
        exit (0);

    }

    public function getPictureURL(int $id = 0, bool $encodeurl = true): string
    {

        if ($id < 1) {
            $this->logger->error('id < 1');
            return '';
        }

        $row = $this->db->get('SELECT categories_id,url FROM ' . self::PIC_TABLE . ' WHERE id=?', array($id));

        if (empty($row)) {
            return '';
        }

        if ($encodeurl)
            $purl = rawurlencode($row ['url']);
        else
            $purl = $row ['url'];

        return Application::get(FoldersGateway::class)->getFullFolderURL($row ['categories_id']) . $purl;
    }


    public function getPictureLinkdata(int $id, int $wi = 0, int $hei = 0, $fullcaturl = null, bool $parent = true, string $absurl = 'no'): array
    {

        if ($id < 1) {
            $this->logger->error('id < 1');
            return array();
        }

        $sql = 'SELECT id,categories_id,url,alt,width,height,parent_picture_id,bildtext,quelle,object_template,teaser_template,keyword,descr,descr_type,targeturl FROM ' . self::PIC_TABLE . ' WHERE id=?';
        $row = $this->db->get($sql, array($id));

        if (empty ($row)) {
            return array();
        }

        $url = $row ['url'];
        $alt = $row ['alt'];
        $width = $row ['width'];
        $height = $row ['height'];

        if ($fullcaturl == null)
            $kurl = Application::get(FoldersGateway::class)->getFullFolderURL($row ['categories_id']);
        else
            $kurl = $fullcaturl;

        $ww = $width;
        $hh = $height;

        if (($wi != 0) && ($hei != 0)) { // Specify a fixed size, possibly in the future clipping here
            $ww = $wi;
            $hh = $hei;
        }


        if (($wi != 0) && ($hei == 0)) { // Width Fixed Vary height, maintain aspect ratio
            $faktor = $wi / $width;
            $hh = ( int )($height * $faktor);
            $ww = $wi;
        }

        if (($wi == 0) && ($hei != 0)) { // Width variable, height fixed, aspect ratio maintained
            $faktor = $hei / $height;
            $ww = ( int )($width * $faktor);
            $hh = $hei;
        }

        $re ['pic_id'] = $row ['id'];
        $re ['pic_parent_id'] = $row ['parent_picture_id'];

        $re ['pic_url'] = $kurl . rawurlencode($url);

        $re ['pic_alt'] = $alt;
        $re ['pic_width'] = $ww;
        $re ['pic_height'] = $hh;
        $re ['pic_subtext'] = $row ['bildtext'];
        $re ['pic_source'] = $row ['quelle'];
        $re ['pic_template'] = $row ['object_template'];
        $re ['pic_keyword'] = $row ['keyword'];
        $re ['pic_descr'] = $row ['descr'];
        $re ['pic_descr_type'] = $row ['descr_type'];
        $re ['pic_targeturl'] = $row['targeturl'];


        if (empty ($re ['pic_template']))
            $re ['pic_template'] = 'image';

        if (($parent) && ($row ['parent_picture_id'] > 0) && ($row ['id'] != $row ['parent_picture_id'])) // über-Bild Daten holen ...
            $re ['pic_parent'] = $this->getPictureLinkdata($row ['parent_picture_id'], 0, 0, false);

        return $re;
    }

    /**
     * Display a list of all IDs of gallery images in a category
     */
    public function getGalleryPictureIDs(int $catid = 0, int $maxanzahl = 0, string $type = 'gallery_pic'): array
    {

        if ($catid < 1) {
            $this->logger->error('catid < 1');
            return array();
        }

        $sql = 'SELECT id FROM ' . self::PIC_TABLE . ' WHERE categories_id=:id AND aktiv="ja" AND ' . $type . '="yes" ORDER BY sortorder,bildtext,url';

        $binding = array('id' => $catid);

        if ($maxanzahl > 0) {
            $sql .= ' LIMIT 0,:anzahl';
            $binding['anzahl'] = $maxanzahl;
        }

        return $this->db->getlist($sql, $binding, '', 'id');
    }

    public function getRandomPictureID(int $catid = 0): int
    {

        $sql = "SELECT id FROM " . self::PIC_TABLE . ' WHERE aktiv="ja" AND gallery_pic="yes" ';

        $binding = array();

        if ($catid > 0) {
            $sql .= " AND categories_id=:catid";
            $binding['catid'] = $catid;
        }

        $sql .= ' ORDER BY RAND()';
        $row = $this->db->get($sql, $binding);

        if (empty ($row))
            return 0;
        else
            return $row ['id'];
    }

    public function getPictureHTML(int $picid = 0, string $absurl = 'no'): string
    {

        if ($picid < 1) {
            $this->logger->warning('picid < 1');
            return '';
        }

        $pida = $this->getPictureLinkdata($picid, 0, 0, null, true, $absurl);

        if (empty ($pida))
            return '';

        if (is_null($this->twig)) {
            $inci = $this->params->get('path.templates') . 'widgets/' . $pida ['pic_template'] . '.php';
            if (file_exists($inci)) {
                ob_start(); // start output buffer
                include($inci);
                $ret = ob_get_contents(); // read ob2 ("b")
                ob_end_clean();
                $ret = str_replace("\n", '', $ret);
                if (!empty($ret))
                    return $ret;
            }
        } else {
            $templatfile = 'widgets/' . $pida ['pic_template'] . '.html.twig';
            if (file_exists($this->params->get('path.twig.templates') . $templatfile)) {
                $ret = '';
                try {
                    $ret = $this->twig->render($templatfile, $pida);
                    $ret = str_replace("\n", '', $ret);

                } catch (Exception $e) {
                    $this->logger->error('Twig-Exception: ' . $e->getMessage() . ' Template: ' . $templatfile);
                }
                if (!empty($ret))
                    return $ret;
            }
        }

        return '<img class="wimg" alt="' . $pida ['pic_alt'] . '" src="' . $pida ['pic_url'] . '" border="0" height="' . $pida ['pic_height'] . '" width="' . $pida ['pic_width'] . '"/>';

    }


    public function createStorageName(int $id, string $extension = ''): string
    {
        if (!empty($extension))
            $extension = '.' . $extension;

        $rest = $id % 100;
        $subdir = sprintf('%02u', $rest);
        $fullpath = $this->params->get('path.storage.image') . $subdir;

        if (!is_dir($fullpath))
            if (!mkdir($fullpath, 0777, true)) {
                $this->logger->error('can not create directory ' . $fullpath);
                return '';
            }

        return $subdir . DIRECTORY_SEPARATOR . sprintf('%u', $id) . $extension;

    }

}


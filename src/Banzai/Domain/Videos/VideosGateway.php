<?php
declare(strict_types=1);

namespace Banzai\Domain\Videos;

use JetBrains\PhpStorm\NoReturn;
use function date;
use function date_default_timezone_set;
use function header;
use function ini_set;
use function mktime;
use function session_write_close;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Http\FileResponse;
use Banzai\Renderers\RenderersGateway;
use Banzai\Domain\Pictures\PicturesGateway;

class VideosGateway
{
    const string VIDEO_TABLE = 'videos';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected PicturesGateway $PicturesGateway)
    {
    }

    /**
     * retrieves a video record from the database for a category and a URL
     */
    public function getVideoIDFromURL(int $catid = 0, string $content = '', string $ext = ''): int
    {
        $curl = $content . "." . $ext;

        if ($catid < 1) {
            $this->logger->error('catid < 1');
            return 0;
        }

        if (empty($content) || empty($ext)) {
            $this->logger->warning('content oder ext ist leer');
            return 0;
        }

        $sql = 'SELECT id FROM ' . self::VIDEO_TABLE . ' WHERE categories_id=:catid AND url=:curl';
        $binding = array('catid' => $catid, 'curl' => $curl);

        $rkat = $this->db->get($sql, $binding);

        if (empty ($rkat))
            return 0;

        return (int)$rkat ['id'];
    }

    public function getVideoHeaderData(int $id): array
    {
        $row = $this->db->get('SELECT * FROM ' . self::VIDEO_TABLE . ' WHERE id=?', array($id));

        if (empty($row))
            return array();

        $row['cache'] = Application::get('config')->get('system.cache.files');  // TODO
        $row['filepath'] = Application::get('config')->get('path.storage.video') . $row['localfilename'];     // TODO

        return $row;
    }

    public function getVideoHeader(array $row): array
    {

        $ret = array();

        $mimea = $row ["mimea"];
        $mimeb = $row ["mimeb"];

        $cache = $row['cache'];

        if ($cache == 'nocache') {
            $ret['Pragma'] = 'no-cache';
            $ret["Cache-Control"] = "private, must-revalidate, post-check=0, pre-check=0";
            $ret["Expires"] = "0";
        } else {
            if ($cache == 'cache')
                $secs = 3600;
            else
                $secs = (int)$cache;

            header('Cache-Control: max-age=' . $secs . ', public');

            date_default_timezone_set('UTC');
            $mt = mktime((int)date("H"), (int)date("i"), (int)date("s") + $secs, (int)date("n"), (int)date("j"), (int)date("Y"));

            $ed = date('D, d M Y H:i:s', $mt);
            $ret['Expires'] = $ed . ' GMT';
            $ret['Pragma'] = 'public';

        }

        $fname = $row ['url'];

        $ret["Content-Length"] = (string)$row ['asize'];

        if ($row ['disposition'] == 'attachment') {
            $ret["Content-type"] = $mimea . '/' . $mimeb;
            $ret['Content-Description'] = '"' . $fname . '"';
            $ret['Content-Disposition'] = 'attachment; filename="' . $fname . '"';
        } else {
            $ret["Content-type"] = $mimea . '/' . $mimeb;
            if ($row ['disposition'] == 'inline')
                $ret['Content-Disposition'] = 'inline; filename="' . $fname . '"';
        }
        return $ret;

    }

    #[NoReturn]
    public function sendVideo(int $att_id): void
    {
        $row = $this->getVideoHeaderData($att_id);
        $headers = $this->getVideoHeader($row);

        session_write_close();
        ini_set('zlib.output_compression', '0');
        @ob_end_flush();

        FileResponse::create($row['filepath'], $headers)->send();

        exit (0);
    }

    public function getVideo(int $att_id): array
    {
        return $this->db->get('SELECT * FROM ' . self::VIDEO_TABLE . ' WHERE id=?', array($att_id));
    }

    public function getTeaserlist(int $kategid, int $langid = 0): array
    {

        global $my_preview; // TODO replace

        $ret = array();

        $binding = array('kategid' => $kategid);
        $sql = 'SELECT id,address_id,objclass,article_id,language_id,author_id,categories_id,image_id,asize,width,height,url,title,descr,descr_type,active,mimea,mimeb,lastchange,storage,localfilename,object_template,teaser_template,keywords,volltextsuche,disposition,feed_enabled,feed_id  FROM ' . self::VIDEO_TABLE . ' WHERE categories_id=:kategid';

        if ($langid > 0) {
            $sql .= ' AND language_id=:langid';
            $binding['langid'] = $langid;
        }

        if (!isset ($my_preview))
            $sql .= " AND  active = 'yes' ";

        $items = $this->db->getlist($sql, $binding);

        foreach ($items as $item) {
            $item ['titel2'] = $item ['title'];
            $item ['kurztext'] = $item ['descr'];
            $item ['kurztext_type'] = $item ['descr_type'];
            $ret [] = $item;
        }

        return $ret;
    }

    public function getVideoInfo(int $id): array
    {

        // everything except field content !
        $sql = 'SELECT id,fullurl,objclass,article_id,language_id,author_id,categories_id,asize,url,title,descr,active,mimea,mimeb' . ',lastchange,teaser_template,image_id,descr_type,width,height FROM ' . self::VIDEO_TABLE . ' WHERE id=?';

        $item = $this->db->get($sql, array($id));

        if (empty($item))
            return array();

        if (empty ($item ['object_template']))
            $item ['object_template'] = 'video';

        if ($item ['image_id'] > 0) {
            $pida = $this->PicturesGateway->getPictureLinkdata($item ['image_id']);
            $item ['pic_url'] = $pida ['pic_url'];
            $item ['pic_alt'] = $pida ['pic_alt'];
            $item ['pic_width'] = $pida ['pic_width'];
            $item ['pic_height'] = $pida ['pic_height'];
            $item ['pic_subtext'] = $pida ['pic_subtext'];
            $item ['pic_source'] = $pida ['pic_source'];
        }

        $item ['descr'] = RenderersGateway::RenderText($item ['descr'], $item ['descr_type']);

        return $item;
    }

    public function createStorageName(int $id, string $extension = ''): string
    {
        if (!empty($extension))
            $extension = '.' . $extension;

        $rest = $id % 100;
        $subdir = sprintf('%02u', $rest);
        $fullpath = Application::get('config')->get('path.storage.video') . $subdir;

        if (!is_dir($fullpath))
            if (!mkdir($fullpath, 0777, true)) {
                $this->logger->error('can not create directory ' . $fullpath);
                return '';
            }

        return $subdir . DIRECTORY_SEPARATOR . sprintf('%u', $id) . $extension;

    }
}

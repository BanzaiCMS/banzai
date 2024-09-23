<?php
declare(strict_types=1);

namespace Banzai\Domain\Files;

use function date_default_timezone_set;
use function session_write_close;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Http\FileResponse;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Domain\Pictures\PicturesGateway;

class FilesGateway
{
    const string FILE_TABLE = 'attachements';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected PicturesGateway $PicturesGateway)
    {
    }

    /**
     * retrieves an additional record from the database for a category and a URL
     *
     */
    public function getFileIDfromURL(int $catid = 0, string $content = '', string $ext = ''): int
    {
        if ($catid < 1) {
            $this->logger->error('catid < 1');
            return 0;
        }

        if (empty($content) || empty($ext)) {
            $this->logger->warning('content oder ext ist leer');
            return 0;
        }

        $curl = $content . "." . $ext;

        $rkat = $this->db->get('SELECT id FROM ' . self::FILE_TABLE . ' WHERE categories_id=? AND url=?', array($catid, $curl));

        if (empty($rkat))
            return 0;
        else
            return (int)$rkat['id'];
    }


    public function getFileHeaderData(int $attid): array
    {
        $row = $this->db->get('SELECT id,mimea,mimeb,disposition,url,asize,storagepath FROM ' . self::FILE_TABLE . ' WHERE id=?', array($attid));

        if (empty($row))
            return array();

        $row['cache'] = Application::get('config')->get('system.cache.files');  // TODO
        $row['filepath'] = Application::get('config')->get('path.storage.file') . $row['storagepath'];     // TODO

        return $row;
    }

    public function getFileHeader(array $row): array
    {
        $ret = array();

        $mimea = $row["mimea"];
        $mimeb = $row["mimeb"];

        if ($row['cache'] == 'nocache') {
            $ret['Pragma'] = 'no-cache';
            $ret["Cache-Control"] = "private, must-revalidate, post-check=0, pre-check=0";
            $ret["Expires"] = "0";
        } else {

            if ($row['cache'] == 'cache')
                $secs = 3600;
            else
                $secs = (int)$row['cache'];

            $ret['Cache-Control'] = 'max-age=' . $secs . ', public';

            date_default_timezone_set('UTC');
            $mt = mktime((int)date("H"), (int)date("i"), (int)date("s") + $secs, (int)date("n"), (int)date("j"), (int)date("Y"));

            $ed = date('D, d M Y H:i:s', $mt);
            $ret['Expires'] = $ed . ' GMT';
            $ret['Pragma'] = 'public';
        }

        $fname = $row['url'];

        $ret["Content-Length"] = $row['asize'];

        if ($row['disposition'] == 'attachment') {
            $ret['Content-type'] = "application/octet-stream";
            $ret['Content-Description'] = '"' . $fname . '"';
            $ret['Content-Disposition'] = 'attachment; filename="' . $fname . '"';
        } else {
            $ret["Content-type"] = $mimea . '/' . $mimeb;
            if ($row['disposition'] == 'inline')
                $ret['Content-Disposition'] = 'inline; filename="' . $fname . '"';
        }

        return $ret;
    }

    public function sendFile(int $att_id): void
    {

        $att = $this->getFileHeaderData($att_id);


        if (empty($att))
            return;

        $headers = $this->getFileHeader($att);

        session_write_close();

        FileResponse::create($att['filepath'], $headers)->send();
        exit (0);

    }

    public function getFile(int $att_id): array
    {
        return $this->db->get('SELECT * FROM ' . self::FILE_TABLE . ' WHERE id=?', array($att_id));
    }

    public function getTeaserlist(int $kategid, int $langid = 0, bool $withdatekey = false, $locatobj = null, int $count = 0): array
    {
        global $my_preview; // TODO remove

        // everything except field content !
        $sql = "SELECT id,objclass,attdatetime,article_id,language_id,author_id,categories_id,asize,url,title,descr,active,mimea,mimeb" . ",lastchange,teaser_template,image_id,descr_type,newwindow FROM " . self::FILE_TABLE . " WHERE categories_id=:kategid";
        $binding = array('kategid' => $kategid);

        if ($langid > 0) {
            $sql .= ' AND language_id=:langid';
            $binding['langid'] = $langid;
        }

        if (!isset($my_preview))
            $sql .= " AND  active = 'yes' ";

        if (empty($locatobj))
            $locatobj = $this->db->get('SELECT sortorder_elements,sub_teaser_template FROM ' . FoldersGateway::FOLDER_TABLE . ' WHERE categories_id=?', array($kategid));

        if (!empty($locatobj))
            $sorti = $locatobj['sortorder_elements'];
        else
            $sorti = 'datedesc';

        switch ($sorti) {
            case 'alphaasc':
                $qsort = 'sort_order, title ASC';
                break;
            case 'alphadesc':
                $qsort = 'sort_order, title DESC';
                break;
            case 'dateasc':
                $qsort = 'sort_order, attdatetime ASC';
                break;
            case 'datedesc':
            default:
                $qsort = 'sort_order, attdatetime DESC';
                break;
        }

        $sql .= " ORDER BY " . $qsort;
        if ($count > 0) {
            $sql .= ' LIMIT :count';
            $binding['count'] = $count;
        }

        $items = $this->db->getlist($sql, $binding);
        $ret = array();

        foreach ($items as $item) {
            $item['titel2'] = $item['title'];
            $item['kurztext'] = $item['descr'];
            $item['kurztext_type'] = $item['descr_type'];
            $item['objtype'] = $item['objclass'];

            if (!empty($locatobj['sub_teaser_template']))
                $item['teaser_template'] = $locatobj['sub_teaser_template'];

            if ($withdatekey)
                $ret[$item['attdatetime']] = $item;
            else
                $ret[] = $item;
        }
        return $ret;
    }

    public function getFileInfo(int $id): array
    {
        if ($id < 1)
            return array();

        // everything except field content !

        $sql = 'SELECT id,fullurl,objclass,article_id,language_id,author_id,categories_id,asize,url,title,descr,active,mimea,mimeb,lastchange,teaser_template,object_template,image_id,descr_type,newwindow FROM ' . self::FILE_TABLE . ' WHERE id=?';
        $item = $this->db->get($sql, array($id));

        if (empty ($item))
            return array();

        if ($item['image_id'] > 0) {
            $pida = $this->PicturesGateway->getPictureLinkdata($item['image_id']);
            $item['pic_url'] = $pida['pic_url'];
            $item['pic_alt'] = $pida['pic_alt'];
            $item['pic_width'] = $pida['pic_width'];
            $item['pic_height'] = $pida['pic_height'];
            $item['pic_subtext'] = $pida['pic_subtext'];
            $item['pic_source'] = $pida['pic_source'];
        }

        if (empty($item['object_template']))
            $item['object_template'] = 'file';

        return $item;
    }

    public function createStorageName(int $id, string $extension = ''): string
    {
        if (!empty($extension))
            $extension = '.' . $extension;

        $rest = $id % 100;
        $subdir = sprintf('%02u', $rest);
        $fullpath = Application::get('config')->get('path.storage.file') . $subdir;

        if (!is_dir($fullpath))
            if (!mkdir($fullpath, 0777, true)) {
                $this->logger->error('can not create directory ' . $fullpath);
                return '';
            }

        return $subdir . DIRECTORY_SEPARATOR . sprintf('%u', $id) . $extension;

    }
}

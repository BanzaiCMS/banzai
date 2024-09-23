<?php
declare(strict_types=1);

namespace Banzai\Domain\Folders;

use Banzai\Core\Application;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Videos\VideosGateway;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Files\FilesGateway;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Domain\Products\ProductsGateway;

class FoldersGateway
{
    const string FOLDER_TABLE = 'categories';

    public function __construct(protected ?DatabaseInterface $db = null, protected ?LoggerInterface $logger = null, protected ?ArticlesGateway $articles = null)
    {
    }

    public function _inject(DatabaseInterface $db, LoggerInterface $logger, ArticlesGateway $articles): void
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->articles = $articles;
    }


    /**
     * Returns a category/folder array for a URL
     *
     * If $parentid is not null: get category matching (parentid,url)
     * If $parentid is null : get category matching (url) (parentid is ignored)
     */
    public function getFolderFromURL(string $url = '', ?int $parentid = null, bool $ignoreActiveState = false): array
    {

        $binding = array();

        $sql = 'SELECT * FROM ' . self::FOLDER_TABLE;
        $ad = ' WHERE ';

        $sql .= $ad . ' fullurl=:url ';
        $binding['url'] = $url;
        $ad = ' AND ';

        if (!is_null($parentid)) {
            $sql .= $ad . '  parent_id=:parentid ';
            $binding['parentid'] = $parentid;
            $ad = ' AND ';
        }

        if (!$ignoreActiveState) {
            $sql .= $ad . " active = 'yes' ";
        }

        $catobj = $this->db->get($sql, $binding);

        if (!empty($catobj))
            $catobj = $this->transformFolder($catobj);

        return $catobj;
    }

    function getTeaserlist(int $curr_cat_id = 0, array $sarr = array(), int $tiefe = 0, int $maxtiefe = 0, $locatobj = null): array
    {
        global $my_preview;     // TODO replace
        global $catobj;         // TODO replace

        if (!empty($sarr))
            $ret = $sarr;
        else
            $ret = array();

        if ($maxtiefe > 0)
            if ($tiefe > $maxtiefe)
                return $ret;

        if ($curr_cat_id < 1)
            $curr_cat_id = 0;

        if (!isset($locatobj))
            $locatobj = $catobj;

        $sql = 'SELECT * FROM ' . self::FOLDER_TABLE . ' WHERE  parent_id=?';

        if (!isset($my_preview))
            $sql = $sql . ' AND  active="yes"';

        if ($maxtiefe == 0)
            $sql .= " AND (visible='all' OR visible='list')";
        else
            $sql .= " AND (visible<>'none')";

        if (isset($locatobj))
            $sorti = $locatobj['sortorder_elements'];
        else
            $sorti = 'datedesc';

        $qsort = match ($sorti) {
            'alphaasc' => 'sort_order, categories_name ASC',
            'alphadesc' => 'sort_order, categories_name DESC',
            'dateasc' => 'sort_order, date_added ASC',
            default => 'sort_order, date_added DESC',
        };

        $sql .= " ORDER BY " . $qsort;

        $liste = $this->db->getlist($sql, array($curr_cat_id));

        foreach ($liste as $rkat) {
            if (Application::get('user')->hasPermission($rkat['access_perm_code'])) {
                // Session variable also allows this ...

                $artid = $this->articles->getDefaultArticleID($rkat['categories_id']);

                if ($artid > 0) {
                    $reco = $this->db->get('SELECT * FROM ' . ArticlesGateway::ART_TABLE . ' WHERE article_id=?', array($artid));
                    $reco = $this->articles->transformArticle($reco);
                } else {
                    // Set parameters manually for show_artobj ...
                    $reco = array();
                    $reco['url'] = 'index';
                    $reco['categories_id'] = $rkat['categories_id'];
                    $reco['titel2'] = $rkat['categories_name'];
                    $reco['image_id'] = $rkat['image_id'];
                }

                if ($maxtiefe > 0)
                    $reco['tiefe'] = $tiefe;

                $ret[] = $reco;

                if ($maxtiefe > 0)
                    $ret = $this->getTeaserlist($reco['categories_id'], $ret, $tiefe + 1, $maxtiefe);

            }

        }

        if ($maxtiefe > 0)
            $ret = $this->articles->getTeaserlist($curr_cat_id, $ret, $tiefe, 'all');

        return $ret;
    }

    public function transformFolder(array $catobj): array
    {
        if (!defined('INSCCMS_TWIG')) {

            if (empty($catobj['layout_template']))
                $catobj['layout_template'] = 'default';

            if (empty($catobj['content_template']))
                $catobj['content_template'] = 'c_default';

            // We still need it because many functions depend on it
            // but it should eventually be completely replaced by define

            if (empty($catobj['templates_basedir']))  // Not set
                $catobj['templates_basedir'] = 'templates';

            if (empty($catobj['stylesheet'])) // Not set
                $catobj['stylesheet'] = 'formate.css';

        }

        if ($catobj['image_id'] > 0) {
            $pida = Application::get(PicturesGateway::class)->getPictureLinkdata($catobj['image_id'], 0, 0); //Todo deprecated_functions.php

            $catobj['pic_url'] = $pida['pic_url'];
            $catobj['pic_alt'] = $pida['pic_alt'];
            $catobj['pic_width'] = $pida['pic_width'];
            $catobj['pic_height'] = $pida['pic_height'];
            $catobj['pic_subtext'] = $pida['pic_subtext'];
            $catobj['pic_source'] = $pida['pic_source'];
        }

        return $catobj;
    }


    /**
     * Returns a category/folder array for a catid
     */
    function getFolder(int $catid): array
    {

        if ($catid < 1) {
            $this->logger->error('catid<1');
            return array();
        }

        $catobj = $this->db->get('SELECT * FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($catid));

        if (empty($catobj)) {
            $this->logger->warning('Keine Kategorie gefunden');
            return $catobj;
        }

        return $this->transformFolder($catobj);
    }

    function getFolderName($cid): string
    {
        $te = $this->db->get('SELECT categories_name FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return '';

        return $te['categories_name'];
    }

    function getFolderTitle($cid): string
    {
        $te = $this->db->get('SELECT categories_pagetitle FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return '';

        return $te['categories_pagetitle'];
    }

    function getFolderTemplate(int $cid): string
    {
        $te = $this->db->get('SELECT layout_template FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return '';

        return $te['layout_template'];
    }

    function getFolderContentTemplate(int $cid): string
    {
        $te = $this->db->get('SELECT content_template FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return 'c_default';

        return $te['content_template'];
    }

    function getFolderImageID($cid): int
    {
        $te = $this->db->get('SELECT image_id FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return 0;

        return $te['image_id'];
    }


    function getFolderOnlyDefaultArticle($cid): string
    {
        $te = $this->db->get('SELECT only_default_art FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));
        if (empty($te))
            return '';

        return $te['only_default_art'];
    }

    function getFolderID(string $curl, int $lid): int
    {

        $sql = 'SELECT categories_id FROM ' . self::FOLDER_TABLE . ' WHERE language_id=:lid AND categories_url=:curl ';

        $binding = array('lid' => $lid, 'curl' => $curl);

        $rkat = $this->db->get($sql, $binding);

        if (empty($rkat)) {
            $this->logger->error('category not found for cid=' . $curl);
            return 0;
        }

        return (int)$rkat['categories_id'];

    }

    /**
     * generates a URL from a category ID
     */
    function getFullFolderURL(int $cid = 0): string
    {
        if ($cid < 1) {
            $this->logger->error('cid<1');
            return '';
        }

        $rkat = $this->db->get('SELECT language_id, categories_url,fullurl FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?', array($cid));

        if (empty($rkat)) {
            $this->logger->error('category not found for cid=' . $cid);
            return '';
        }
        return $rkat['fullurl'];
    }

    function makeFullFolderURL(array $rkat = array()): string
    {
        if (empty($rkat)) {
            $this->logger->warning('cat is empty');
            return '';
        }

        return $rkat['fullurl'];
    }

    function getMenueRoot(int $langid): int
    {
        $sql = 'SELECT  categories_id FROM ' . self::FOLDER_TABLE . ' WHERE parent_id=? AND language_id=?';

        $rkat = $this->db->get($sql, array(0, $langid));
        if (empty($rkat))
            return 1;

        return (int)$rkat['categories_id'];

    }


    /**
     * Returns the parent ID for the current category
     */
    function getParentFolderID(int $kat_id = 0): int
    {

        if ($kat_id < 1)
            return 0;

        $sql = "SELECT categories_id,parent_id FROM " . self::FOLDER_TABLE . " WHERE  categories_id=?";
        $rkat = $this->db->get($sql, array($kat_id));

        if (empty($rkat))
            return 0;

        return (int)$rkat['parent_id'];
    }

    /**
     * Returns the topmost category ID (in the category tree)
     * Below the start category
     * for the current category
     */
    function getTopFolderIDFromFolder(int $kat_id): int
    {
        if ($kat_id < 1)
            return 0;

        $parent = $this->getParentFolderID($kat_id);

        $pparent = $this->getParentFolderID($parent);

        if ($pparent == 0)
            return $kat_id;

        return $this->getTopFolderIDFromFolder($parent);
    }

    function folderCheckRekursion($actid, $parentid, $treetype, $rekursion): bool
    {
        if ($rekursion < 1)
            return false;

        $rekursion--;

        if (($parentid == 0) || empty($parentid))
            return true;

        if ($parentid == $actid)
            return false;

        $sql = 'SELECT categories_id,parent_id FROM ' . self::FOLDER_TABLE . ' WHERE categories_id=?';
        $ka = $this->db->get($sql, array($parentid));

        if (empty($ka))
            return false;

        $pi = $ka['parent_id'];

        return $this->folderCheckRekursion($actid, $pi, $treetype, $rekursion);
    }


    /**
     * Returns all elements contained in a category as an array
     * If there are no elements in the category, then return value is empty
     */
    function countFolderElements($catid = 0): array
    {
        $ret = array('folder' => 0, 'article' => 0, 'teaser' => 0, 'product' => 0, 'picture' => 0, 'file' => 0, 'video' => 0);

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . self::FOLDER_TABLE . ' WHERE parent_id=?', array($catid));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['folder'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . ArticlesGateway::ART_TABLE . ' WHERE categories_id=? and objtype<>? and objtype<>?', array($catid, 'teaser', 'alias'));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['article'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . ArticlesGateway::ART_TABLE . ' WHERE categories_id=? and (objtype=? OR objtype=?) ', array($catid, 'teaser', 'alias'));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['teaser'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . ProductsGateway::PRODUCTS_TABLE . ' WHERE categories_id=?', array($catid));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['product'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . PicturesGateway::PIC_TABLE . ' WHERE categories_id=?', array($catid));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['picture'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . FilesGateway::FILE_TABLE . ' WHERE categories_id=?', array($catid));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['file'] = $anz['anz'];

        $anz = $this->db->get('SELECT count(*) as anz FROM ' . VideosGateway::VIDEO_TABLE . ' WHERE categories_id=?', array($catid));
        if (!empty($anz))
            if ($anz['anz'] > 0)
                $ret['video'] = $anz['anz'];

        return $ret;
    }

}

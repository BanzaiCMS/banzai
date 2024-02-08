<?php
declare(strict_types=1);

namespace Banzai\Domain\Articles;

use Exception;
use DOMDocument;

use Banzai\Core\Application;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Flux\Config\Config;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Http\Routing\RouteProviderInterface;
use Banzai\Renderers\RenderersGateway;
use Banzai\I18n\Locale\LocaleServiceInterface;
use Twig\Environment as Twig;

use const COMMENTS_TABLE;

// Todo remove
use const FEEDS_TABLE;

// Todo remove
use const GEOOBJ_TABLE;

// Todo remove
use const TAGGING_TABLE;

// Todo remove
use const TAGLIST_TABLE;

// Todo remove
use function getHTTPpage;

// Todo remove
use function limit_string;

// Todo remove
use function makeSEOCleanURL;

// Todo remove
use function meinBlogArchivDatum;

// Todo remove
use function send_commentmail;

// Todo remove

/**
 * Class ArticlesGateway
 * @package Banzai\Domain\Articles
 */
class ArticlesGateway
{
    const ART_TABLE = 'article';

    protected RouteProviderInterface $router;

    public function __construct(protected ?DatabaseInterface $db = null, protected ?LoggerInterface $logger = null, protected ?Config $params = null, protected ?Twig $twig = null, protected ?LocaleServiceInterface $locale = null, protected ?FoldersGateway $folders = null)
    {
    }

    public function _inject(DatabaseInterface $db, LoggerInterface $logger, Config $params, Twig $twig, LocaleServiceInterface $locale, FoldersGateway $folders)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->folders = $folders;
        $this->locale = $locale;
        $this->params = $params;
        $this->twig = $twig;
    }

    /**
     * Liefert die ArtikelId eines Artikels zu einer Url und einem Ordner
     * Wenn nicht gefunden, dann 0
     *
     * @param string $url
     * @param int $catid
     * @param bool $all
     * @return int
     */
    public function getArticleIDFromURL(string $url = '', int $catid = 0, bool $all = false): int
    {
        if ($catid < 1) {
            $this->logger->error('catid < 1');
            return 0;
        }

        $binding = array();

        $sql = 'SELECT article_id FROM ' . self::ART_TABLE . ' WHERE categories_id=:catid AND url=:url';

        $binding['catid'] = $catid;
        $binding['url'] = $url;

        if (!$all) {
            $sql = $sql . " AND astatus = 'aktiv' ";

            if (Application::getApplication()->isStaging())
                $sql .= ' AND stagingstate<>"notinstage" ';
            else
                $sql .= ' AND stagingstate<>"stageonly" ';
        }

        $row = $this->db->get($sql, $binding);

        if (empty ($row))
            return 0;
        else
            return (int)$row['article_id'];

    }


    /**
     * @param int $folderid
     * @param bool $ignoreActiveState
     * @return array
     */
    public function getIndexArticle(int $folderid = 0, bool $ignoreActiveState = false): array
    {

        $sql = 'SELECT * FROM ' . self::ART_TABLE . ' WHERE categories_id=? AND category_article="ja"';

        if (!$ignoreActiveState) {
            $sql .= ' AND astatus = "aktiv"';
            if (Application::getApplication()->isStaging())
                $sql .= ' AND stagingstate<>"notinstage" ';
            else
                $sql .= ' AND stagingstate<>"stageonly" ';
        }

        $row = $this->db->get($sql, array($folderid));

        if (empty ($row))
            return array();

        return $this->transformArticle($row);

    }


    /**
     * @param string $url
     * @param int $folderid
     * @param string $ext
     * @param bool $ignoreActiveState
     * @param bool $noindex
     * @return array
     */
    public function getArticleFromURL(string $url = '', int $folderid = 0, string $ext = 'html', bool $ignoreActiveState = false, bool $noindex = true): array
    {

        $sql = 'SELECT * FROM ' . self::ART_TABLE . ' WHERE url=:url AND extension=:ext';
        $binding = array('url' => $url, 'ext' => $ext);

        if ($noindex)
            $sql .= ' AND category_article="nein"';

        if ($folderid > 0) {
            $sql .= ' AND (categories_id=:catida OR secondary_categories_id=:catidb)';
            $binding['catida'] = $folderid;
            $binding['catidb'] = $folderid;
        }

        if (!$ignoreActiveState) {
            $sql .= ' AND astatus = "aktiv"';

            if (Application::getApplication()->isStaging())
                $sql .= ' AND stagingstate<>"notinstage" ';
            else
                $sql .= ' AND stagingstate<>"stageonly" ';

        }

        $row = $this->db->get($sql, $binding);

        if (empty ($row))
            return array();

        return $this->transformArticleToShow($row);
    }


    /**
     * @param int $id
     * @param bool $isdebug
     * @return int
     */
    public function importRSSFeed(int $id, bool $isdebug = false): int
    {

        $tb = $this->db->get(' SELECT * FROM ' . FEEDS_TABLE . ' WHERE feed_id=?', array($id));

        $context = array('feedid' => $id);

        if (empty($tb)) {
            $this->logger->error('feedid not found', $context);
            return 0;
        }

        if ($tb ['categories_id'] < 1) {
            $this->logger->error('catid<1', $context);
            return 0;
        }

        if ($isdebug)
            $this->logger->debug('URL: ' . $tb ['feedurl'], $context);

        $resp = getHTTPpage($tb ['feedurl'], 'Mozilla/5.0 (Windows; U; Windows NT 5.1; de; rv:1.9.0.1) Gecko/2008070208 Firefox/3.0.1.', false, '', 'GET', array(), true, '', false);
        //Todo liegt momentan in functions

        if (!isset($resp['status'])) {
            $this->logger->error('CURL-Fehler: status is not set', $context);
            return 0;
        }

        if ($resp['status'] == -1) {
            $this->logger->warning('CURL-Fehler: ' . $resp['body'], $context);
            return 0;
        }

        if (empty ($resp['body'])) {
            $this->logger->warning('HTTP-Antwort:' . $resp['status'] . ' Body ist leer', $context);
            return 0;
        }

        $domDocument = new DOMDocument ();
        $domDocument->preserveWhiteSpace = false;

        $okidoki = $domDocument->loadXML($resp['body']);
        restore_error_handler();

        if (!$okidoki) {
            $this->logger->warning('Fehler bei loadXML', $context);
            return 0;
        }

        $artb = array();
        $artb ['titel1'] = $tb ['feedname'];
        $artb ['kurztext_type'] = 'html';
        $artb ['langtext_type'] = 'html';
        $artb ['feed_id'] = $tb ['feed_id'];
        $artb ['categories_id'] = $tb ['categories_id'];
        $artb ['object_template'] = $tb ['object_template'];
        $artb ['teaser_template'] = $tb ['teaser_template'];
        $artb ['astatus'] = 'aktiv';
        $artb ['visible'] = $tb ['visible'];
        $artb ['visible_sitemaps'] = $tb ['visible_sitemaps'];
        $artb ['feed_enabled'] = $tb ['feed_redistribute'];

        $zaehler = 0;

        foreach ($domDocument->getElementsByTagName('item') as $element) {
            $art = $artb;
            $link = '';
            $guid = '';
            $zaehler += 1;
            foreach ($element->childNodes as $cnode)
                if ($cnode->nodeType == 1) {
                    $in = $cnode->textContent;
                    $nn = $cnode->nodeName;

                    switch ($nn) {
                        case 'title' :
                            $art ['titel2'] = $in;
                            break;
                        case 'guid' :
                            $guid = urldecode($in);
                            break;
                        case 'link' :
                            $link = urldecode($in);
                            break;
                        case 'description' :
                            $art ['kurztext'] = $in;
                            break;
                        case 'content:encoded' :
                            $art ['langtext'] = $in;
                            break;
                    }
                }

            $url = $art ['titel2'];

            if ($tb ['feedclass'] == 'twitter') {
                $tit = $art ['titel2'];
                $tit = htmlspecialchars_decode($tit);
                $pos = strpos($tit, ':');
                if ($pos !== false)
                    $tit = substr($tit, $pos + 2);

                $twa = explode(' ', $tit);
                $twt = '';
                $twc = '';
                $url = '';
                foreach ($twa as $twit) {
                    if (strncasecmp($twit, 'http://', 7) == 0) {
                        $twc .= '<a href="' . $twit . '">' . $twit . '</a> ';
                        continue;
                    }

                    $twt .= $twit . ' ';
                    $twc .= $twit . ' ';
                    $url .= $twit . ' ';

                }
                $url = str_replace('#', '', $url);
                $art ['titel2'] = $twt;
                $art ['langtext'] = $twc;
                $art ['kurztext'] = $twc;
            }

            if (empty ($link))
                $art ['url_external'] = $guid;
            else
                $art ['url_external'] = $link;

            if (empty ($art ['langtext']))
                $art ['langtext'] = $art ['kurztext'];

            if (empty ($art ['kurztext']))
                $art ['kurztext'] = $art ['langtext'];
            $art ['pagetitle'] = $art ['titel2'];
            $art ['navititel'] = $art ['titel2'];
            $art ['linktitle'] = $art ['titel2'];
            $art ['url'] = makeSEOCleanURL($url);//Todo liegt momentan in functions

            $item = $this->getArticleIDFromURL($art ['url'], $art ['categories_id']);

            if (!empty($item))  // bereits vorhanden, also nicht erneut importieren
                continue;

            $art['verfassdat'] = $this->db->timestamp();

            $this->db->add(self::ART_TABLE, $art);
        }

        $data = array('feed_id' => $tb ['feed_id']);
        $data['updated'] = $this->db->timestamp();
        $this->db->put(FEEDS_TABLE, $data, array('feed_id'), false);

        return $zaehler;
    }


    /**
     * Elemente/Artikel eines Ordners ausgeben. Standardfunktion von c_default
     *
     * @param int $catid
     * @param array|null $sarr
     * @param int|null $tiefe
     * @param string|null $was
     * @param int|null $actid
     * @param int|null $language_id
     * @param array|null $locatobj
     * @param int $count
     * @param bool $withdatekey
     * @param int $authorid
     * @return array
     */
    public function getTeaserlist(?int $catid, ?array $sarr = array(), ?int $tiefe = 0, ?string $was = 'list', ?int $actid = 0, ?int $language_id = 0, ?array $locatobj = array(), int $count = 0, bool $withdatekey = false, int $authorid = 0): array
    {
        global $userobj;    // TODO global eliminieren
        global $my_preview; // TODO global eliminieren

        if (is_null($sarr))
            $sarr = array();

        if (is_null($locatobj))
            $locatobj = array();

        $ret = $sarr;

        if (!is_null($catid)) {
            if ($catid < 1)
                return $ret;
        } else {
            $catid = 0;
        }

        if (is_null($tiefe))
            $tiefe = 0;

        if (is_null($was))
            $was = 'list';

        if (is_null($actid))
            $actid = 0;

        $actid = (int)$actid;

        if (empty ($locatobj) && ($catid > 0))
            $locatobj = $this->db->get('SELECT sortorder_elements,sub_teaser_template FROM ' . FoldersGateway::FOLDER_TABLE . ' WHERE categories_id=?', array($catid));

        $dat = date("Y-m-d H:i:s");

        $bind = array();
        $sql = 'SELECT * FROM ' . self::ART_TABLE . ' WHERE (category_article="nein" OR navititle_element<>"")  AND archiv="nein" AND (aktivdat<=:adat OR aktivdat IS NULL) AND (expiredat>:edat OR expiredat is null) ';
        $bind['adat'] = $dat;
        $bind['edat'] = $dat;

        if ($catid > 0) {
            $bind['catid'] = $catid;
            $sql .= ' AND categories_id=:catid ';
        }

        if (!is_null($language_id))
            if ($language_id > 0) {
                $bind['langid'] = $language_id;
                $sql .= ' AND language_id=:langid ';
            }

        switch ($was) {
            case 'list' :
                $sql .= " AND (visible='list' or visible='all')  ";
                break;
            case 'menu' :
                $sql .= " AND (visible='menu' or visible='all')  ";
                break;
            case 'all' :
            default :
                $sql .= " AND (visible<>'none') ";
        }

        $sql .= " AND (main_article_id=0 OR main_article_id=article_id OR main_article_id IS NULL) ";

        if (!isset ($my_preview)) {
            $sql .= ' AND astatus = "aktiv"';

            if (Application::getApplication()->isStaging())
                $sql .= ' AND stagingstate<>"notinstage" ';
            else
                $sql .= ' AND stagingstate<>"stageonly" ';

        }

        if ($authorid > 0) {
            $sql .= ' AND author_id=:authorid ';
            $bind['authorid'] = $authorid;
        }

        if (!empty ($locatobj ['sortorder_elements']))
            $sorti = $locatobj ['sortorder_elements'];
        else
            $sorti = 'datedesc';

        switch ($sorti) {
            case 'alphaasc' :
                $qsort = 'sort_order, titel2 ASC';
                break;
            case 'alphadesc' :
                $qsort = 'sort_order, titel2 DESC';
                break;
            case 'dateasc' :
                $qsort = 'sort_order, verfassdat ASC';
                break;
            case 'datedesc' :
            default :
                $qsort = 'sort_order, verfassdat DESC';
                break;
        }

        $sql .= " ORDER BY " . $qsort;

        if ($count > 0) {
            $sql .= ' LIMIT :limit';
            $bind['limit'] = $count;
        }

        $liste = $this->db->getlist($sql, $bind);

        if (empty($liste))
            return $ret;

        if (isset($userobj ['user_id']))
            $uid = $userobj ['user_id'];
        else
            $uid = 0;

        foreach ($liste as $art) {

            if ($art ['visible_user'] == 'anon')
                if ($uid > 0)
                    continue;

            if ($art ['visible_user'] == 'login')
                if ($uid == 0)
                    continue;

            if (!empty ($locatobj ['sub_teaser_template']))
                $art ['teaser_template'] = $locatobj ['sub_teaser_template'];

            if (!empty($art['navititle_element']))
                $art['navititel'] = $art['navititle_element'];

            $art = $this->transformArticle($art);
            $art = $this->transformArticleToShow($art);

            if (isset ($tiefe))
                $art ['tiefe'] = $tiefe;
            if (isset ($actid))
                $art ['act_id'] = $actid;

            if ($withdatekey)
                $ret [$art ['verfassdat']] = $art;
            else
                $ret [] = $art;
        }
        return $ret;
    }


    /**
     * @param int $catid
     * @return int
     */
    public function getDefaultArticleID(int $catid = 0): int
    {
        if ($catid < 1)
            return 0;

        $sql = 'SELECT article_id FROM ' . self::ART_TABLE . ' WHERE categories_id=? AND category_article ="ja" AND astatus="aktiv"';

        if (Application::getApplication()->isStaging())
            $sql .= ' AND stagingstate<>"notinstage" ';
        else
            $sql .= ' AND stagingstate<>"stageonly" ';

        $art = $this->db->get($sql, array($catid));

        if (empty($art))
            return 0;

        return $art['article_id'];
    }


    /**
     * @return int
     */
    public function getLatestBlogpostArticleID(): int
    {


        $sql = 'SELECT article_id FROM ' . self::ART_TABLE . ' WHERE objtype="blogpost" AND astatus="aktiv" ';

        if (Application::getApplication()->isStaging())
            $sql .= ' AND stagingstate<>"notinstage" ';
        else
            $sql .= ' AND stagingstate<>"stageonly" ';

        $sql .= ' ORDER BY verfassdat DESC';


        $this->db->get($sql);

        if (empty ($art))
            return 0;

        return $art ['article_id'];

    }


    /**
     * @param int $catid
     * @param string|null $ftag
     * @return int
     */
    public function getArtIDFromTeaserlabel(int $catid = 0, string $ftag = null): int
    {

        if (is_null($ftag))
            return 0;

        $binding = array();

        $sql = 'SELECT article_id FROM ' . self::ART_TABLE . ' WHERE teaserkeys_passive=:ftag';
        $binding['ftag'] = $ftag;

        if ($catid > 0) {
            $sql .= ' AND categories_id=:catid';
            $binding['catid'] = $catid;
        }

        if (Application::getApplication()->isStaging())
            $sql .= ' AND stagingstate<>"notinstage" ';
        else
            $sql .= ' AND stagingstate<>"stageonly" ';


        $art = $this->db->get($sql, $binding);

        if (empty ($art))
            return 0;

        return $art ['article_id'];
    }

    /**
     * @param int $art_id
     * @return string
     */
    public function getArtURLFromID(int $art_id): string
    {

        if ($art_id < 1) {
            $this->logger->error('artid<1');
            return '';
        }

        $row = $this->db->get('SELECT categories_id,url,extension FROM ' . self::ART_TABLE . ' WHERE article_id=?', array($art_id));

        if (empty($row)) {
            $this->logger->warning('artid=' . $art_id . ' not found');
            return '';
        }

        $caturl = $this->folders->getFullFolderURL($row ['categories_id']);
        return $caturl . $row ['url'] . '.' . $row ['extension'];

    }

    /**
     * @param array $art
     * @return array
     */
    public function transformArticleToShow(array $art): array
    {

        if (empty($art))
            return array();

        // Nur einmal transformieren ...
        if (isset ($art ['art_is_transformed_toshow']))
            return $art;

        $art ['art_is_transformed_toshow'] = true;

        if (isset ($art ['main_article_id']))
            $main_article_id = $art ['main_article_id'];
        else
            $main_article_id = 0;

        if (isset ($art ['next_article_id']))
            $next_article_id = $art ['next_article_id'];
        else
            $next_article_id = 0;

        if (isset ($art ['prev_article_id']))
            $prev_article_id = $art ['prev_article_id'];
        else
            $prev_article_id = 0;


        $art ['url'] = rawurlencode($art ['url']);

        if ((!empty ($art ['url_external']) && ($art ['objtype'] == 'external')))
            $art ['fullurl'] = $art ['url_external'];

        $art ['titel2'] = str_replace('&lt;br/&gt;', '<br/>', $art ['titel2']);

        if (!empty ($art ['kurztext']))
            $art ['kurztext'] = RenderersGateway::RenderText($art ['kurztext'], $art ['kurztext_type']);//Todo liegt momentan in render.php

        if (!empty ($art ['langtext']))
            $art ['langtext'] = RenderersGateway::RenderText($art ['langtext'], $art ['langtext_type']);//Todo liegt momentan in render.php

        if (($main_article_id != 0) && ($main_article_id != $art ['article_id']))
            $art ['main_art_url'] = $this->getArtURLFromID($main_article_id);

        if ($next_article_id != 0)
            $art ['next_art_url'] = $this->getArtURLFromID($next_article_id);

        if ($prev_article_id != 0)
            $art ['prev_art_url'] = $this->getArtURLFromID($prev_article_id);

        if (!defined('INSCCMS_TWIG')) {

            // Falls kein Template angegeben, dann standard-template
            if (empty ($art ['object_template'])) {
                if ($art ['objtype'] == 'blogpost')
                    $art ['object_template'] = 'blogpost';
                else
                    $art ['object_template'] = 'article';
            }

            if (empty ($art ['teaser_template']))
                $art ['teaser_template'] = 'teaser';
        }

        if (($art ['image_id'] > 0) && (empty ($art ['pic_url']))) {
            $pida = Application::get(PicturesGateway::class)->getPictureLinkdata($art ['image_id'], 0, 0);//Todo deprecated function
            $art ['pic_url'] = $pida ['pic_url'];
            $art ['pic_alt'] = $pida ['pic_alt'];
            $art ['pic_width'] = $pida ['pic_width'];
            $art ['pic_height'] = $pida ['pic_height'];
            $art ['pic_subtext'] = $pida ['pic_subtext'];
            $art ['pic_source'] = $pida ['pic_source'];
        }

        if (isset ($art ['thumbnail_id']))
            if (($art ['thumbnail_id'] > 0) && (empty ($art ['thumbnail_url']))) {
                $pida = Application::get(PicturesGateway::class)->getPictureLinkdata($art ['thumbnail_id'], 0, 0);
                $art ['thumbnail_url'] = $pida ['pic_url'];
                $art ['thumbnail_alt'] = $pida ['pic_alt'];
                $art ['thumbnail_width'] = $pida ['pic_width'];
                $art ['thumbnail_height'] = $pida ['pic_height'];
                $art ['thumbnail_subtext'] = $pida ['pic_subtext'];
                $art ['tumbnail_source'] = $pida ['pic_source'];
            }

        if ($art ['author_id'] > 0) {
            $art ['author_name'] = \Banzai\Domain\Users\UsersGateway::get_user_displayname($art ['author_id']);//Todo liegt momentan in users.php
        }

        if (!empty ($art ['keywords'])) {
            $tags = explode(',', $art ['keywords']);
            $art ['tags'] = $tags;
        }
        return ($art);
    }

    /**
     * @param array $art
     * @param string $atype
     * @param null $noshow
     * @param string $linktext
     * @return array|false|string
     */
    public function showArticle(array $art, string $atype = 'main', $noshow = null, string $linktext = '')
    {
        global $itemdir;

        $art = $this->transformArticleToShow($art);

        if (!empty ($linktext))
            $art ['linktext'] = $linktext;
        else
            $art ['linktext'] = $art ['linktitle'];

        if ($atype == 'main')
            $inci = $itemdir . $art ['object_template'] . '.php';
        else
            $inci = $itemdir . $art ['teaser_template'] . '.php';

        if (empty ($noshow)) {
            include($inci);
            return array();
        } else {
            ob_start(); // start output buffer
            include($inci);
            $obuf = ob_get_contents(); // read ob2 ("b")
            ob_end_clean();
            return $obuf;
        }
    }

    /**
     * @param array $art
     * @return string
     */
    public function renderTeaser(array $art): string
    {
        if (is_null($this->twig)) {
            $this->logger->error('Twig not set');
            return '';
        }

        $art = $this->transformArticleToShow($art);

        $templatfile = 'widgets/' . $art ['teaser_template'] . '.html.twig';
        if (file_exists($this->params->get('path.twig.templates') . $templatfile)) {
            $ret = '';
            try {
                $ret = $this->twig->render($templatfile, $art);
            } catch (Exception $e) {
                $this->logger->error('Twig-Exception: ' . $e->getMessage());
            }
            if (!empty($ret))
                return $ret;
        }
        return '';
    }

    /**
     * @param int $katid
     * @return array
     */
    public function artLine(int $katid): array
    {

        global $language_id; // TODO global entfernen


        $dat = date("Y-m-d");
        $ui = $_SESSION ["s_uid"];

        if ($ui < 1) // Nicht eingeloggt
            $bla = " visible_user<>'login' AND ";
        else
            $bla = " visible_user<>'anon' AND ";


        $binding = array();
        $sql = "SELECT * FROM " . self::ART_TABLE . " where categories_id=:katid AND " . $bla .
            " category_article='nein' AND archiv='nein' AND (aktivdat<=:dat1 OR aktivdat IS NULL) AND  (expiredat>:dat2 OR expiredat IS NULL) AND " .
            " language_id=:language_id AND (visible='menu' or visible='all') AND  astatus = 'aktiv' ";

        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;
        $binding['katid'] = $katid;
        $binding['language_id'] = $language_id;

        $liste = $this->db->getlist($sql, $binding);
        if (empty($liste))
            return array();


        foreach ($liste as $art) {

            $categories_id = $art ["categories_id"];

            $arturl = $art ["url"];

            $navititel = $art ["navititel"];

            $cat = $this->folders->getFullFolderURL($categories_id);

            $url = $cat . $arturl . '.html';
            $name = $navititel;

            $art ['url'] = $url;
            $art ['name'] = $name;

            $liste [] = $art;

        }

        return $liste;

    }

    /**
     * @param array $art
     * @return array
     */
    public function makeArticleFullURL(array $art): array
    {

        if ((!empty ($art ['url_external']) && (($art ['objtype'] == 'external') || ($art ['objtype'] == 'alias'))))
            $art ['fullurl'] = $art ['url_external'];

        return $art;
    }

    /**
     * @param array|null $art
     * @return array
     */
    public function transformArticle(?array $art = null): array
    {

        if (empty ($art))
            return array();

        // Wir sind Teaser oder ALIAS auf eigenen Artikel
        // url,fullurl werden ggf. vom anderen Artikel genommen ...
        if (($art['objtype'] == 'teaser') || ($art ['objtype'] == 'alias')) {
            if (!empty($art['url_external'])) {        // verlinkung in template (navi oder ziel-url) auf diese URL
                $art['fullurl'] = $art['url_external'];
            } else if ($art['objnextid'] > 0) {
                $nobj = $this->db->get('SELECT url,fullurl FROM ' . self::ART_TABLE . ' WHERE article_id=?', array($art['objnextid']));
                if (empty($nobj)) {
                    $this->logger->warning('no target article found. art-id=' . $art['article_id'] . ' target-id=' . $art['objnextid']);
                } else {
                    $art ['url'] = $nobj ['url'];
                    $art ['fullurl'] = $nobj ['fullurl'];
                }
            }
        }

        if ($art ['image_id'] > 0) {
            $pida = Application::get(PicturesGateway::class)->getPictureLinkdata($art ['image_id'], 0, 0);
            $art ['pic_url'] = $pida ['pic_url'];
            $art ['pic_alt'] = $pida ['pic_alt'];
            $art ['pic_width'] = $pida ['pic_width'];
            $art ['pic_height'] = $pida ['pic_height'];
            $art ['pic_subtext'] = $pida ['pic_subtext'];
            $art ['pic_source'] = $pida ['pic_source'];
        }

        if ($art ['geoobj'] > 0)
            $art ['geo'] = $this->db->get('SELECT * FROM ' . GEOOBJ_TABLE . ' WHERE id =?', array($art ['geoobj']));

        if (empty ($art ['linktitle']))
            $art ['linktitle'] = $art ['titel2'];

        $art ['url'] = rawurlencode($art ['url']);


        if (!is_null($art ['language_id'])) {

            if (!is_int($art ['language_id']))      // some old deprecated mysql-functions i.e. fetch_row() in templates do not deliver int type
                $art ['language_id'] = (int)$art ['language_id'];

            if ($art ['language_id'] > 0) {
                $l = $this->locale->getLanguageFromID($art ['language_id']);
                if (!empty($l)) {
                    $art ['languageobj'] = $l;
                    $art ['langcode'] = $l['code'];
                }
            }
        }

        if (empty ($art ['layout_template'])) {
            if (defined('INSCCMS_TWIG'))
                $art ['layout_template'] = 'content';
            else
                $art ['layout_template'] = 'c_default';
        }

        // Um in der Eingabemaske Klassenpfade mit Punkten eingeben zu koennen
        // ist mehr allgemein
        $art ['contentclass'] = str_replace('.', '_', $art ['contentclass']);

        $art['description'] = str_replace("\n", '', $art ['description']);
        $art['description'] = str_replace("\r", '', $art ['description']);
        $art['description'] = str_replace('"', '', $art ['description']);

        return $art;
    }


    /**
     * @param int $artid
     * @return array
     */
    public function getArticle(int $artid = 0): array
    {

        if ($artid < 1)
            return array();

        $art = $this->db->get('SELECT * FROM ' . self::ART_TABLE . ' WHERE article_id=?', array($artid));

        if (empty($art))
            return array();

        return $this->transformArticle($art);

    }

    /**
     * @param array|null $blogart
     * @param string|null $prevnext
     * @return array
     */
    public function getNextBlogArt(array $blogart = null, string $prevnext = null): array
    {

        global $my_preview;

        if (empty($prevnext))
            $prevnext = 'next';

        $dat = date("Y-m-d H:i:s");

        $sql = 'SELECT * FROM ' . self::ART_TABLE . ' WHERE objtype="blogpost" ';

        if (!isset ($my_preview))
            $sql .= ' AND astatus="aktiv" ';

        $binding = array();
        if (!empty($blogart)) {

            $binding['verfassdat'] = $blogart ['verfassdat'];

            if ($prevnext == 'next')
                $sql .= ' AND verfassdat>:verfassdat';
            else
                $sql .= ' AND verfassdat<:verfassdat ';

        }

        $sql .= " AND category_article='nein' AND archiv='nein' AND (aktivdat<=:dat1 OR aktivdat IS NULL) AND  (expiredat>:dat2 OR expiredat is null) ";
        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;

        if (Application::getApplication()->isStaging())
            $sql .= ' AND stagingstate<>"notinstage" ';
        else
            $sql .= ' AND stagingstate<>"stageonly" ';

        if ($prevnext == 'next')
            $sql .= ' ORDER BY verfassdat ASC ';
        else
            $sql .= ' ORDER BY verfassdat DESC';

        $row = $this->db->get($sql, $binding);

        if (empty($row))
            return array();

        return $this->transformArticle($row);

    }

    /**
     * @param int $catid
     * @return array
     */
    public function getArticleRandom(int $catid = 0): array
    {

        $sql = "SELECT * FROM " . self::ART_TABLE . " WHERE astatus = 'aktiv' ";

        $binding = array();

        if ($catid > 0) {
            $sql .= " AND (categories_id=:catid1  OR secondary_categories_id=:catid2) ";
            $binding['catid1'] = $catid;
            $binding['catid2'] = $catid;
        }

        $sql .= ' ORDER BY RAND() LIMIT 1';

        $row = $this->db->get($sql, $binding);

        if (empty($row))
            return array();

        $art = $this->transformArticle($row);

        return $art;
    }


    /**
     * Teaser/Artikel eines bestimmtens TAGS suchen und ausgeben
     *
     * catid         - ID der Kategorie, wenn 0 dann alle
     * tags          - semikolon getrennte liste von keywords, oeffentlich sichtabre TAGS
     * subcl         - wenn null/leer, dann alle, sonst nur teaser
     * auchleere     - wenn true, werden auch teaser mit leerem keywords-feld angezeigt
     * nurnewsticker - wenn true werden nur teaser/artikel mit gesetztem newstickerflag angezeigt
     * language_id   - wenn groesser null, nur artikel/teaser mit dieser laguage_id
     * teaserkeys    - wenn true, werden die neuen (11.01.07) teaserkeys felder verwendet, sonst die tags
     * sortorder     - Sortierung der Einträge: 0: Datum absteigend (wie bisher - DESC), 1: Datum aufsteigend (ASC), älteste zuerst
     *
     * @param int $catid
     * @param string|null $tags
     * @param string|null $subcla
     * @param bool $auchleere
     * @param bool $nurnewsticker
     * @param int|null $language_id
     * @param bool $teaserkeys
     * @param int $sortorder
     * @return array
     */
    public function getTeaserTaglist(int $catid = 0, ?string $tags = '', ?string $subcla = '', bool $auchleere = false, bool $nurnewsticker = false, ?int $language_id = null, bool $teaserkeys = false, int $sortorder = 0): array
    {

        global $my_preview;     // TODO global ersetzen

        $rarr = array();

        if ((empty ($tags)) && (!$auchleere))
            return $rarr;

        $dat = date("Y-m-d H:i:s");


        if ($teaserkeys) {
            $keyname = 'teaserkeys_passive';
        } else {
            $keyname = 'keywords';
        }

        if (!empty($tags))
            $tagarra = explode(';', $tags);
        else
            $tagarra = array();

        $tagarr = array();

        foreach ($tagarra as $higg) {
            $hugg = trim($higg);
            if (!empty ($hugg))
                $tagarr [$hugg] = $hugg; // damit keine doppelten und leeren mehr
        }

        $sql = "SELECT * FROM " . self::ART_TABLE . " WHERE ";

        if ($nurnewsticker)
            $sql .= " newstickerjn='ja' AND ";

        $binding = array();
        if ($catid > 0) {
            $sql .= " categories_id=:catid AND";
            $binding['catid'] = $catid;
        }

        if (!is_null($language_id))
            if ($language_id > 0) {
                $sql .= ' language_id=:language_id  AND ';
                $binding['language_id'] = $language_id;
            }

        $sql .= " category_article='nein' AND archiv='nein' AND (aktivdat<=:dat1 OR aktivdat IS NULL) AND (expiredat>:dat2 OR expiredat IS NULL) AND ";
        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;

        $sql .= " (visible='list' or visible='all') ";

        if (empty ($subcla))
            $sql .= " AND (main_article_id=0 OR main_article_id=article_id ) ";
        else
            $sql .= " AND (objtype='teaser') ";

        if (!isset ($my_preview)) {
            $sql .= ' AND astatus = "aktiv"';

            if (Application::getApplication()->isStaging())
                $sql .= ' AND stagingstate<>"notinstage" ';
            else
                $sql .= ' AND stagingstate<>"stageonly" ';

        }

        if ((!empty ($tagarr)) || $auchleere) {
            $sql .= ' AND (';
            $ori = '';
            $i = 0;

            if (!empty ($tagarr))
                foreach ($tagarr as $tagele) {
                    $i++;
                    $tagfeld = 'tagele' . $i;

                    $sql .= $ori . ' ' . $keyname . ' like :' . $tagfeld;
                    $ori = ' OR ';

                    $binding[$tagfeld] = '%' . trim($tagele) . '%';
                }

            if ($auchleere)
                $sql .= $ori . ' ' . $keyname . "='' ";

            $sql .= ' ) ';
        }

        if ($sortorder == 0)
            $datsort = 'DESC';
        else
            $datsort = 'ASC';

        $sql .= ' ORDER BY sort_order,verfassdat ' . $datsort;

        $qart = $this->db->getlist($sql, $binding);

        if (empty($qart))
            return array();


        foreach ($qart as $art) {
            $art = $this->transformArticle($art);
            $rarr [] = $art;
        }

        return $rarr;
    }


    /**
     * Alle TAGS ausgeben, die in Artikeln verwendet werden
     *
     * @param int $catid
     * @param int $count
     * @param string $tag
     * @param string $tagclass
     * @return array
     */
    public function getTeaserBlogtags(int $catid, int $count = 10, string $tag = '', string $tagclass = 'article'): array
    {
        global $my_preview; // TODO global entfernen

        $rarr = array();

        if (empty ($tag))
            return $rarr;

        $dat = date("Y-m-d H:i:s");

        $binding = array();

        $sql = 'SELECT * FROM ' . self::ART_TABLE . ' a ' . 'JOIN ' . TAGGING_TABLE . ' t ON a.article_id=t.objid '
            . 'JOIN ' . TAGLIST_TABLE . ' l ON t.tagnameid=l.tagnameid ' . 'WHERE a.feed_enabled="yes" ' . 'AND l.objclass=:tagclass AND l.tagname=:tag';

        $binding['tagclass'] = $tagclass;
        $binding['tag'] = $tag;
        if ($catid > 0) {
            $sql .= ' AND a.categories_id=:catid';
            $binding['catid'] = $catid;
        }

        $sql .= " AND a.category_article='nein' AND  a.archiv='nein' AND  (a.aktivdat<=:dat1 OR a.aktivdat IS NULL) AND (a.expiredat>:dat2 OR a.expiredat IS NULL) AND ";
        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;

        $sql .= " (a.visible='list' or a.visible='all') ";

        $sql .= " AND (a.main_article_id=0 OR a.main_article_id=article_id ) ";

        if (!isset ($my_preview)) {

            $sql .= ' AND a.astatus = "aktiv"';

            if (Application::getApplication()->isStaging())
                $sql .= ' AND a.stagingstate<>"notinstage" ';
            else
                $sql .= ' AND a.stagingstate<>"stageonly" ';

        }

        if ($count > 0) {
            $sql .= " ORDER BY a.verfassdat DESC LIMIT :count";
            $binding['count'] = $count;
        } else
            $sql .= " ORDER BY a.verfassdat ";

        $qart = $this->db->getlist($sql, $binding);

        if (empty($qart))
            return array();

        foreach ($qart as $art) {
            $art = $this->transformArticle($art);
            $rarr [] = $art;
        }

        return $rarr;
    }


    /**
     * Teaser ausgeben, bei denen newstickerjn gesetzt ist, sonst wie get_teaser_taglist
     *
     * @param int $catid
     * @param string $tags
     * @param string $subcla
     * @param bool $auchleere
     * @return array
     */
    public function getTeaserNewslist(int $catid, string $tags, string $subcla = '', $auchleere = false): array
    {
        return $this->getTeaserTaglist($catid, $tags, $subcla, $auchleere, true);
    }

    /**
     * Artikel/Teaser eines Blog-Archiv-Zeitraumes Monat/Jahr ausgeben z.B. fuer RSS-Feed
     *
     * @param int $catid
     * @param int $year
     * @param int $month
     * @param int $feedid
     * @param bool $onlyfeed
     * @param bool $defaultfeed
     * @return int
     */
    public function getCountBloglist(int $catid, int $year = 0, int $month = 0, int $feedid = 0, bool $onlyfeed = false, bool $defaultfeed = true): int
    {

        global $my_preview;

        $dat = date("Y-m-d H:i:s");


        // Archiv-Funktion, nur Artikel eines Monats ...
        if (($year > 0) && ($month > 0)) {
            $ys = (string)$year;
            $ms = (string)$month;
            $sdat = $ys . '-' . $ms . '-01 00:00:00';
            $month = $month + 1;
            if ($month > 12) {
                $month = 1;
                $year = $year + 1;
            }

            $expdat = $year . '-' . $month . '-01 00:00:00';
        }

        if ($onlyfeed)
            $sql = "SELECT count(*) AS anz FROM " . self::ART_TABLE . " a JOIN " . FoldersGateway::FOLDER_TABLE . " c ON a.categories_id=c.categories_id WHERE a.feed_enabled='yes' AND ";
        else
            $sql = "SELECT count(*) AS anz FROM " . self::ART_TABLE . " a JOIN " . FoldersGateway::FOLDER_TABLE . " c ON a.categories_id=c.categories_id WHERE (a.newstickerjn='ja' OR a.feed_enabled='yes') AND ";

        $binding = array();
        if ($catid > 0) {
            $sql .= ' a.categories_id=:catid AND ';
            $binding['catid'] = $catid;
        }

        if ($feedid > 0) {
            $binding['feedid1'] = $feedid;
            $binding['feedid2'] = $feedid;
            if ($defaultfeed)
                $sql .= ' (a.feed_id =:feedid1 OR a.feed_id = 0) AND (c.feed_id =:feedid2 OR c.feed_id=0) AND';
            else
                $sql .= ' (a . feed_id =:feedid1 OR c . feed_id =:feedid2) AND';
        }

        // Archiv-Funktion, nur Artikel eines Monats ...
        if (!empty ($expdat) && (!empty($sdat))) {
            $sql .= " a.verfassdat>=:sdat AND ";
            $sql .= " a.verfassdat<:expdat AND ";

            $binding['sdat'] = $sdat;
            $binding['expdat'] = $expdat;
        }

        if (!$onlyfeed)
            $sql .= " a.category_article='nein' AND ";

        $sql .= " a.archiv='nein' AND (a.aktivdat<=:dat1 OR a.aktivdat IS NULL) AND  (a.expiredat>:dat2 OR a.expiredat IS NULL) ";

        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;

        if (!$onlyfeed) {
            $sql .= " AND (a.visible='list' or a.visible='all') ";
            $sql .= " AND (a.main_article_id=0 OR a.main_article_id=article_id ) ";

        }

        if (!isset ($my_preview))
            $sql .= " AND  a.astatus = 'aktiv' ";

        $ret = $this->db->get($sql, $binding);

        if (empty($ret))
            return 0;

        return $ret ['anz'];

    }

    /**
     *
     * Artikel/Teaser eines Blog-Archiv-Zeitraumes Monat/Jahr ausgeben z.B. fuer RSS-Feed
     *
     * @param int $catid
     * @param int $count
     * @param int $year
     * @param int $month
     * @param int $feedid
     * @param bool $onlyfeed
     * @param bool $defaultfeed
     * @param int $limitstart
     * @param int $limitende
     * @param bool $allbutindex
     * @return array
     */
    public function getTeaserBloglist(int $catid, int $count = 10, int $year = 0, int $month = 0, int $feedid = 0, bool $onlyfeed = false, bool $defaultfeed = true, int $limitstart = 0, int $limitende = 0, bool $allbutindex = false): array
    {

        global $my_preview;     // TODO global ersetzen

        $rarr = array();

        $dat = date("Y-m-d H:i:s");


        $sdat = '';
        // Archiv-Funktion, nur Artikel eines Monats ...
        if (($year > 0) && ($month > 0)) {
            $sdat = $year . '-' . $month . '-01 00:00:00';
            $month = $month + 1;
            if ($month > 12) {
                $month = 1;
                $year = $year + 1;
            }

            $expdat = $year . '-' . $month . '-01 00:00:00';
        }

        if ($allbutindex)
            $sql = "SELECT a.*,c.categories_id FROM " . self::ART_TABLE . " a JOIN " . FoldersGateway::FOLDER_TABLE . " c ON a.categories_id=c.categories_id WHERE ";
        else if ($onlyfeed)
            $sql = "SELECT a.*,c.categories_id FROM " . self::ART_TABLE . " a JOIN " . FoldersGateway::FOLDER_TABLE . " c ON a.categories_id=c.categories_id WHERE a.feed_enabled='yes' AND ";
        else
            $sql = "SELECT a.*,c.categories_id FROM " . self::ART_TABLE . " a JOIN " . FoldersGateway::FOLDER_TABLE . " c ON a.categories_id=c.categories_id WHERE (a.newstickerjn='ja' OR a.feed_enabled='yes') AND ";

        $binding = array();

        if ($catid > 0) {
            $sql .= ' a.categories_id=:catid AND';
            $binding['catid'] = $catid;
        }

        if ($feedid > 0) {
            $binding['feedid1'] = $feedid;
            $binding['feedid2'] = $feedid;

            if ($defaultfeed)
                $sql .= ' (a.feed_id =:feedid1 OR a.feed_id=0 OR a.feed_id IS NULL) AND (c.feed_id =:feedid2 OR c.feed_id=0) AND';
            else
                $sql .= ' (a.feed_id =:feedid1 OR c.feed_id =:feedid2 . ) AND';

        }

        // Archiv-Funktion, nur Artikel eines Monats ...
        if ((!empty ($expdat)) && (!empty ($sdat))) {
            $sql .= " a.verfassdat>=:sdat AND ";
            $binding['sdat'] = $sdat;
            $sql .= " a.verfassdat<:expdat AND ";
            $binding['expdat'] = $expdat;
        }

        if ((!$onlyfeed) || ($allbutindex))
            $sql .= " a.category_article='nein' AND ";

        $sql .= " a.archiv='nein' AND (a.aktivdat<=:dat1 OR a.aktivdat IS NULL) AND (a.expiredat>:dat2 OR a.expiredat IS NULL) ";
        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;


        if (!$onlyfeed) {
            $sql .= " AND (a.visible='list' or a.visible='all') ";
            $sql .= " AND (a.main_article_id IS NULL OR a.main_article_id=0 OR a.main_article_id=a.article_id ) ";
        }

        if (!isset ($my_preview))
            $sql .= " AND  a.astatus = 'aktiv' ";

        if ($count > 0) {
            $sql .= " ORDER BY a.verfassdat DESC LIMIT :count";
            $binding['count'] = $count;
        } else {
            $sql .= " ORDER BY a.verfassdat DESC ";

            if (($limitstart != 0) || ($limitende != 0)) {
                $sql .= ' LIMIT :limitstart,:limitende';
                $binding['limitstart'] = $limitstart;
                $binding['limitende'] = $limitende;
            }
        }

        $qart = $this->db->getlist($sql, $binding);

        if (empty($qart))
            return array();

        foreach ($qart as $art) {
            $art = $this->transformArticle($art);
            $rarr [] = $art;
        }

        return $rarr;
    }


    /**
     *  Die Monate ausgeben, in denen Artikel vorhanden sind
     *
     * @param int $catid
     * @param bool $onlyblog
     * @param bool $sortreverse
     * @return array
     */
    public function getBlogDateArchivlist(int $catid, bool $onlyblog = true, bool $sortreverse = false): array
    {

        global $my_preview;

        if ($catid < 1)
            return array();

        $rarr = array();

        $dat = date("Y-m-d H:i:s");

        $sql = "SELECT DISTINCT month(verfassdat) as monat, year(verfassdat) as jahr FROM " . self::ART_TABLE . " WHERE ";

        $binding = array();

        $sql .= ' categories_id=:catid AND';
        $binding['catid'] = $catid;

        if ($onlyblog)
            $sql .= " feed_enabled='yes' AND ";

        $sql .= " category_article='nein' AND " . " (aktivdat<=:dat1 OR aktivdat IS NULL) AND  (expiredat>:dat2 OR expiredat IS NULL) AND ";
        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;


        $sql .= " (visible='list' or visible='all') ";

        $sql .= " AND (main_article_id=0 OR main_article_id=article_id ) ";

        if (!isset ($my_preview))
            $sql .= " AND  astatus = 'aktiv' ";

        if ($sortreverse)
            $sql .= " ORDER BY jahr DESC, monat DESC";
        else
            $sql .= " ORDER BY jahr, monat ";

        $qart = $this->db->getlist($sql, $binding);


        if (empty($qart))
            return array();

        foreach ($qart as $art) {
            $erg ['name'] = meinBlogArchivDatum($art ['jahr'], $art ['monat']);//Todo liegt momentan in functions
            $erg ['url'] = $art ['jahr'] . '-' . $art ['monat'];
            $rarr [] = $erg;
        }

        return $rarr;
    }


    /**
     *Artikel/Teaser einer einfachen Suche zurückgeben
     *
     * @param int $catid
     * @param string $suchwort
     * @param int $limit
     * @param string $sorti
     * @param int $languageid
     * @param bool $withall
     * @return array
     */
    public function getTeaserSearchlist(int $catid, string $suchwort, int $limit = 0, string $sorti = 'datedesc', int $languageid = 0, bool $withall = false): array
    {
        global $my_preview;

        if (empty ($suchwort))
            return array();

        $rarr = array();

        $dat = date("Y-m-d");

        $sql = 'SELECT c.categories_id,c.categories_name,a.* FROM ' . self::ART_TABLE . ' a JOIN ' . FoldersGateway::FOLDER_TABLE . ' c ON a.categories_id = c.categories_id WHERE ';

        $binding = array();
        $and = '';

        if ($catid > 0) {
            $sql .= $and . 'a.categories_id=:catid ';
            $binding['catid'] = $catid;
            $and = 'AND ';
        }

        if (!$withall) {
            $sql .= $and . "a.archiv='nein' ";
            $and = 'AND ';
        }

        $sql .= $and . "(a.aktivdat<=:dat1 OR a.aktivdat IS NULL) AND (a.expiredat>:dat2 OR a.expiredat IS NULL) ";

        $binding['dat1'] = $dat;
        $binding['dat2'] = $dat;

        if (!$withall)
            $sql .= "AND (a.visible<>'none') ";

        if (!isset ($my_preview)) {
            $sql .= 'AND a.astatus = "aktiv" AND c.active = "yes" ';

            if (Application::getApplication()->isStaging())
                $sql .= 'AND a.stagingstate<>"notinstage" ';
            else
                $sql .= 'AND a.stagingstate<>"stageonly" ';

        }

        if ($languageid > 0) {
            $sql .= 'AND a.language_id=:languageid ';
            $binding['languageid'] = $languageid;
        }

        $sql .= 'AND (a.langtext LIKE :suchwort1 OR a.titel2 LIKE :suchwort2 OR a.kurztext LIKE :suchwort3) ';
        $binding['suchwort1'] = '%' . $suchwort . '%';
        $binding['suchwort2'] = '%' . $suchwort . '%';
        $binding['suchwort3'] = '%' . $suchwort . '%';

        switch ($sorti) {
            case 'alphaasc' :
                $qsort = 'a.sort_order, a.titel2 ASC';
                break;
            case 'alphadesc' :
                $qsort = 'a.sort_order, a.titel2 DESC';
                break;
            case 'dateasc' :
                $qsort = 'a.sort_order, a.verfassdat ASC';
                break;
            case 'datedesc' :
            default :
                $qsort = 'a.sort_order, a.verfassdat DESC';
                break;
        }

        $sql .= 'ORDER BY ' . $qsort;

        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
            $binding['limit'] = $limit;
        }

        $qart = $this->db->getlist($sql, $binding);

        if (empty($qart))
            return array();

        foreach ($qart as $art) {
            $art = $this->transformArticle($art);
            $rarr [] = $art;
        }

        return $rarr;
    }


    /**
     * Unterartikel eines Haupt-Artikels ausgeben
     *
     * @param int $main_article_id
     * @param int $maxcount
     * @return array
     */
    public function getArticleMultilist(int $main_article_id, int $maxcount = 30): array
    {

        $rarr = array();

        if ($main_article_id < 1)
            return $rarr;

        $count = 1;

        $next_id = $main_article_id;

        while (($next_id > 0) && ($count < $maxcount)) {
            $aobj = $this->getArticle($next_id);
            if (empty ($aobj))
                $next_id = 0;
            else {
                $aobj ['counternum'] = $count;
                $next_id = $aobj ['next_article_id'];
                $rarr [] = $aobj;
            }
            $count = $count + 1;
        }

        return $rarr;
    }

    /**
     * Kommentarfunktion. Anzahl aktualisieren
     *
     * @param int $article_id
     * @return void
     */
    public function updateCommentCount(int $article_id): void
    {
        if ($article_id < 1)
            return;

        $binding = array();
        $binding['article_id'] = $article_id;
        $sql = "SELECT COUNT(*) as anz from " . COMMENTS_TABLE . ' WHERE related_objid=:article_id  AND comment_type="comment" AND (approved="yes" OR approved="reserved" OR approved="yesnofollow") ';
        $row = $this->db->get($sql, $binding);

        if (empty($row))
            return;

        $komm = $row ['anz'];


        $sql = "SELECT COUNT(*) as anz from " . COMMENTS_TABLE . ' WHERE related_objid=:article_id AND comment_type="trackback" AND (approved="yes" OR approved="reserved" OR approved="yesnofollow") ';
        $row = $this->db->get($sql, $binding);

        if (empty($row))
            return;

        $data = array();
        $data['article_id'] = $article_id;
        $data['count_comments'] = $komm;
        $data['count_trackbacks'] = $row ['anz'];
        $this->db->put(self::ART_TABLE, $data, array('article_id'), false);

    }


    /**
     * Alle Kommentare eines Artikels holen
     *
     * @param $artid
     * @param int $anzahl
     * @param string $type
     * @return array
     */
    public function getArticleComments(int $artid, int $anzahl = 0, string $type = ''): array
    {
        $rarr = array();
        $binding = array();

        if (empty ($type))
            $stype = '';
        else {
            $stype = ' comment_type=:type AND ';
            $binding['type'] = $type;
        }

        if ($artid == 0) {
            $sql = "SELECT * from " . COMMENTS_TABLE . ' WHERE ' . $stype . '(approved="yes" OR approved="reserved" OR approved="yesnofollow") ORDER BY create_date ';
        } else {
            $sql = "SELECT * from " . COMMENTS_TABLE . ' WHERE ' . $stype . 'related_objid=:artid  AND (approved="yes" OR approved="yesnofollow" OR approved="reserved") ORDER BY create_date ';
            $binding['artid'] = $artid;
        }

        if ($anzahl > 0) {
            $sql .= ' DESC LIMIT :anzahl';
            $binding['anzahl'] = $anzahl;
        }

        $qart = $this->db->getlist($sql, $binding);

        if (empty($qart))
            return array();

        $objid = 0;
        $objurl = '';

        foreach ($qart as $comm) {
            $comm ['comment_text'] = \Banzai\Renderers\RenderersGateway::RenderText($comm ['comment_text'], $comm ['comment_text_type']);//Todo liegt momentan in render.php
            $comm ['comment_short'] = limit_string(strip_tags(str_replace('<br />', ' ', $comm ['comment_text'])), 70);
            //Todo limit_string liegt momentan in functions.php

            if ($comm ['approved'] == 'yes')
                $nofo = '';
            else
                $nofo = ' rel="nofollow"';

            if (empty ($comm ['author_url']))
                $comm ['author_linkname'] = $comm ['author_name'];
            else
                $comm ['author_linkname'] = '<a href="' . $comm ['author_url'] . '" title="' . $comm ['author_name'] . '" ' . $nofo . '>' . $comm ['author_name'] . '</a>';

            if ($comm ['related_objid'] != $objid) {
                $objid = $comm ['related_objid'];
                $objurl = $this->getArtURLFromID($objid);
            }

            if (!empty ($objurl))
                $comm ['fullurl'] = $objurl . '#comment-' . $comm ['id'];

            $rarr [] = $comm;
        }

        return $rarr;
    }


    /**
     * Kommentar zu einem Artikel hinzufügen
     *
     * @param int $article_id
     * @param string $author
     * @param string $email
     * @param string $url
     * @param string $comment
     * @param string $sendmail
     * @return void;
     */
    public function addComment(int $article_id, string $author, string $email, string $url, string $comment, string $sendmail): void
    {
        $userobj = Application::get('user')->getAll();
        $request = Application::get('request');

        // Jetzt den Trackback verarbeiten

        if (!empty ($url))
            if (substr($url, 0, 4) != 'http')
                $url = 'http://' . $url;

        $data = array();
        if ($userobj ['user_id'] > 0) {
            $data['approved'] = 'yes';
            $data['author_id'] = $userobj ['user_id'];
            if (empty ($author))
                $author = $userobj ['display_name'];
        } else
            $data['approved'] = 'reserved';

        $data['related_objid'] = $article_id;
        $data['author_name'] = $author;
        $data['author_email'] = $email;
        $data['newcomment_email'] = $sendmail;
        $data['author_url'] = $url;
        $data['comment_text'] = strip_tags($comment);
        $data['author_ip'] = $request->getClientIP();
        $data['author_agent'] = strip_tags($_SERVER ['HTTP_USER_AGENT']);
        $data['comment_text_type'] = 'plain';
        $data['comment_type'] = 'comment';
        $data['create_date'] = $this->db->timestamp();
        $data['confirm_needed'] = 'no';

        $comment_id = $this->db->add(COMMENTS_TABLE, $data);

        $this->updateCommentCount($article_id);

        send_commentmail($article_id, $comment_id, $author, $email, $url, $comment);//Todo liegt momentan in functions
    }


    /**
     * ermittelt die URL aufgrund des APP-pathnamens des Artikels
     * damit koennen besondere Seiten miteinander verlinkt werden
     *
     * @param string $pathname
     * @param int $roleid
     * @param bool $withdefault
     * @return string
     */
    public function getURLFromAppPathname(string $pathname = '', int $roleid = -1, bool $withdefault = true): string
    {
        global $userobj; // TODO globale Variablen eliminieren

        if ($roleid == -1)
            $roleid = $userobj['group_id'];

        $sql = 'SELECT fullurl FROM ' . self::ART_TABLE . ' WHERE app_route_role_id=? AND app_route_pathname=?';
        $art = $this->db->get($sql, array($roleid, $pathname));

        if (empty($art)) {
            if ($roleid == 0 || $withdefault == false)
                return '';

            $sql = 'SELECT fullurl FROM ' . self::ART_TABLE . ' WHERE app_route_role_id=0 AND app_route_pathname=?';
            $art = $this->db->get($sql, array($pathname));

        }

        if (empty($art))
            return '';

        return $art['fullurl'];

    }

    public function getBlockArticles(): array
    {
        $sql = 'SELECT article_id,titel2 FROM ' . self::ART_TABLE . ' WHERE withblocks="yes" ORDER BY titel2';
        return $this->db->getlist($sql, null, 'article_id', 'titel2');
    }


}

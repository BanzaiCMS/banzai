<?php
declare(strict_types=1);

namespace Banzai\Http\Routing;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Flux\Config\Config;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Files\FilesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Domain\Products\ProductsGateway;
use Banzai\Domain\Videos\VideosGateway;


class DatabaseRouterGateway
{

    public function __construct(
        protected DatabaseInterface      $db,
        protected LoggerInterface        $logger,
        protected Config                 $params,
        protected RouteProviderInterface $router,
        protected FoldersGateway         $folders
    )
    {

    }

    public function rebuildAllPaths(): void
    {
        $this->router->deleteAllRoutes();
        $this->updateFolders(0, '', 0, 20);
    }

    public function updateAllItems(int $folderid, string $folderpath, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {
        $this->updateArticles($folderid, $folderpath, Folderispublished: $Folderispublished, FolderPermCode: $FolderPermCode);
        $this->updateProducts($folderid, $folderpath, Folderispublished: $Folderispublished, FolderPermCode: $FolderPermCode);
        $this->updateFiles($folderid, $folderpath, Folderispublished: $Folderispublished, FolderPermCode: $FolderPermCode);
        $this->updatePictures($folderid, $folderpath, Folderispublished: $Folderispublished, FolderPermCode: $FolderPermCode);
        $this->updateVideos($folderid, $folderpath, Folderispublished: $Folderispublished, FolderPermCode: $FolderPermCode);

    }

    public function getNotFoundType(?string $autoredirect): int
    {
        // 'showindex','show404','redir301','redir302'
        return match ($autoredirect) {
            'redir302' => RouteInterface::NotFoundRedirect302,
            'showindex' => RouteInterface::NotfoundCallFolder,
            'show404' => RouteInterface::NotFoundShow404,
            default => RouteInterface::NotFoundRedirect301
        };

    }

    function updateFolderIndexElement(int $folderid): void
    {

        $route = $this->db->get('SELECT routeid FROM ' . DatabaseRouter::ROUTES_PATH_TABLE . ' WHERE itemid=? AND itemtype=?', array($folderid, 'folder'));

        if (empty($route))
            return;

        $art = $this->db->get('SELECT article_id FROM ' . ArticlesGateway::ART_TABLE . ' WHERE categories_id=? AND category_article=?', array($folderid, 'ja'));

        if (empty($art))
            $route['indexitemid'] = 0;
        else
            $route['indexitemid'] = $art['article_id'];

        $this->db->put(DatabaseRouter::ROUTES_PATH_TABLE, $route, array('routeid'), false);

    }

    public function updateFolders(int $parentid = 0, string $parenturl = '', int $tiefe = 0, int $maxtiefe = 0, bool $isParentPublished = true, ?string $ParentPermCode = null): void
    {

        $tiefe++;

        if (($tiefe > $maxtiefe) && ($maxtiefe > 0))
            return;

        $liste = $this->db->getlist('SELECT categories_id,categories_url,fullurl,visible,navtitle,navstyle,date_added,sortorder_elements,sort_order,active,access_perm_code,naviarea,controllerclassname,autoredirect FROM ' .
            FoldersGateway::FOLDER_TABLE . ' WHERE parent_id=?', array($parentid));

        foreach ($liste as $cat) {

            $fullurl = $parenturl . $cat['categories_url'];

            if (empty($fullurl))
                $fullurl = '/';

            if (!str_starts_with($fullurl, '/'))
                $fullurl = '/' . $fullurl;

            if (!str_ends_with($fullurl, '/'))
                $fullurl .= '/';

            if ($fullurl != $cat['fullurl']) {
                $data = array('categories_id' => $cat['categories_id'], 'fullurl' => $fullurl);
                $this->db->put(FoldersGateway::FOLDER_TABLE, $data, array('categories_id'), true);
            }

            // if parent is not published, all childs are not published
            if (!$isParentPublished)
                $cat['active'] = 'no';

            $isPublished = $cat['active'] == 'yes';

            // use Permcode from parent only if we do not have set our own permcode in this child
            if (empty($cat['access_perm_code']) && (!empty($ParentPermCode)))
                $cat['access_perm_code'] = $ParentPermCode;

            // 'all', 'menu', 'list', 'none')
            $innav = match ($cat['visible']) {
                'all', 'menu' => true,
                default => false
            };

            $inteaser = match ($cat['visible']) {
                'all', 'list' => true,
                default => false
            };

            $notfoundtype = $this->getNotFoundType($cat['autoredirect']);

            $sorttype = 'chrono';
            $sortdirection = 'desc';

            if (!empty($cat['sortorder_elements'])) {
                $sorttype = match ($cat['sortorder_elements']) {
                    'alphaasc', 'alphadesc' => 'alpha',
                    default => 'chrono'
                };

                $sortdirection = match ($cat['sortorder_elements']) {
                    'alphaasc', 'dateasc' => 'asc',
                    default => 'desc'
                };

            }

            $art = $this->db->get('SELECT article_id FROM ' . ArticlesGateway::ART_TABLE . ' WHERE categories_id=? AND category_article=?', array($cat['categories_id'], 'ja'));
            if (empty($art))
                $indexid = 0;
            else
                $indexid = $art['article_id'];


            // set/update our own path entry
            $this->router->createRouteByItem('folder', $cat['categories_id'])
                ->setParentID($parentid)
                ->setFullPath($fullurl)
                ->setItemPath($cat['categories_url'])
                ->setIndexID($indexid)
                ->setisPublished($isPublished)
                ->setPermissionCode($cat['access_perm_code'])
                ->setNavTitle($cat['navtitle'])
                ->setSortValue($cat['sort_order'])
                ->setSortType($sorttype)
                ->setSortOrder($sortdirection)
                ->setNavDate($cat['date_added'])
                ->setNavID($cat['naviarea'])
                ->setNavStyle($cat['navstyle'])
                ->setVisibleinNav($innav)
                ->setVisibleinTeaser($inteaser)
                ->setPageController($cat['controllerclassname'])
                ->setNotFoundType($notfoundtype)
                ->save();

            // update all our items (no sub-folders)
            $this->updateAllItems($cat['categories_id'], $fullurl, Folderispublished: $isPublished, FolderPermCode: $cat['access_perm_code']);

            // update all our sub-folders
            $this->updateFolders($cat['categories_id'], $fullurl, $tiefe, $maxtiefe, $isPublished, $cat['access_perm_code']);

        }
    }

    public function updateArticles(int $folderid = 0, ?string $folderurl = null, int $articleid = 0, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {

        $sql = 'SELECT * FROM ' . ArticlesGateway::ART_TABLE . ' WHERE ';

        if ($articleid > 0)
            $liste = $this->db->getlist($sql . ' article_id=?', array($articleid));
        else
            $liste = $this->db->getlist($sql . ' categories_id=?', array($folderid));

        $withchangelog = ($articleid > 0);

        foreach ($liste as $art) {

            $path = $art['url'];
            if ((!empty($path)) && (!empty($art['extension'])))
                $path .= '.' . $art['extension'];
            $fullurl = $folderurl . $path;

            if ($fullurl != $art['fullurl']) {
                $data = array('article_id' => $art['article_id'], 'fullurl' => $fullurl);
                $this->db->put(ArticlesGateway::ART_TABLE, $data, array('article_id'), $withchangelog);
            }

            // astatus:   'geloescht','inarbeit','privat','vorlage','aktiv'

            // kein Artikel/Blogpost d.h. immer Teaser
            // wir löschen den eintrag, falls vorhanden
            if (($art['objtype'] != 'art') && ($art['objtype'] != 'blogpost')) {
                $this->router->delRouteByItem('teaser', $art['article_id']);
                continue;
            }

            // wenn status gelöscht, dann immer den eintrag löschen, falls vorhanden
            if ($art['astatus'] == 'geloescht') {
                $this->router->delRouteByItem('article', $art['article_id']);
                continue;
            }

            if (empty($art['url'])) {       // ohne url keinen route-eintrag, dann immer den eintrag löschen, falls vorhanden
                $this->router->delRouteByItem('article', $art['article_id']);
                continue;
            }

            if (empty($art['app_route_pathname'])) {
                $routename = null;
            } else {
                $routename = $art['app_route_pathname'];
            }

            if ($art['visible'] == 'none')
                $navtitle = null;
            else
                $navtitle = $art['navititel'];

            if ($Folderispublished)
                $ispublished = ($art['astatus'] == 'aktiv');
            else
                $ispublished = false;

            if (empty($art['access_perm_code']))
                $permcode = $FolderPermCode;
            else
                $permcode = $art['access_perm_code'];

            // 'all', 'menu', 'list', 'none')
            $innav = match ($art['visible']) {
                'all', 'menu' => true,
                default => false
            };

            $inteaser = match ($art['visible']) {
                'all', 'list' => true,
                default => false
            };

            $this->router->createRouteByItem('article', $art['article_id'])
                ->setParentID($art['categories_id'])
                ->setFullpath($fullurl)
                ->setItemPath($path)
                ->setisPublished($ispublished)
                ->setPermissionCode($permcode)
                ->setNavTitle($navtitle)
                ->setSortValue($art['sort_order'])
                ->setNavDate($art['verfassdat'])
                ->setNavID($art['naviarea'])
                ->setNavStyle($art['navstyle'])
                ->setVisibleinNav($innav)
                ->setVisibleinTeaser($inteaser)
                ->setRouteName($routename)
                ->setPageController($art['contentclass'])
                ->setActiveFrom($art['aktivdat'])
                ->setActiveUntil($art['expiredat'])
                ->save();

        }

    }

    public function updateProducts(int $folderid = 0, ?string $folderurl = null, int $productid = 0, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {

        $sql = 'SELECT * FROM ' . ProductsGateway::PRODUCTS_TABLE . ' WHERE ';
        if ($productid > 0)
            $liste = $this->db->getlist($sql . ' products_id=?', array($productid));
        else
            $liste = $this->db->getlist($sql . ' categories_id=?', array($folderid));

        foreach ($liste as $art) {

            $path = $art['products_url'];
            if ((!empty($path)) && (!empty($art['extension'])))
                $path .= '.' . $art['extension'];
            $fullurl = $folderurl . $path;

            if ($fullurl != $art['fullurl']) {
                $data = array('products_id' => $art['products_id'], 'fullurl' => $fullurl);
                $this->db->put(ProductsGateway::PRODUCTS_TABLE, $data, array('products_id'), false);
            }

            if ($art['visible'] == 'none')
                $navtitle = null;
            else
                $navtitle = $art['navtitle'];

            if ($Folderispublished)
                $ispublished = ($art['active'] == 'yes');
            else
                $ispublished = false;

            // 'all', 'menu', 'list', 'none')
            $innav = match ($art['visible']) {
                'all', 'menu' => true,
                default => false
            };

            $inteaser = match ($art['visible']) {
                'all', 'list' => true,
                default => false
            };

            // Products don't have their own permission code, they always use the code from folder
            $permcode = $FolderPermCode;

            $this->router->createRouteByItem('product', $art['products_id'])
                ->setParentID($art['categories_id'])
                ->setFullPath($fullurl)
                ->setItemPath($path)
                ->setisPublished($ispublished)
                ->setPermissionCode($permcode)
                ->setNavTitle($navtitle)
                ->setSortValue($art['sort_order'])
                ->setNavDate($art['products_date_added'])
                ->setVisibleinNav($innav)
                ->setVisibleinTeaser($inteaser)
                ->save();

        }

    }


    public function updateFiles(int $folderid = 0, ?string $folderurl = null, int $articleid = 0, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {

        $sql = 'SELECT id,categories_id,url,active,fullurl,storagepath FROM ' . FilesGateway::FILE_TABLE . ' WHERE ';
        if ($articleid > 0)
            $liste = $this->db->getlist($sql . ' id=?', array($articleid));
        else
            $liste = $this->db->getlist($sql . ' categories_id=?', array($folderid));

        $storagepath = $this->params->get('path.storage.file');

        foreach ($liste as $art) {

            $path = $art['url'];
            $fullurl = $folderurl . $path;

            if ($fullurl != $art['fullurl']) {
                $data = array('id' => $art['id'], 'fullurl' => $fullurl);
                $this->db->put(FilesGateway::FILE_TABLE, $data, array('id'), false);
            }

            if ($Folderispublished)
                $ispublished = ($art['active'] == 'yes');
            else
                $ispublished = false;

            $etag = hash_file('sha256', $storagepath . $art['storagepath'], false);
            if ($etag === false)
                $etag = null;

            $this->router->createRouteByItem('file', $art['id'])
                ->setParentID($art['categories_id'])
                ->setFullPath($fullurl)
                ->setItemPath($path)
                ->setisPublished($ispublished)
                ->setPermissionCode($FolderPermCode)        // we do not have our own permcode
                ->setEtag($etag)
                ->setVisibleinNav(false)
                ->save();
        }

    }

    function updatePictures(int $folderid = 0, ?string $folderurl = null, int $articleid = 0, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {

        $sql = 'SELECT id,categories_id,url,aktiv,fullurl,storagepath FROM ' . PicturesGateway::PIC_TABLE . ' WHERE ';
        if ($articleid > 0)
            $liste = $this->db->getlist($sql . ' id=?', array($articleid));
        else
            $liste = $this->db->getlist($sql . ' categories_id=?', array($folderid));

        $storagepath = $this->params->get('path.storage.image');

        foreach ($liste as $art) {

            $path = $art['url'];
            $fullurl = $folderurl . $path;

            if ($fullurl != $art['fullurl']) {
                $data = array('id' => $art['id'], 'fullurl' => $fullurl);
                $this->db->put(PicturesGateway::PIC_TABLE, $data, array('id'), false);
            }

            if ($Folderispublished)
                $ispublished = ($art['aktiv'] == 'ja');
            else
                $ispublished = false;

            $etag = hash_file('sha256', $storagepath . $art['storagepath'], false);

            if ($etag === false)
                $etag = null;

            $this->router->createRouteByItem('picture', $art['id'])
                ->setParentID($art['categories_id'])
                ->setFullPath($fullurl)
                ->setItemPath($path)
                ->setisPublished($ispublished)
                ->setPermissionCode($FolderPermCode)    // we do not have our own permcode
                ->setEtag($etag)
                ->setVisibleinNav(false)
                ->save();
        }
    }

    public function updateVideos(int $folderid = 0, ?string $folderurl = null, int $articleid = 0, bool $Folderispublished = true, ?string $FolderPermCode = null): void
    {

        $storagepath = $this->params->get('path.storage.video');

        $sql = 'SELECT id,categories_id,url,fullurl,active,localfilename FROM ' . VideosGateway::VIDEO_TABLE . ' WHERE ';
        if ($articleid > 0)
            $liste = $this->db->getlist($sql . ' id=?', array($articleid));
        else
            $liste = $this->db->getlist($sql . ' categories_id=?', array($folderid));

        foreach ($liste as $art) {

            $path = $art['url'];
            $fullurl = $folderurl . $path;

            if ($fullurl != $art['fullurl']) {
                $data = array('id' => $art['id'], 'fullurl' => $fullurl);
                $this->db->put(VideosGateway::VIDEO_TABLE, $data, array('id'), false);
            }

            if ($Folderispublished)
                $ispublished = ($art['active'] == 'yes');
            else
                $ispublished = false;

            $etag = hash_file('sha256', $storagepath . $art['localfilename'], false);
            if ($etag === false)
                $etag = null;

            $this->router->createRouteByItem('video', $art['id'])
                ->setParentID($art['categories_id'])
                ->setFullPath($fullurl)
                ->setItemPath($path)
                ->setisPublished($ispublished)
                ->setPermissionCode($FolderPermCode)        // we do not have our own permcode
                ->setEtag($etag)
                ->setVisibleinNav(false)
                ->save();
        }

    }
}

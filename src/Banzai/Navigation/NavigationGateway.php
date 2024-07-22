<?php
declare(strict_types=1);

namespace Banzai\Navigation;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Users\User;
use Banzai\Http\Routing\DatabaseRouter;
use Banzai\Http\Routing\RouteInterface;
use Banzai\Http\Routing\RouteProviderInterface;


class NavigationGateway implements NavigationInterface
{
    const NAVIAREA_TABLE = 'naviareas';
    const TEASERAREA_TABLE = 'teaserareas';

    const NAVENTRIES_TABLE = 'naventries';

    public function __construct(protected DatabaseInterface      $db,
                                protected LoggerInterface        $logger,
                                protected User                   $user,
                                protected ArticlesGateway        $ArticlesGateway,
                                protected RouteProviderInterface $router)
    {

    }

    protected function getAllNaviAreaParams(): array
    {
        return $this->db->getlist('SELECT * FROM ' . self::NAVIAREA_TABLE . ' WHERE code<>""');
    }

    protected function getAllTeaserAreaParams(): array
    {
        return $this->db->getlist('SELECT * FROM ' . self::TEASERAREA_TABLE . ' WHERE code<>""');
    }

    protected function isinPath(string $pagepath, string $elementpath): bool
    {
        return strncmp($pagepath, $elementpath, strlen($elementpath)) == 0;
    }

    protected function isPathIdentical(string $pagepath, string $elementpath): bool
    {
        return strcmp($pagepath, $elementpath) == 0;
    }

    protected function flattenList(array $tree, array $result = array()): array
    {
        foreach ($tree as $entry) {
            if (empty($entry['children'])) {
                $result[] = $entry;
            } else {
                $sublist = $entry['children'];
                unset($entry['children']);
                $result[] = $entry;
                $result = $this->flattenList($sublist, $result);
            }
        }
        return $result;
    }

    protected function createBreadcrumbs(RouteInterface $route): array
    {
        $ret = array();

        $routeid = $route->getID();

        $route = $this->router->getRouteAsArrayByID($routeid);
        if (empty($route))
            return array();

        $ret[] = $route;

        $parentid = $route['parentid'];

        while ($parentid > 0) {

            $route = $this->router->getRouteAsArrayByItem('folder', $parentid);
            if (empty($route)) {
                $parentid = 0;
            } else {
                $ret[] = $route;
                $parentid = $route['parentid'];
            }
        }

        return array_reverse($ret);

    }

    protected function TreeBuilderFactory(?string $Classname): TreeBuilderInterface
    {
        if (empty($Classname))
            $Classname = TreeBuilderFromDatabaseRoutes::class;      // IMPORTANT, don't shorten absolute path

        return $Classname::create(Application::getContainer());
    }

    public function createNavigation(int $PageFolderID, RouteInterface $route, bool $OnlyPublished = true): array
    {

        $ret = array();
        $params = $this->getAllNaviAreaParams();
        if (empty($params))
            return array();

        $TreeBuilder = null;
        $TreeBuilderClassname = '';

        $PagePath = $route->getPath();

        foreach ($params as $navi) {
            if ($navi['rootfolderid'] == 0) {
                $rootfolderid = $PageFolderID;
            } else {
                $rootfolderid = $navi['rootfolderid'];
            }

            // only create new TreeBuilder if classname has changed or has is not created yet
            if (!(isset($TreeBuilder)) || ($TreeBuilderClassname <> $navi['TreeBuilderClassName'])) {
                $TreeBuilder = $this->TreeBuilderFactory($navi['TreeBuilderClassName']);
                $TreeBuilderClassname = $navi['TreeBuilderClassName'];
            }

            $rootfolder = $TreeBuilder->getRootEntry($navi, $rootfolderid);

            if (empty($rootfolder)) {
                $this->logger->error('no route for root-folder found.', array('folderid' => $rootfolderid, 'classname' => $TreeBuilderClassname));
                continue;
            }

            $prependParent = ($navi['prependparentfolder'] == 'yes');

            // to have this field in case we prepend the root-folder to the list

            if ($this->isinPath($PagePath, $rootfolder['fullpath']))
                $rootfolder['inpath'] = true;

            if ($this->isPathIdentical($PagePath, $rootfolder['fullpath']))
                $rootfolder['selected'] = true;

            $ret[$navi['code']] = $TreeBuilder->BuildTree($navi, $PageFolderID, $PagePath, $rootfolder, OnlyPublished: $OnlyPublished, prependParent: $prependParent);

            if ($navi['flatlist'] == 'yes') {
                $ret[$navi['code']] = $this->flattenList($ret[$navi['code']]);
            }

            if (!empty($navi['breadcrumbs']))
                $ret[$navi['breadcrumbs']] = $this->createBreadcrumbs($route);

        }

        return $ret;

    }

    public function createTeaser(int $PageFolderID, string $PagePath, bool $OnlyPublished = true): array
    {

        $ret = array();
        $params = $this->getAllTeaserAreaParams();
        if (empty($params))
            return array();

        foreach ($params as $navi) {
            if ($navi['folderid'] == 0) {
                $folderid = $PageFolderID;
            } else {
                $folderid = $navi['folderid'];
            }

            $folder = $this->router->getRouteAsArrayByItem('folder', $folderid);
            if (empty($folder)) {
                $this->logger->error('no route for root-folder found.', array('folderid' => $folderid));
                continue;
            }

            // to have this field in case we prepend the root-folder to the list
            $folder['depth'] = 0;

            if ($this->isinPath($PagePath, $folder['fullpath']))
                $folder['inpath'] = true;
            if ($this->isPathIdentical($PagePath, $folder['fullpath']))
                $folder['selected'] = true;

            $ret[$navi['code']] = $this->createTeaserList($navi, $folder, OnlyPublished: $OnlyPublished);


        }

        return $ret;

    }

    protected function createTeaserList(array $NavParams, array $ParentFolder, bool $OnlyPublished = true): array
    {
        $ret = array();

        if ($NavParams['prependparentfolder'] == 'yes') {
            $ret[] = $ParentFolder;
        }

        $bind = array('parentid' => $ParentFolder['itemid']);
        $sql = 'SELECT * FROM ' . DatabaseRouter::ROUTES_PATH_TABLE . ' WHERE parentid=:parentid AND navtitle is not null';

        if ($OnlyPublished)
            $sql .= ' AND published="yes"';

        $sql .= ' AND inteaser="yes"';

        $sql .= ' AND itemtype="article"';  // teasers can only be articles at the moment

        $sql .= ' AND (activefrom<=:adat OR activefrom IS NULL) AND (activeuntil>:edat OR activeuntil is null)';
        $dat = $this->db->timestamp();
        $bind['adat'] = $dat;
        $bind['edat'] = $dat;

        $sql .= match ($ParentFolder['sorttyp']) {
            'chrono' => ' ORDER BY  sortvalue,navdate',
            default => ' ORDER BY sortvalue,navtitle'
        };

        $sql .= ' ' . $ParentFolder['sortdirection'];

        $list = $this->db->getlist($sql, $bind);

        foreach ($list as $entry) {

            $art = $this->ArticlesGateway->getArticle($entry['itemid']);
            $art = $this->ArticlesGateway->transformArticleToShow($art);

            $entry['title'] = $art['titel2'];
            $entry['content'] = $art['kurztext'];
            $entry['item'] = $art;

            $ret[] = $entry;
        }

        return $ret;
    }

    public function createNavigationwithParams(array $NavParam, int $PageFolderID, string $PagePath, bool $OnlyPublished = true, bool $OnlyNavElements = true): array
    {
        if (empty($NavParam))
            return array();

        if ($NavParam['rootfolderid'] == 0) {
            $rootfolderid = $PageFolderID;
        } else {
            $rootfolderid = $NavParam['rootfolderid'];
        }

        $TreeBuilder = $this->TreeBuilderFactory($NavParam['TreeBuilderClassName']);

        $rootfolder = $this->router->getRouteAsArrayByItem('folder', $rootfolderid);
        if (empty($rootfolder)) {
            $this->logger->error('no route for root-folder found.', array('folderid' => $rootfolderid));
            return array();
        }

        $prependParent = ($NavParam['prependparentfolder'] == 'yes');

        // to have this field in case we prepend the root-folder to the list
        $rootfolder['depth'] = 0;
        if ($this->isinPath($PagePath, $rootfolder['fullpath']))
            $rootfolder['inpath'] = true;
        if ($this->isPathIdentical($PagePath, $rootfolder['fullpath']))
            $rootfolder['selected'] = true;

        $tree = $TreeBuilder->BuildTree($NavParam, $PageFolderID, $PagePath, $rootfolder, OnlyPublished: $OnlyPublished, prependParent: $prependParent, OnlyNavElements: $OnlyNavElements);

        if ($NavParam['flatlist'] == 'yes') {
            $tree = $this->flattenList($tree);
        }

        return $tree;

    }

}

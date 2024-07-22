<?php
declare(strict_types=1);

namespace Banzai\Navigation;

use Flux\Container\ContainerInterface;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Users\User;
use Banzai\Http\Routing\DatabaseRouter;
use Banzai\Http\Routing\RouteProviderInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;


class TreeBuilderFromDatabaseRoutes implements TreeBuilderInterface
{

    public function __construct(protected DatabaseInterface      $db,
                                protected LoggerInterface        $logger,
                                protected User                   $user,
                                protected RouteProviderInterface $router)
    {

    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function create(ContainerInterface $container): TreeBuilderInterface
    {
        return new static(
            $container->get('db'),
            $container->get('logger'),
            $container->get('user'),
            $container->get('router')
        );
    }

    protected function isinPath(string $pagepath, string $elementpath): bool
    {
        return strncmp($pagepath, $elementpath, strlen($elementpath)) == 0;
    }

    protected function isPathIdentical(string $pagepath, string $elementpath): bool
    {
        return strcmp($pagepath, $elementpath) == 0;
    }

    public function getRootEntry(array $NavParams, int $ItemID = 0): array
    {
        $folder = $this->router->getRouteAsArrayByItem('folder', $ItemID);

        if (empty($folder))
            return array();
        $folder['depth'] = 0;

        return $folder;
    }

    public function buildTree(
        array  $NavParams,
        int    $PageFolderID,
        string $PagePath,
        array  $ParentFolder,
        int    $Depth = 0,
        bool   $OnlyPublished = true,
        bool   $prependParent = false,
        bool   $OnlyNavElements = true): array
    {
        $ret = array();

        if (($NavParams['depth'] > 0) && ($Depth > $NavParams['depth']))   // max depth exceeded
            return array();

        if (!empty($NavParams['permissioncode']))
            if (!$this->user->hasPermission($NavParams['permissioncode']))
                return array();

        if ($prependParent) {
            $ret[] = $ParentFolder;
        }

        $bind = array('parentid' => $ParentFolder['itemid']);
        $sql = 'SELECT * FROM ' . DatabaseRouter::ROUTES_PATH_TABLE . ' WHERE parentid=:parentid AND navtitle is not null';

        if ($OnlyPublished)
            $sql .= ' AND published="yes"';


        if ($OnlyNavElements) {
            $sql .= ' AND (navid=0 OR navid=:navid)';
            $bind['navid'] = $NavParams['id'];

            $sql .= ' AND innav="yes"';
        }

        $sql .= ' AND (activefrom<=:adat OR activefrom IS NULL) AND (activeuntil>:edat OR activeuntil is null)';
        $dat = $this->db->timestamp();
        $bind['adat'] = $dat;
        $bind['edat'] = $dat;

        $sql .= match ($ParentFolder['sorttyp']) {
            'chrono' => ' ORDER BY  sortvalue,navdate',
            default => ' ORDER BY sortvalue,navtitle'
        };

        $sql .= ' ' . $ParentFolder['sortdirection'];
        $liste = $this->db->getlist($sql, $bind);

        if (empty($liste))
            return array();

        foreach ($liste as $entry) {

            // ignore this entry if we do not have sufficient permission
            if (!empty($entry['permcode']))
                if (!$this->user->hasPermission($entry['permcode']))
                    continue;

            $entry['depth'] = $Depth;

            $inpath = $this->isinPath($PagePath, $entry['fullpath']);

            if ($inpath)
                $entry['inpath'] = true;

            if ($this->isPathIdentical($PagePath, $entry['fullpath']))
                $entry['selected'] = true;

            if ($entry['itemtype'] == 'folder')
                if ($inpath || $NavParams['expandallsubfolders'] == 'yes') {
                    $subelements = $this->buildTree($NavParams, $PageFolderID, $PagePath, $entry, Depth: $Depth + 1, OnlyPublished: $OnlyPublished, OnlyNavElements: $OnlyNavElements);
                    if (!empty($subelements))
                        $entry['children'] = $subelements;

                }

            $ret[] = $entry;

        }
        return $ret;
    }

}

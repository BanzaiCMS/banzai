<?php
declare(strict_types=1);

namespace Banzai\Navigation;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Flux\Container\ContainerInterface;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Users\User;


class TreeBuilderFromNavigationEntries implements TreeBuilderInterface
{

    public function __construct(protected DatabaseInterface $db,
                                protected LoggerInterface   $logger,
                                protected User              $user)
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
            $container->get('user')
        );
    }


    public function getRootEntry(array $NavParams, int $ItemID = 0): array
    {

        $folder = $this->db->get('SELECT * FROM ' . NavigationGateway::NAVENTRIES_TABLE . ' WHERE parentid=0 AND navareaid=?', array($NavParams['id']));

        if (empty($folder))
            return array();

        $folder['depth'] = 0;
        $folder['itemid'] = $folder['id'];

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

        $sql = 'SELECT * FROM ' . NavigationGateway::NAVENTRIES_TABLE . ' WHERE parentid=:parentid ';
        $bind = array('parentid' => $ParentFolder['id']);

        if ($OnlyPublished)
            $sql .= 'AND isactive="yes" ';

        $sql .= 'ORDER BY sortorder,navtitle';

        $liste = $this->db->getlist($sql, $bind);

        if (empty($liste))
            return array();

        foreach ($liste as $entry) {

            // ignore this entry if we do not have sufficient permission
            if (!empty($entry['permcode']))
                if (!$this->user->hasPermission($entry['permcode']))
                    continue;

            // set standard names
            $entry['depth'] = $Depth;
            $entry['itemid'] = $entry['id'];

            if ($entry['itemtype'] == 'folder')
                if ($NavParams['expandallsubfolders'] == 'yes') {
                    $subelements = $this->buildTree($NavParams, $PageFolderID, $PagePath, $entry, Depth: $Depth + 1, OnlyPublished: $OnlyPublished, OnlyNavElements: $OnlyNavElements);
                    if (!empty($subelements))
                        $entry['children'] = $subelements;
                }

            $ret[] = $entry;

        }
        return $ret;
    }

}

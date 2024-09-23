<?php
declare(strict_types=1);

namespace Banzai\Navigation;

use Flux\Container\ContainerInterface;

interface TreeBuilderInterface
{
    public static function create(ContainerInterface $container): TreeBuilderInterface;

    public function getRootEntry(array $NavParams, int $ItemID = 0): array;

    public function buildTree(
        array  $NavParams,
        int    $PageFolderID,
        string $PagePath,
        array  $ParentFolder,
        int    $Depth = 0,
        bool   $OnlyPublished = true,
        bool   $prependParent = false,
        bool   $OnlyNavElements = true): array;


}

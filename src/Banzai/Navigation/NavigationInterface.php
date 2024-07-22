<?php

namespace Banzai\Navigation;

use Banzai\Http\Routing\RouteInterface;

interface NavigationInterface
{
    public function createNavigation(int $PageFolderID, RouteInterface $route, bool $OnlyPublished = true): array;
}

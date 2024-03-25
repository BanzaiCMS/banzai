<?php
declare(strict_types=1);

namespace Banzai\Http\Routing;

use Banzai\Http\RequestInterface;

interface RouteProviderInterface
{
    public function deleteAllRoutes(): void;

    public function getRouteAsArrayByID(?int $id): array;

    public function getRouteAsArrayByItem(string $itemtype, int $itemid): array;

    public function getRouteByName(?string $name): ?RouteInterface;

    public function getRouteByPath(?string $path): ?RouteInterface;

    public function getRouteByRequest(RequestInterface $request, bool $OnlyPublished = true): ?RouteInterface;

    public function getRouteByItem(string $itemtype, int $itemid): ?RouteInterface;

    public function getRouteIDByItem(string $itemtype, int $itemid): ?int;

    public function delRouteByItem(string $itemtype, int $itemid): bool;

    public function createRouteByItem(string $itemtype, int $itemid): RouteInterface;

}

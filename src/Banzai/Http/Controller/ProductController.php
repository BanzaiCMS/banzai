<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Config\Config;
use Flux\Container\ContainerInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Domain\Products\ProductsGateway;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;
use Banzai\Http\Routing\RouteInterface;
use Banzai\Http\StatusResponse;
use Banzai\Http\Tracker\ClientTracker;
use Banzai\Navigation\NavigationGateway;


class ProductController implements ControllerInterface
{
    use ControllerTrait;

    public static function create(ContainerInterface $container): ControllerInterface
    {
        return new static(
            $container->get('logger'),
            $container->get('config'),
            $container->get('route'),
            $container->get('navigation'),
            $container->get('tracker'),
            $container->get(FoldersGateway::class),
            $container->get(ProductsGateway::class)
        );
    }

    public function __construct(protected LoggerInterface   $logger,
                                protected Config            $params,
                                protected RouteInterface    $route,
                                protected NavigationGateway $NavigationGateway,
                                protected ClientTracker     $tracker,
                                protected FoldersGateway    $FoldersGateway,
                                protected ProductsGateway   $ProductsGateway)
    {
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        return StatusResponse::create(500, array(), '<h1>This page was intentionally left blank.<h1>');
    }

}


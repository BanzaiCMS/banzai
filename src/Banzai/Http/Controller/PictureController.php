<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Config\Config;
use Flux\Container\ContainerInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Http\FileResponse;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;
use Banzai\Http\Routing\RouteInterface;


class PictureController implements ControllerInterface
{
    use ControllerTrait;

    public static function create(ContainerInterface $container): ControllerInterface
    {
        return new static(
            $container->get('logger'),
            $container->get('config'),
            $container->get('request'),
            $container->get('route'),
            $container->get(PicturesGateway::class)
        );
    }

    public function __construct(protected LoggerInterface  $logger,
                                protected Config           $params,
                                protected RequestInterface $request,
                                protected RouteInterface   $route,
                                protected PicturesGateway  $PicturesGateway
    )
    {
    }


    public function handle(RequestInterface $request): ResponseInterface
    {
        $id = $this->route->getContentID();
        $pic = $this->PicturesGateway->getPictureHeaderData($id);

        $securityheaders = $this->getSecurityHeaders();
        $headers = $this->PicturesGateway->getPictureHeader($pic);
        $headers = array_merge($securityheaders, $headers);

        if (!empty($this->route->getEtag()))
            $headers['Etag'] = '"' . $this->route->getEtag() . '"';

        session_write_close();

        return FileResponse::create($pic['filepath'], $headers);
    }

}


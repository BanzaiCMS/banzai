<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Config\Config;
use Flux\Container\ContainerInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Files\FilesGateway;
use Banzai\Http\FileResponse;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;
use Banzai\Http\Routing\RouteInterface;


class FileController implements ControllerInterface
{
    use ControllerTrait;

    public static function create(ContainerInterface $container): ControllerInterface
    {
        return new static(
            $container->get('logger'),
            $container->get('config'),
            $container->get('route'),
            $container->get(FilesGateway::class)
        );
    }

    public function __construct(protected LoggerInterface $logger,
                                protected Config          $params,
                                protected RouteInterface  $route,
                                protected FilesGateway    $FilesGateway
    )
    {
    }

    public function handle(RequestInterface $request): ResponseInterface
    {

        $id = $this->route->getContentID();

        $securityheaders = $this->getSecurityHeaders();

        $att = $this->FilesGateway->getFileHeaderData($id);
        $headers = $this->FilesGateway->getFileHeader($att);

        $headers = array_merge($securityheaders, $headers);

        if (!empty($this->route->getEtag()))
            $headers['Etag'] = '"' . $this->route->getEtag() . '"';

        session_write_close();

        return FileResponse::create($att['filepath'], $headers);

    }

}


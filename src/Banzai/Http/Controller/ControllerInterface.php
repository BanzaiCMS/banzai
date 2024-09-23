<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Container\ContainerInterface;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;

interface ControllerInterface
{
    public static function create(ContainerInterface $container): ControllerInterface;

    public function handle(RequestInterface $request): ResponseInterface;

}


<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Container\ContainerInterface;

class ControllerFactory
{

    public static function getController(ContainerInterface $di, string $cla = ''): ControllerInterface
    {

        // TODO check whether the class implements the interface ContainerInjectionInterface
        // TODO and error handling if not

        /** @noinspection PhpUndefinedMethodInspection */
        return $cla::create($di);

    }
}

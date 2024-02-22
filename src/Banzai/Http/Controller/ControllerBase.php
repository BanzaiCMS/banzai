<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Container\ContainerInterface;

abstract class ControllerBase implements ControllerInterface
{
    use ControllerTrait;

    public static function create(ContainerInterface $container): ControllerInterface
    {
        return new static();
    }

}



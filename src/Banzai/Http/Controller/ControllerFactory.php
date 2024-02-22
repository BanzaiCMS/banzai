<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Container\ContainerInterface;

class ControllerFactory
{

    public static function getController(ContainerInterface $di, string $cla = ''): ControllerInterface
    {

        // TODO prüfen ob die Klasse das Interface ContainerInjectionInterface implementiert
        // TODO und Fehlerbehandlung, falls nein

        /** @noinspection PhpUndefinedMethodInspection */
        return $cla::create($di);

    }
}

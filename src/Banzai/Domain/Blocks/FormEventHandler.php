<?php
declare(strict_types=1);

namespace Banzai\Domain\Blocks;

use Flux\Events\EventDispatcherInterface;
use Flux\Events\EventInterface;
use Flux\Events\EventListener;
use JetBrains\PhpStorm\NoReturn;

class FormEventHandler extends EventListener
{
    #[NoReturn]
    public function onEvent(EventInterface $event, string $eventName, EventDispatcherInterface $eventDispatcher)
    {
        echo('<pre>');
        print_r('ON EVENT');
        print_r($this->subject);
        exit(0);
    }

}

<?php

namespace Banzai\Domain\Blocks;

use Flux\Events\EventDispatcherInterface;
use Flux\Events\EventInterface;
use Flux\Events\EventListener;

class FormEventHandler extends EventListener
{
    public function onEvent(EventInterface $event, string $eventName, EventDispatcherInterface $eventDispatcher)
    {
        echo('<pre>');
        print_r('ON EVENT');
        print_r($this->subject);
        exit(0);
    }

}

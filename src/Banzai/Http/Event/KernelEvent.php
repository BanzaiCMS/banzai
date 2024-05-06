<?php

namespace Banzai\Http\Event;

use Flux\Events\Event;
use Banzai\Http\Kernel;
use Banzai\Http\RequestInterface;

class KernelEvent extends Event
{
    public function __construct(protected Kernel $kernel, protected RequestInterface $request)
    {
    }

    public function getKernel(): Kernel
    {
        return $this->kernel;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

}

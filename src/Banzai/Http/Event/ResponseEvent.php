<?php

namespace Banzai\Http\Event;

use Banzai\Http\Kernel;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;

class ResponseEvent extends KernelEvent
{

    public function __construct(Kernel $kernel, RequestInterface $request, protected ResponseInterface $response)
    {
        parent::__construct($kernel, $request);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

}

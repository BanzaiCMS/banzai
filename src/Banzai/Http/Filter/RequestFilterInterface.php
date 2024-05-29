<?php

namespace Banzai\Http\Filter;

use Banzai\Http\RequestInterface;

interface RequestFilterInterface
{
    public function filterRequest(RequestInterface $request): FilterReponse;

}

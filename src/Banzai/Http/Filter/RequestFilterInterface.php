<?php
declare(strict_types=1);

namespace Banzai\Http\Filter;

use Banzai\Http\RequestInterface;

interface RequestFilterInterface
{
    public function filterRequest(RequestInterface $request): FilterReponse;

}

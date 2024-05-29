<?php
declare(strict_types=1);

namespace Banzai\Http\Filter;

class FilterReponse
{

    function __construct(public bool $blockRequest, public bool $disableTracking = false, public int $StatusCode = 200, public string $StatusText = '', public string $InfoText = '')
    {

    }

}

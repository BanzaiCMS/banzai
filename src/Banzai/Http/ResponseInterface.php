<?php
declare(strict_types=1);

namespace Banzai\Http;

use Psr\Http\Message\ResponseInterface as Psr7ResponseInterface;

interface ResponseInterface extends Psr7ResponseInterface
{

    public function send();

    public function withRequestTracking():bool;

}

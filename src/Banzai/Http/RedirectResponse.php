<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Psr7\TempStream;
use Flux\Psr7\Response as Psr7Response;

class RedirectResponse extends Psr7Response implements ResponseInterface
{
    use ResponseTrait;

    public function __construct(string $url = '', int $code = 301)
    {
        parent::__construct(new TempStream());

        $this->statuscode = $code;
        $this->initHeader('Location', array($url));
    }

    public static function create(string $url = '', int $code = 301): static
    {
        return new static($url, $code);
    }

    public function send(): static
    {
        $this->sendHeaders();
        return $this;
    }

    public function withRequestTracking(): bool
    {
        return true;
    }

}

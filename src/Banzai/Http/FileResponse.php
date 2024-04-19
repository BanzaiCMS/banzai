<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Psr7\Response as Psr7Response;


class FileResponse extends Psr7Response implements ResponseInterface
{
    use ResponseTrait;

    protected string $filepath;

    public function __construct(string $filename, $headers = array())
    {
        parent::__construct();

        $this->filepath = $filename;

        foreach ($headers as $name => $value)
            $this->initHeader($name, $value);

    }

    public static function create(string $filename, $headers = array()): static
    {
        return new static($filename, $headers);
    }

    public function sendContent()
    {
        @ob_end_flush();
        readfile($this->filepath);
    }

    public function send(): static
    {
        $this->sendHeaders();
        $this->sendContent();
        return $this;
    }

    public function withRequestTracking():bool {
        return false;
    }

}

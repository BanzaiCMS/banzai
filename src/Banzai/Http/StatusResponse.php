<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Psr7\TempStream;
use Flux\Psr7\Response as Psr7Response;

class StatusResponse extends Psr7Response implements ResponseInterface
{
    protected string $charset = 'UTF-8';
    protected string $language = 'de';
    protected array $cookies = array();

    public function __construct(int $code = 404, $headers = array(), string $content = '')
    {
        parent::__construct(new TempStream());

        $this->statuscode = $code;

        foreach ($headers as $name => $value)
            $this->initHeader($name, $value);

        if (!empty($content))
            $this->body->write($content);

    }

    public static function create(int $code = 301, $headers = array(), string $content = ''): static
    {
        if (empty($content))
            $content = match ($code) {
                404 => '<html><head><title>404 Not Found</title></head><body><center><h1>404 Not Found</h1></center></body></html>',
                default => ''
            };
        return new static($code, $headers, $content);
    }

    public function sendHeaders(): static
    {
        header(sprintf('HTTP/%s %s %s', $this->getProtocolVersion(), $this->getStatusCode(), $this->getReasonPhrase()), true, $this->statuscode);

        foreach ($this->getHeaders() as $name => $values) {
            if ($this->isSingleValueHeader($name)) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            } else {
                header(sprintf('%s: %s', $name, implode(", ", $values)), false);
            }
        }

        return $this;

    }

    public function sendContent(): static
    {
        $body = $this->getBody();
        $body->rewind();
        echo $body->getContents();
        return $this;
    }

    public function send(): static
    {
        $this->sendHeaders();
        $this->sendContent();
        return $this;
    }


    private function isSingleValueHeader(string $name): bool
    {
        return match (strtolower($name)) {
            'set-cookie',
            'cookie' => true,
            default => false
        };

    }

    public function withRequestTracking(): bool
    {
        return true;
    }

}

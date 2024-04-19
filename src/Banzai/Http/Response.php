<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Psr7\TempStream;
use Flux\Psr7\Response as Psr7Response;

class Response extends Psr7Response implements ResponseInterface
{
    protected string $charset = 'UTF-8';
    protected string $language = 'de';
    protected array $cookies = array();

    use ResponseTrait;

    public function __construct(string $content = '', int $status = 200, array $headers = array(), array $cookies = array(), string $charset = 'UTF-8', string $language = 'de')
    {
        parent::__construct(new TempStream());

        $this->statuscode = $status;
        $this->cookies = $cookies;
        $this->charset = $charset;
        $this->language = $language;

        foreach ($headers as $name => $value)
            $this->initHeader($name, $value);

        if (!empty($content))
            $this->body->write($content);

    }

    public static function create(string $content = '', int $status = 200, array $headers = array(), array $cookies = array(), string $charset = 'UTF-8', string $language = 'de'): static
    {
        return new static($content, $status, $headers, $cookies, $charset, $language);
    }

    public function sendContent()
    {
        $body = $this->getBody();
        $body->rewind();
        echo $body->getContents();
    }

    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();
    }


    public function setContent(string $content): static
    {
        $this->body->write($content);
        return $this;
    }

    public function setStatusCode(int $statuscode, string $infotext): static
    {
        $this->statuscode = $statuscode;
        $this->reasonphrase = $infotext;
        return $this;
    }

    public function setCookie(string $field, string $value): static
    {
        $this->cookies[$field] = $value;
        return $this;
    }

    public function withRequestTracking():bool {
        return true;
    }

}

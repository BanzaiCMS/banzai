<?php
declare(strict_types=1);

namespace Banzai\Http;

trait ResponseTrait
{

    protected static bool $isRendered = false;

    protected function setRenderState(bool $state = true)
    {
        self::$isRendered = $state;
    }

    protected function getRenderState(): bool
    {
        return self::$isRendered;
    }


    public function sendHeaders()
    {
        header(sprintf('HTTP/%s %s %s', $this->getProtocolVersion(), $this->getStatusCode(), $this->getReasonPhrase()), true, $this->statuscode);

        // first send cookies

        foreach ($this->getHeaders() as $name => $values) {

            if (strcmp(strtolower($name), 'set-cookie') != 0)
                continue;

            if ($this->isSingleValueHeader($name)) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            } else {
                header(sprintf('%s: %s', $name, implode(", ", $values)), true);
            }
        }

        // now send everything else
        // it is important that we replace headers which were already set by PHP

        foreach ($this->getHeaders() as $name => $values) {

            if (strcmp(strtolower($name), 'set-cookie') == 0)
                continue;

            if ($this->isSingleValueHeader($name)) {
                $replace = true;
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $replace);
                    $replace = false;               // we replace only once, or we would overwerite our own headers if we have more than one value
                }
            } else {
                header(sprintf('%s: %s', $name, implode(", ", $values)), true);
            }
        }

    }

    public function setLanguage(string $code): static
    {
        if (!empty($code))
            $this->language = $code;

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

}

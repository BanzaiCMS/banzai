<?php
declare(strict_types=1);

namespace Banzai\Http;

use Psr\Http\Message\StreamInterface;
use Flux\Psr7\ServerInputStream;
use Flux\Psr7\ServerRequest as FluxServerRequest;
use Banzai\Core\Application;
use Banzai\I18n\Locale\LocaleServiceInterface;
use Banzai\Http\Session\SessionInterface;
use Banzai\Http\Session\Session;

class Request extends FluxServerRequest implements RequestInterface
{
    protected ?string $clientip;
    protected ?string $user;
    protected ?string $password;

    public function __construct(protected array                  $server,
                                protected array                  $get,
                                protected array                  $post,
                                protected array                  $cookies,
                                protected array                  $files,
                                protected StreamInterface        $body,
                                protected SessionInterface       $session,
                                protected LocaleServiceInterface $locale)
    {
        parent::__construct($server, $get, $post, $cookies, $files, $body);

        $this->clientip = $this->frontend['REMOTE_ADDR'];

        // if the client sent us a session-cookie, we automatically start the session
        $name = $this->session->getName();
        if ($this->hasCookie($name))
            $this->session->start();
    }

    public static function createFromGlobals(): RequestInterface
    {
        return new static($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, new ServerInputStream(), new Session(), Application::get('locale'));
    }

    public function getHost(): string
    {
        return $this->uri->getHost();
    }

    public function getHttpHost(): string
    {
        return $this->getHeaderLine('host');
    }

    public function getServerHost(): string
    {
        return $this->server['SERVER_NAME'];
    }

    public function getPort(): int
    {
        return $this->uri->getPort() ?? 0;
    }

    public function getMethod(): string
    {
        return strtolower($this->server['REQUEST_METHOD']);
    }

    public function getScheme(): string
    {
        return $this->uri->getScheme();
    }

    public function getAcceptLanguage(): string
    {
        if (empty($this->server['HTTP_ACCEPT_LANGUAGE']))
            return '';

        $al = $this->server['HTTP_ACCEPT_LANGUAGE'];

        if (strcmp($al, '*') == 0)
            return '';

        // we use internally codes according to ISO 15897
        // so we have to replace "-" (RFC) with "_"
        $al = str_replace('-', '_', $al);

        // names are case-insensitive, we use lower for matching
        return strtolower($al);

    }

    public function getPath(): string
    {
        return $this->uri->getPath();
    }

    public function getAbsolutePath(): string
    {
        $path = $this->uri->getPath();

        if (empty($path))
            return '/';

        if (strncmp($path, '/', 1) == 0)
            return $path;

        return '/' . $path;

    }

    public function getPathFileExtension(): string
    {
        $path = $this->uri->getPath();
        if (empty($path))
            return '';

        $pos = strrpos($path, '.');
        if ($pos === false)
            return '';

        return substr($path, $pos + 1);

    }

    public function getBasePath(): string
    {
        return $this->frontend['path'];
    }


    public function getClientIP(): ?string
    {
        return $this->clientip;
    }

    public function clearClientIP()
    {
        $this->clientip = '';
    }

    public function getBaseUri(bool $withslash = true, bool $frontend = true): string
    {

        $uri = $this->uri->getScheme();
        if (!empty($uri))
            $uri .= ':';

        $auth = $this->uri->getAuthority();

        if (!empty($auth))
            $uri .= '//' . $auth . '/';
        else
            $uri = '/';

        return $uri;
    }

    public function getRequestURI(): string
    {
        return $this->frontend['REQUEST_URI'];
    }


    public function getUser(): ?string
    {
        return $this->user;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getAcceptableContentTypes(): array
    {
        return array();
    }

    public function getLocale(): LocaleServiceInterface
    {
        return $this->locale;
    }

    public function getCharsets(): array
    {
        return array();
    }

    public function getEncodings(): array
    {
        return array();
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function hasCookie(string $name): bool
    {
        $cookies = $this->getCookieParams();
        return isset($cookies[$name]);
    }

    public function hasQuery(string $name): bool
    {
        $query = $this->getQueryParams();
        return isset($query[$name]);
    }

}

<?php
declare(strict_types=1);

namespace Banzai\Http;

use Psr\Http\Message\ServerRequestInterface as Psr7ServerRequestInterface;

use Banzai\I18n\Locale\LocaleServiceInterface;
use Banzai\Http\Session\SessionInterface;

interface RequestInterface extends Psr7ServerRequestInterface
{

    /**
     *  hostname of the server from the Uri
     */
    public function getHost(): string;

    /**
     *  hostname of the server from the http header
     */
    public function getHttpHost(): string;

    /**
     *  hostname of the server from the web server (virtual) host config
     */
    public function getServerHost(): string;

    /**
     * the port number or 0 if not specified in the uri
     *
     * @return int
     */
    public function getPort(): int;


    /**
     * the http method (post,get, put,....) in lowercase
     *
     */
    public function getMethod(): string;

    /**
     * the http scheme (http, https, ftp,...) in lowercase
     */
    public function getScheme(): string;

    /**
     * the path without query/fragment
     * path can be i.e.
     *  empty string   (root path)
     *  "/"            (absolute root path)
     *  "itema/itemb"  (relative path)
     *  "/itema/itemb" (absolute path)
     */
    public function getPath(): string;

    /**
     * the absolute path without query/fragment
     * it will always start with a '/'
     */
    public function getAbsolutePath(): string;

    /**
     * returns the file extension of the request, which is the path (without fragment and query) ending after the last "dot"
     */
    public function getPathFileExtension(): string;

    /**
     * the absolute base path, this always ends with a "/"
     * this is the path where the path element after the last "/" is removed.
     * in terms of a file-system, this is the path to a directory/folder
     */
    public function getBasePath(): string;

    /**
     * returns the uri of the request without path,query,fragment
     * the uri always ends with a slash
     */
    public function getBaseUri(): string;

    /**
     * returns the path of the request including query
     */
    public function getRequestURI(): string;

    /**
     *  returns the clientIP of the request, returns null if not set
     */
    public function getClientIP(): ?string;

    /**
     * removes the client-ip from the request as a privacy measure
     */
    public function clearClientIP();

    /**
     * returns the http username of the request or null
     */
    public function getUser(): ?string;

    /**
     * returns the http password of the request or null
     */
    public function getPassword(): ?string;

    public function getAcceptableContentTypes(): array;

    public function getAcceptLanguage(): string;

    public function getLocale(): LocaleServiceInterface;

    public function getCharsets(): array;

    public function getEncodings(): array;

    public function getSession(): SessionInterface;

    public function hasCookie(string $name): bool;

    public function hasQuery(string $name): bool;
}

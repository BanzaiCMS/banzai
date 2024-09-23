<?php
declare(strict_types=1);

namespace Banzai\I18n\Locale;

use Banzai\Http\RequestInterface;

interface LocaleServiceInterface
{
    const LANG_TABLE = 'languages';

    /**
     * @deprecated
     */
    public function getLanguageCodeFromID(int $id = 0): string;

    /**
     * @deprecated
     */
    public function getLanguageFromID(int $id = 0, bool $onlydefault = false): array;

    public function setLocaleFromHTTP(RequestInterface $request): string;

    public function setLocaleFromCode(string $lang): string;

    public function setLocaleFromID(int $id): string;

    public function getLocale(): string;

    public function getLocaleRFC(): string;

    public function getAll(): array;

    public function getID(): int;

    public function saveinSession(): void;

    public function getFromSession(): void;
}

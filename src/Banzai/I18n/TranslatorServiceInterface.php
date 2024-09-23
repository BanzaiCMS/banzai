<?php
declare(strict_types=1);

namespace Banzai\I18n;

interface TranslatorServiceInterface
{

    public function addDBtranslationData(int $langid = 0, string $textDomain = ''): void;

    public function addTranslationFile($type, $filename, $textDomain = '', $langid = 0): void;

    public function setLangid(int $langid = 0, bool $loaddata = true): void;

    public function setFallbackLangid(int $langid = 0, bool $loaddata = true): void;

    public function setAutoUpdate($update = true): void;

    public function translate(string $message, array $replacedata = array(), string $textDomain = '', int $langid = 0): string;

    public function isPlural($number = 0): bool;

    public function translatePlural(string $singular, string $plural, $number = 0, array $replacedata = array(), $textDomain = '', int $langid = 0): string;
}

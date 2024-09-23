<?php
declare(strict_types=1);

namespace Banzai\Renderers;

use const ENT_COMPAT;
use const ENT_HTML401;
use const ENT_SUBSTITUTE;
use Flux\Logger\LoggerInterface;

class RenderersGateway
{
    public function __construct(protected LoggerInterface $logger,
                                protected LegacyWikiText        $wiki)
    {

    }

    public static function escape_htmlspecialchars($text = ''): string
    {
        if (empty($text))
            return '';

        return htmlspecialchars($text, ENT_COMPAT | ENT_HTML401 | ENT_SUBSTITUTE, 'UTF-8');

    }

    public static function RenderText(?string $text = null, ?string $type = null): string
    {

        if (empty($text))
            return '';

        return match ($type) {
            'plain' => nl2br(self::escape_htmlspecialchars($text)),
            'structured',
            'wiki' => LegacyWikiText::wiki_parsetext($text),
            default => $text
        };

    }

}

<?php
declare(strict_types=1);

namespace Banzai\I18n;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;

class TranslatorService implements TranslatorServiceInterface
{

    const string TRANSLATION_TABLE = 'translations';

    private int $langid = 0;

    private int $fallbacklangid = 0;

    private array $transdata = array();

    private bool $autoupdatedb = true;


    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {

    }

    public function addDBtranslationData(int $langid = 0, string $textDomain = ''): void
    {
        if ($langid < 1) {
            $this->logger->error('langid<1');
            return;
        }

        $sql = 'SELECT translation_key,translation_text FROM ' . self::TRANSLATION_TABLE . ' WHERE language_id=? AND translation_textdomain=?';
        $liste = $this->db->getlist($sql, array($langid, $textDomain), 'translation_key', 'translation_text');
        $this->transdata[$langid] = $liste;

    }

    public function addTranslationFile($type, $filename, $textDomain = '', $langid = 0): void
    {
    }

    public function setLangid(int $langid = 0, bool $loaddata = true): void
    {
        if ($langid < 1) {
            $this->logger->error('langid<1');
            return;
        }

        $this->langid = $langid;

        if ($loaddata)
            $this->addDBtranslationData($langid);
    }


    public function setFallbackLangid(int $langid = 0, bool $loaddata = true): void
    {

        if ($langid < 1) {
            $this->logger->error('langid<1');
            return;
        }

        $this->fallbacklangid = $langid;

        if ($loaddata)
            $this->addDBtranslationData($langid);
    }

    public function setAutoUpdate($update = true): void
    {
        if ($update)
            $this->autoupdatedb = true;
        else
            $this->autoupdatedb = false;
    }


    public function translate(string $message, array $replacedata = array(), string $textDomain = '', int $langid = 0): string
    {

        if (empty($message))
            return '';

        $fallbacklangid = $this->fallbacklangid;

        if ($langid < 1)
            $langid = $this->langid;

        if ($langid < 1) {
            $this->logger->error('langid<1');
            return $message;
        }

        $message = strtolower($message);

        if (isset($this->transdata[$langid][$message])) {
            $ret = $this->transdata[$langid][$message];
            if (empty($ret))
                $ret = $message;

            if (!empty($replacedata))
                foreach ($replacedata as $feld => $inhalt)
                    $ret = str_replace('{{' . (string)$feld . '}}', (string)$inhalt, $ret);

            return $ret;
        }

        // message/key not found and automatically create empty data record
        if ($this->autoupdatedb) {
            $data = array();
            $data['language_id'] = $langid;
            $data['translation_key'] = $message;
            $this->db->add(self::TRANSLATION_TABLE, $data);
            $this->transdata[$langid][$message] = '';
        }

        if (($fallbacklangid > 0) && (isset($this->transdata[$fallbacklangid][$message]))) {
            $ret = $this->transdata[$fallbacklangid][$message];

            if (empty($ret))
                $ret = $message;

            if (!empty($replacedata))
                foreach ($replacedata as $feld => $inhalt)
                    $ret = str_replace('{{' . $feld . '}}', $inhalt, $ret);

            return $ret;
        }

        // message/key not found and automatically create empty data record
        if ($this->autoupdatedb) {
            $data = array();
            $data['language_id'] = $fallbacklangid;
            $data['translation_key'] = $message;
            $this->db->add(self::TRANSLATION_TABLE, $data);
            $this->transdata[$fallbacklangid][$message] = '';
        }

        $ret = $message;

        if (!empty($replacedata))
            foreach ($replacedata as $feld => $inhalt)
                $ret = str_replace('{{' . $feld . '}}', $inhalt, $ret);

        return $ret;
    }

    public function isPlural($number = 0): bool
    {

        if ($number == 1)
            return false;
        else
            return true;
    }

    public function translatePlural(string $singular, string $plural, $number = 0, array $replacedata = array(), $textDomain = '', int $langid = 0): string
    {
        $isplural = $this->isPlural($number);

        if ($isplural)
            $ret = $this->translate($plural, $replacedata, $textDomain, $langid);
        else
            $ret = $this->translate($singular, $replacedata, $textDomain, $langid);
        return $ret;
    }
}

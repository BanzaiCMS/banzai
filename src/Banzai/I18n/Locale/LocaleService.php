<?php
declare(strict_types=1);

namespace Banzai\I18n\Locale;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Http\RequestInterface;

class LocaleService implements LocaleServiceInterface
{

    protected array $data = array(
        'languages_id' => 1,
        'name' => 'Deutsch',
        'code' => 'de',
        'active' => 'yes',
        'default_lang' => 'yes'
    );

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
        $this->setDefaultLocale();
    }

    public function setDefaultLocale(): string
    {
        $data = $this->db->get('SELECT * FROM ' . LocaleServiceInterface::LANG_TABLE . ' WHERE default_lang=?', array('yes'));

        if (!empty($data))
            $this->data = $data;

        return $this->data['code'];

    }

    public function setLocaleFromHTTP(RequestInterface $request): string
    {
        $lang = $request->getAcceptLanguage();

        if (empty($lang))
            return $this->data['code'];

        $liste = $this->db->getlist('SELECT code FROM ' . LocaleServiceInterface::LANG_TABLE . ' WHERE active=?', array('yes'), null, 'code');

        if (empty($liste))
            return $this->data['code'];

        $selected = $this->MatchAcceptedLang($liste, $this->data['code'], $lang);

        if (empty($selected))
            return $this->data['code'];

        return $this->setLocaleFromCode($selected);

    }

    public function setLocaleFromCode(string $lang): string
    {
        if (empty($lang))
            return $this->data['code'];

        $lang = strtolower(str_replace('-', '_', $lang));

        $data = $this->db->get('SELECT * FROM ' . LocaleServiceInterface::LANG_TABLE . ' WHERE lower(code)=?', array($lang));

        if (!empty($data))
            $this->data = $data;

        return $this->data['code'];
    }

    public function setLocaleFromID(int $id): string
    {

        if ($id < 1)
            return $this->data['code'];

        $data = $this->db->get('SELECT * FROM ' . LocaleServiceInterface::LANG_TABLE . ' WHERE languages_id=?', array($id));

        if (!empty($data))
            $this->data = $data;

        return $this->data['code'];

    }

    protected function MatchAcceptedLang(array $allowed_languages, string $default_language, string $lang_variable, bool $strict_mode = true): string
    {

        // split list
        $accepted_languages = preg_split('/,\s*/', $lang_variable);

        $current_lang = $default_language;
        $current_q = 0;

        foreach ($accepted_languages as $accepted_language) {
            $res = preg_match(
                '/^([a-z]{1,8}(?:_[a-z]{1,8})*)(?:;\s*q=(0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?$/i',
                $accepted_language,
                $matches
            );

            // Syntax valid?
            if (!$res) {
                // No? Then ignore
                continue;
            }

            // Get the language code and then immediately separate it into individual parts
            $lang_code = explode('_', $matches[1]);

            // Was a quality included?
            if (isset($matches[2])) {
                // use quality
                $lang_quality = (float)$matches[2];
            } else {
                // Compatibility mode: Assume quality 1
                $lang_quality = 1.0;
            }

            // Until the language code is empty...
            while (count($lang_code)) {
                // let's see if the language code is offered
                if (in_array(strtolower(join('_', $lang_code)), $allowed_languages)) {
                    // View quality
                    if ($lang_quality > $current_q) {
                        // use this language
                        $current_lang = strtolower(join('_', $lang_code));
                        $current_q = $lang_quality;
                        // Exit the inner while loop here
                        break;
                    }
                }
                // When we are in strict mode, do not try to minimize the language
                if ($strict_mode) {
                    // break inner while loop
                    break;
                }
                // cut off the rightmost part of the language code
                array_pop($lang_code);
            }
        }

        // return the found language
        return $current_lang;
    }

    public function getLocale(): string
    {
        return $this->data['code'];
    }

    public function getLocaleRFC(): string
    {
        return str_replace('_', '-', $this->data['code']);
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function getID(): int
    {
        return $this->data['languages_id'];
    }

    /**
     * @deprecated
     */
    public function getLanguageCodeFromID(?int $id = null): string
    {
        if (is_null($id))
            return '';

        if ($id < 1)    // If no language is stored in objects, this is not an error that needs to be logged
            return '';

        $data = $this->db->get('SELECT code FROM ' . LocaleServiceInterface::LANG_TABLE . ' WHERE languages_id=?', array($id));

        if (empty($data)) {
            $this->logger->error('Language not found for id=' . $id);
            return '';
        }

        return $data['code'];

    }


    /**
     * @deprecated
     */
    public function getLanguageFromID(?int $id = null, bool $onlydefault = false): array
    {

        if (!$onlydefault)
            if (is_null($id) || ($id < 1))
                return array();

        $bind = array();

        $sql = 'SELECT * FROM ' . LocaleServiceInterface::LANG_TABLE . " WHERE active='yes'";

        if (!is_null($id))
            if ($id > 0) {
                $sql .= ' AND languages_id=?';
                $bind = array($id);
            }

        if ($onlydefault)
            $sql .= " AND default_lang='yes' ";

        $ret = $this->db->get($sql, $bind);

        if (empty($ret))
            $this->logger->warning('no langobj found. SQL:' . $sql);

        return $ret;
    }

    public function saveinSession(): void
    {
        $_SESSION['languageobj'] = $this->data;     // TODO replace $_SESSION usage
    }

    public function getFromSession(): void
    {
        if (!empty($_SESSION['languageobj']) && is_array($_SESSION['languageobj']))
            $this->data = $_SESSION['languageobj']; // TODO replace $_SESSION usage
    }

}

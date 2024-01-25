<?php

namespace Banzai\Authentication;

use Exception;
use Banzai\Core\Application;
use function random_bytes;

class CSRFProtector
{
    const sessiontokenname = 'banzaicsrftoken';

    public static function setToken()
    {
        if (!self::isTokenProtectionActive())
            return;

        try {
            $_SESSION[self::sessiontokenname] = bin2hex(random_bytes(64));
        } catch (Exception $e) {
            $_SESSION[self::sessiontokenname] = '';
        }

    }

    public static function clearToken()
    {
        unset($_SESSION[self::sessiontokenname]);
    }

    /**
     * @return string
     */
    public static function getToken(): string
    {
        if (empty($_SESSION[self::sessiontokenname]))
            return '';
        else
            return $_SESSION[self::sessiontokenname];

    }

    /**
     * @return string
     */
    public static function getTokenName(): string
    {
        return self::sessiontokenname;

    }

    /**
     * @return bool
     */
    public static function isTokenProtectionActive(): bool
    {
        return !empty(Application::get('config')->get('system.security.admin.csrf.token'));
    }


    /**
     * @return bool
     */
    public static function validateTokenFromPost(): bool
    {
        if (!self::isTokenProtectionActive())
            return true;

        if (empty($_POST[self::sessiontokenname]))
            return false;

        if (empty($_SESSION[self::sessiontokenname]))
            return false;

        return $_POST[self::sessiontokenname] == $_SESSION[self::sessiontokenname];

    }

}

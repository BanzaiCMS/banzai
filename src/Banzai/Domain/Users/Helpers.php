<?php

namespace Banzai\Domain\Users;

use Banzai\Core\Application;

class Helpers
{
    const CONTACTYPES_TABLE = 'contacts_types';

    /**
     *  temporary helper
     */
    public static function get_contacttype(string $conftag = ''): array
    {
        $db = Application::get('db');

        if (empty($conftag))
            return array();

        return $db->get('SELECT * FROM ' . self::CONTACTYPES_TABLE . ' WHERE conf_tag=?', array($conftag));

    }

}

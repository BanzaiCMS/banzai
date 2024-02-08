<?php
declare(strict_types=1);

namespace Banzai\Domain\Users;

use Flux\Config\ConfigurationInterface;
use Banzai\Core\Application;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Articles\ArticlesGateway;
use INS\Domain\Tickets\TicketsGateway;

class UsersGateway
{
    const USER_TABLE = 'users';

    static User|null $user = null;
    static DatabaseInterface|null $db = null;
    static LoggerInterface|null $logger = null;
    static ConfigurationInterface|null $conf = null;

    public function __construct()
    {
    }

    static function init(): void
    {
        if (is_null(self::$user))
            self::$user = Application::get('user');

        if (is_null(self::$db))
            self::$db = Application::get('db');

        if (is_null(self::$logger))
            self::$logger = Application::get('logger');

        if (is_null(self::$conf))
            self::$conf = Application::get('config');

    }


    /**
     * Liefert zu einer User-ID den vollen Namen (<1. Buchstabe des Vornamens> <Punkt> <Nachname>)
     * @param int $uid
     * @return string
     */
    static function get_userfullname($uid = 0): string
    {

        self::init();

        if ($uid < 1)
            return '';

        $user = self::$db->get('SELECT user_firstname, user_lastname FROM ' . self::USER_TABLE . ' WHERE user_id=?', array($uid));

        if (empty($user))
            return '';

        return substr($user['user_firstname'], 0, 1) . "Users " . $user['user_lastname'];
    }

    /**
     * @param $uid
     * @return string
     */
    static function get_user_longname($uid)
    {

        self::init();

        $rkat = self::$db->get('SELECT user_firstname, user_lastname, display_name FROM ' . self::USER_TABLE . ' where user_id=?', array($uid));
        if (empty($rkat))
            return '';

        if (!empty($rkat['display_name']))
            return $rkat['display_name'];

        $kat_vname = $rkat['user_firstname'];
        $kat_nname = $rkat['user_lastname'];

        if (empty($kat_vname))
            return ($kat_nname);

        return $kat_vname . ' ' . $kat_nname;

    }

    /**
     * @param int $uid
     * @return string
     */
    static function get_user_displayname($uid = 0): string
    {
        self::init();

        $user = self::$db->get('SELECT user_firstname, user_lastname, display_name FROM ' . self::USER_TABLE . ' WHERE user_id=?', array($uid));

        if (empty($user))
            return '';

        if (!empty($user['display_name']))
            return $user['display_name'];

        return substr($user['user_firstname'], 0, 1) . 'Users ' . $user['user_lastname'];
    }

    /**
     * @param $uid
     * @return string
     */
    static function get_user_profile_url($uid): string
    {

        self::init();

        $us = self::$db->get('SELECT profilepage_id FROM ' . self::USER_TABLE . ' where user_id=?', array($uid));

        if (empty($us))
            return '';
        if ($us['profilepage_id'] == 0)
            return '';
        else
            return Application::get(ArticlesGateway::class)->getArtURLFromID($us['profilepage_id']);
    }

    /**
     * @param $uid
     * @return mixed|string
     */
    static function get_userlogin($uid)
    {

        self::init();

        $a = self::$db->get('SELECT user_loginname FROM ' . self::USER_TABLE . ' WHERE user_id=' . $uid);
        if (is_array($a))
            return $a['user_loginname'];
        else
            return ' ';
    }


    /**
     * Liefert die Default-Rolle (Benutzergruppe) des Systems oder 0 falls keine gefunden wurde.
     *
     * @return int
     */
    static function get_default_role(): int
    {
        self::init();

        $rolle = self::$db->get('SELECT id FROM ' . ROLES_TABLE . ' WHERE defaultrole="yes"');
        if (empty($rolle)) {
            self::$logger->error('keine default-rolle gefunden');
            return 0;
        }

        return $rolle['id'];

    }


// //
// This function makes a new password from a plaintext password.
    /**
     * @param $plain
     * @param $salt
     * @param bool $saltfirst
     * @return string
     */
    static function encrypt_password_email($plain, $salt, $saltfirst = true)
    {
        if ($saltfirst)
            return hash('sha256', $salt . $plain);
        else
            return hash('sha256', $plain . $salt);
    }

    /**
     * @param int $len
     * @return string
     */
    static function create_password($len = 15)
    {
        $characterPool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_?%$!';    // TODO extend character pool of allowed characters
        $characterPoolLength = strlen($characterPool);

        $password = '';

        for ($i = 0; $i < $len; $i++) {
            $password .= substr($characterPool, ins_rand(0, $characterPoolLength), 1);  // TODO replace ins_rand
        }

        return $password;
    }


    /**
     * @param $user
     * @param string $title
     * @param string $direction
     * @param string $conftag
     * @param string $infotext
     * @param string $userip
     * @param int $visitid
     */
    static function log_userinfo($user, $title = '', $direction = '', $conftag = 'syslog', $infotext = '', $userip = '', $visitid = 0)  // make own table "user events" or such, not ticket table !!!
    {

        self::init();
        $userobj = self::$user->getAll();

        if (empty($user))
            return;

        $ct = Helpers::get_contacttype($conftag);

        if (empty($ct))
            return;

        // Wenn nicht User sondern custobj uebergeben wurde
        if ($user['objclass'] == 'address') {
            $user['address_id'] = $user['adr_id'];
            $user['user_id'] = 0;
        }

        if (empty($title))
            $title = $ct['name'];

        if (empty($userobj))
            $duser = $user;
        else
            $duser = $userobj;

        if (empty($direction)) {
            if ($user['user_id'] == $duser['user_id'])
                $direction = 'inbound';
            else
                $direction = 'outbound';
        }

        if ($direction != 'inbound')
            $direction = 'outbound';

        $t = array();
        $t['adr_id'] = $user['address_id'];
        $t['contact_id'] = $user['user_id'];
        $t['history_type_id'] = $ct['id'];
        $t['visibility'] = $ct['visibility'];
        $t['creator_id'] = $duser['user_id'];
        $t['direction'] = $direction;
        $t['title'] = $title;
        $t['summary'] = $infotext;
        $t['summary_type'] = 'plain';
        $t['contactdate'] = self::$db->timestamp();
        $t['created_date'] = $t['contactdate'];

        if ($visitid > 0)
            $t['visitid'] = $visitid;

        if (!empty($userip))
            $t['clientip'] = $userip;

        self::$db->add(TicketsGateway::TICKETHIST_TABLE, $t);

    }


}

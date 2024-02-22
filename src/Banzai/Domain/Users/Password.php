<?php declare(strict_types=1);

namespace Banzai\Domain\Users;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use function md5;
use const PASSWORD_ARGON2I;
use const PASSWORD_ARGON2ID;
use function password_hash;
use function password_verify;



/**
 * Class Password
 * @package Banzai\Domain\Users
 */
class Password
{
    const   MD5A = 'md5a';
    const   MYSQLMD5 = 'mysqlmd5';
    const   SHA256 = 'sha256';
    const   PWH = 'pwh';
    const   PASSWORD_HISTORY_TABLE = 'password_history';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }

    /**
     * Zentrale Funktion um ein Benutzerpasswort neu zu setzen und in der Datenbank zu speichern
     *
     * @param int $userid
     * @param string $passstring
     * @param bool $clearkey
     * @return bool
     */
    function set(int $userid, string $passstring, bool $clearkey = true): bool
    {

        if ($userid < 1) {
            $this->logger->error('change_user_pass: userid<1');
            return false;
        }

        if (empty($passstring)) {
            $this->logger->error('change_user_pass: passstring empty');
            return false;
        }

        $usr = $this->db->get('select user_id FROM ' . UsersGateway::USER_TABLE . ' WHERE user_id=?', array($userid));

        if (empty($usr)) {
            $this->logger->error('change_user_pass: userid (' . $userid . ') not found');
            return false;
        }

        // damit wir eine saubere user-id haben
        $userid = (int)$usr['user_id'];


        $options = array(
            'memory_cost' => 1 << 20, // 1024 MB
            'time_cost' => 4,
            'threads' => 3,
        );

        $algo = PASSWORD_ARGON2ID;

        $pwd = password_hash($passstring, PASSWORD_ARGON2ID, $options);

        if ($pwd === false) {
            $this->logger->critical('change_user_pass: userid (' . $userid . ') passwort erzeugen fehlgeschlagen');
            return false;
        }

        // 22.2.2018 prefix extrahieren, falls vorhanden um ihn für spätere auswertungen in der db zu speichern
        $pwa = explode('$', $pwd);

        if (isset($pwa[1]))
            $htype = $pwa[1];
        else
            $htype = '';

        $d = array();
        $d['user_id'] = $userid;
        $d['user_passdate'] = $this->db->timestamp(withTZ: true);
        $d['user_passtype'] = $algo;
        $d['user_passprefix'] = $htype;
        $d['user_password'] = $pwd;
        $d['user_saltpass'] = null;
        if ($clearkey)
            $d['lostpw_key'] = '';

        if (!$this->db->put(UsersGateway::USER_TABLE, $d, array('user_id'), false)) {
            $this->logger->error('can not change password', array('pwduserid' => $userid));
            return false;
        }

        // password_history schreiben
        unset($d['lostpw_key']);
        if ($this->db->add(self::PASSWORD_HISTORY_TABLE, $d) < 1)
            $this->logger->error('can not add password history.', array('pwduserid' => $userid));

        return true;
    }

    /**
     * @param int $userid
     * @param string $password
     * @param array $usr
     * @return bool
     */
    public function verify(int $userid, string $password, array $usr = array()): bool
    {

        if (empty($password))
            return false;

        if (empty($usr))
            $usr = $this->db->get('SELECT user_passtype,user_password,user_saltpass FROM ' . UsersGateway::USER_TABLE . ' WHERE user_id=?', array($userid));

        if (empty($usr)) {
            $this->logger->error('userid=' . $userid . ' not found.');
            return false;
        }

        $okidoki = false;

        switch ($usr['user_passtype']) { // Validationsverfahren ...
            case self::MD5A:
                $okidoki = $this->verifymd5a($password, $usr['user_password']);
                break;
            case self::MYSQLMD5:
                $okidoki = $this->validatemysqlmd5($password, $usr['user_password']);
                break;
            case self::SHA256:
                $okidoki = $this->verifysha256($password, $usr['user_password'], $usr['user_saltpass']);
                break;
            case self::PWH:
            case PASSWORD_ARGON2I:
            case PASSWORD_ARGON2ID:
                $okidoki = password_verify($password, $usr['user_password']);   // PHP builtin function
                break;
            default:

        }

        return $okidoki;

    }

    /**
     * @param string $plain
     * @param string $encrypted
     * @return bool
     */
    private function verifymd5a(string $plain, string $encrypted): bool
    {
        if ((!empty($plain)) && (!empty($encrypted))) {
            // split apart the hash / salt
            $stack = explode(':', $encrypted);

            if (sizeof($stack) != 2)
                return false;

            if (md5($stack[1] . $plain) == $stack[0]) {
                return true;
            }
        }

        return false;
    }


    /**
     * @param string $plain
     * @param string $encrypted
     * @param string $salt
     * @return bool
     */
    private function verifysha256(string $plain, string $encrypted, string $salt): bool
    {
        if (empty($plain))
            return false;

        if (empty($encrypted))
            return false;

        if (empty($salt))
            return false;

        $check = hash('sha256', $salt . $plain);
        return strcmp($check, $encrypted) == 0;
    }

    /**
     * @param string $plain
     * @param string $encrypted
     * @return bool
     */
    private function validatemysqlmd5(string $plain, string $encrypted): bool
    {
        $ret = $this->db->get('SELECT MD5(?) as mpwd ', array($plain));

        if (empty($ret))
            return false;

        $check = $ret['mpwd'];

        return strcmp($check, $encrypted) == 0;
    }

    /**
     * @param string $login
     * @param string $password
     * @return bool
     */
    public function validateLoginPassword(string $login, string $password): bool
    {
        if (empty($login) || empty($password))
            return false;

        $usr = $this->db->get('SELECT user_id,user_passtype,user_password,user_saltpass FROM ' . UsersGateway::USER_TABLE . ' WHERE user_loginname=?', array($login));

        if (empty($usr))
            return false;

        return $this->verify($usr['user_id'], $password, $usr);

    }

}
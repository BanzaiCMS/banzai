<?php

namespace Banzai\Authentication\password;

use Banzai\Core\Application;
use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use ZxcvbnPhp\Zxcvbn;


class Validator implements ValidatorInterface
{


    function __construct(protected ?DatabaseInterface $db = null, protected ?LoggerInterface $logger = null)
    {

    }


    public function verifyPassword(string $password = '', array $userdata = array()): array
    {

        $ret = array();

        $fail = false;

        $minlength = Application::get('config')->get('system.security.auth.password.min.length');
        $minscore = Application::get('config')->get('system.security.auth.password.min.score');

        if ($minlength > 0) {
            $len = strlen($password);
            if ($len < $minlength) {
                $fail = true;
                $ret['min.length'] = array(
                    'actual' => $len,
                    'target' => $minlength
                );
            }
        }

        if ($minscore > 0) {
            $zxcvbn = new Zxcvbn();
            $strength = $zxcvbn->passwordStrength($password, $userdata);
            $score = $strength['score'] + 1; // shift  1
            if ($score < $minscore) {
                $fail = true;
                $ret['min.score'] = array(
                    'actual' => $score,
                    'target' => $minscore
                );
            }
        }

        if ($fail)
            $ret['result'] = (boolean)false;
        else
            $ret['result'] = (boolean)true;

        return $ret;
    }
}


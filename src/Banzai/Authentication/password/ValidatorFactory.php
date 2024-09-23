<?php
declare(strict_types=1);

namespace Banzai\Authentication\password;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;

class ValidatorFactory
{

    /**
     * Creates a password validator class from sysconfig.
     * * Defaults to built-in class that uses Zxcvbn.
     */

    public static function create(DatabaseInterface $db = null, LoggerInterface $logger = null): ValidatorInterface
    {
        $cla = Application::get('config')->get('system.security.auth.password.validator');

        if (empty($cla))
            $cla = 'Validator';

        return new $cla($db, $logger);
    }
}

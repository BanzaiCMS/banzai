<?php
declare(strict_types=1);

namespace Banzai\Authentication\password;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;

interface ValidatorInterface
{

    function __construct(DatabaseInterface $db = null, LoggerInterface $logger = null);

    public function verifyPassword(string $password = '', array $userdata = array()): array;

}

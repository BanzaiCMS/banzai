<?php

namespace Banzai\Domain\Customers;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;

class Customer implements CustomerInterface
{
    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected array $customer = array())
    {
    }

    public function hasFeature(?string $featurecode = null): bool
    {
        if (empty($featurecode))
            return true;

        if (empty($this->customer['optarr']))
            return false;

        if (!is_array($this->customer['optarr']))
            return false;

        $opts = $this->customer['optarr'];

        if (isset($opts[$featurecode]))
            if ($opts[$featurecode] == 'yes')
                return true;

        return false;
    }

    public function hasPermission(?string $permissioncode = null): bool
    {
        if (empty($permissioncode))
            return true;

        if (isset ($this->customer['permissions'][$permissioncode]))
            return true;
        else
            return false;

    }


    public function getID(): int
    {
        return $this->customer['adr_id'];
    }

    public function getAll(): array
    {
        return $this->customer;
    }

}

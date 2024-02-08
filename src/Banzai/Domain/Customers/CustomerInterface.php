<?php

namespace Banzai\Domain\Customers;

interface CustomerInterface
{
    public function hasFeature(?string $featurecode = null): bool;

    public function hasPermission(?string $permissioncode = null): bool;

    public function getID(): int;

    public function getAll(): array;

}



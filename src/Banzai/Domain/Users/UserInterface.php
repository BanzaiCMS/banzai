<?php
declare(strict_types=1);

namespace Banzai\Domain\Users;

use Banzai\Domain\Customers\CustomerInterface;
use Banzai\Http\Session\Session;

interface UserInterface
{
    public function hasRootPermission(): bool;

    public function hasPermission(?string $permissioncode = null): bool;

    public function hasFeature(string $featurecode = ''): bool;

    public function isLoggedIn(): bool;

    public function isTrackingDisabled(): bool;

    public function isExpert(): bool;

    public function isBeginner(): bool;

    public function loadUserFromSession(Session $session): void;

    public function saveUsertoSession(Session $session): void;

    public function clear(): void;

    public function getFullName(): string;

    public function getID(): int;

    public function getLoginName(): string;

    public function getEmail(): string;

    public function getLocaleID():int;

    public function getHomePath():string;

    public function getAll(): array;

    public function hasCustomer(): bool;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(CustomerInterface $customer): void;

}



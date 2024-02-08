<?php

namespace Banzai\Domain\Users;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Customers\CustomerInterface;
use Banzai\Http\Session\Session;

class User implements UserInterface
{

    protected CustomerInterface|null $customer = null;

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger, protected array $user = array())
    {
    }

    public function hasRootPermission(): bool
    {
        if (!isset($this->user['role']['rootrole']))
            return false;

        return $this->user['role']['rootrole'] == 'yes';
    }

    public function hasPermission(?string $permissioncode = null): bool
    {
        if (empty($permissioncode))
            return true;

        if (isset ($this->user['permissions'][$permissioncode]))
            return true;

        if (is_null($this->customer))
            return false;
        else
            return $this->getCustomer()->hasPermission($permissioncode);
    }

    public function hasFeature(?string $featurecode = null): bool
    {
        if (empty($featurecode))
            return true;

        if (is_null($this->customer))
            return false;
        else
            return $this->getCustomer()->hasFeature($featurecode);
    }

    public function loadUserFromSession(Session $session): void
    {
        if (!$session->isStarted())
            return;

        if (!$session->has('userobj'))
            return;

        $this->user = $session->get('userobj');

    }

    public function saveUsertoSession(Session $session): void
    {
        if (!$session->isStarted())
            return;

        $session->set('userobj', $this->user);
    }

    public function clear(): void
    {
        $this->user = array();
        $_SESSION['userobj'] = array();
    }

    public function isLoggedIn(): bool
    {
        return isset($this->user['user_id']) && ($this->user['user_id'] > 0);
    }

    public function isTrackingDisabled(): bool
    {
        return isset($this->user['notracking']) && ($this->user['notracking'] == 'yes');
    }

    public function isExpert(): bool
    {
        return isset($this->user['user_mode']) && ($this->user['user_mode'] == 'expert');
    }

    public function isBeginner(): bool
    {
        return isset($this->user['user_mode']) && ($this->user['user_mode'] == 'beginner');
    }

    public function getFullName(bool $shortenFirstName = false): string
    {
        if (empty($this->user))
            return '';

        if (!$shortenFirstName)
            return $this->user['user_firstname'] . ' ' . $this->user['user_lastname'];

        return substr($this->user['user_firstname'], 0, 1) . "Users " . $this->user['user_lastname'];
    }

    public function getID(): int
    {
        if (empty($this->user) || empty($this->user['user_id']))
            return 0;
        else
            return $this->user['user_id'];
    }

    public function getLoginName(): string
    {
        if (empty($this->user))
            return '';
        else
            return $this->user['user_loginname'];
    }

    public function getEmail(): string
    {
        if (empty($this->user))
            return '';
        else
            return $this->user['user_email'];
    }

    public function getLocaleID(): int
    {
        return $this->user['language_id'];
    }

    public function getHomePath(): string
    {
        return $this->user['loginhomepath'];
    }

    public function getAll(): array
    {
        return $this->user;
    }

    public function hasCustomer(): bool
    {
        return !is_null($this->customer);
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

}

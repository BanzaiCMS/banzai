<?php
declare(strict_types=1);

namespace Banzai\Http\Session;

interface SessionInterface
{
    /**
     *  get a session value or the default value, if the value is not set
     */
    public function get(string $name, mixed $default = null): mixed;

    /**
     * set a session value
     */
    public function set(string $name, mixed $value): void;

    /**
     * checks if an entry with the name is in the storage data
     */
    public function has(string $name): bool;

    /**
     * remove an entry, if it exists
     */
    public function remove(string $name): void;

    /**
     * start the session
     */

    public function start(): bool;

    /**
     * get session id
     */
    public function getId(): string;

    /**
     * set session id
     */
    public function setId(string $id): void;

    /**
     *  get session name
     */
    public function getName(): string;

    /**
     * set session name
     */
    public function setName(string $name): void;

    /**
     * returns true if a session is already started
     */
    public function isStarted(): bool;

    public function delete(): void;

}

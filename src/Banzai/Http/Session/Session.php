<?php
declare(strict_types=1);

namespace Banzai\Http\Session;

class Session implements SessionInterface
{
    protected string $name = 'sessid';

    /**
     *  get a session value or the default value, if the value is not set
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (isset($_SESSION[$name]))
            return $_SESSION[$name];
        else
            return $default;
    }

    /**
     * set a session value
     */
    public function set(string $name, mixed $value)
    {
        $_SESSION[$name] = $value;
    }

    /**
     * checks if an entry with the name is in the storage data
     */
    public function has(string $name): bool
    {
        return isset($_SESSION[$name]);
    }

    /**
     * remove an entry, if it exists
     */
    public function remove(string $name)
    {
        if (isset($_SESSION[$name]))
            unset ($_SESSION[$name]);
    }

    /**
     * start the session
     */
    public function start(): bool
    {
        if ($this->isStarted())
            return false;

        session_name($this->name);
        return session_start();

    }

    /**
     *  get session name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * set session name
     */
    public function setName(string $name)
    {
        session_name($name);
        $this->name = $name;
    }

    /**
     * get session id
     */
    public function getId(): string
    {
        return session_id();
    }

    /**
     * set session id
     */
    public function setId(string $id)
    {
        session_id($id);
    }

    public function isStarted(): bool
    {
        return session_status() !== PHP_SESSION_NONE;
    }

    /**
     * deletes the current session including data and cookies
     * for a new session, session->start() has to be called
     */
    public function delete()
    {

        // clear data
        $_SESSION = array();

        // delete session cookie
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"],
            $params["domain"], $params["secure"], $params["httponly"]
        );

        // destroy session
        session_destroy();

    }

}


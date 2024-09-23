<?php
declare(strict_types=1);

namespace Banzai\Authentication;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Domain\Customers\CustomersGateway;
use Banzai\Domain\Users\Password;
use Banzai\Domain\Users\UsersGateway;
use Banzai\Http\Filter\ipv4;
use Banzai\Http\Filter\ipv6;
use Banzai\Http\RedirectResponse;
use Banzai\Http\Session\Session;
use Banzai\Http\Tracker\ClientTracker;
use Banzai\Domain\Users\User as DomainUser;

class user
{

    const int AUTH_FAIL_UsernameTooShort = 1;
    const int AUTH_FAIL_NoUsername = 2;
    const int AUTH_FAIL_PasswordTooShort = 3;
    const int AUTH_FAIL_PasswordMismatch = 4;
    const int AUTH_FAIL_UserNotActive = 5;
    const int AUTH_FAIL_UserIsBlocked = 6;
    const int AUTH_FAIL_OnlyAllowedOnStaging = 7;
    const int AUTH_FAIL_RoleNotSet = 8;
    const int AUTH_FAIL_RoleNotFound = 9;
    const int AUTH_FAIL_RoleIsBlocked = 10;        // we do not log this as a failed attempt, because it could be blocked because of maintenance (i.e. not an user error)

    protected array $reasons = array(
        self::AUTH_FAIL_UsernameTooShort => "username too short",
        self::AUTH_FAIL_NoUsername => 'no username',
        self::AUTH_FAIL_PasswordTooShort => 'password too short',
        self::AUTH_FAIL_PasswordMismatch => 'wrong password',
        self::AUTH_FAIL_UserNotActive => 'user is not active',
        self::AUTH_FAIL_UserIsBlocked => 'user is blocked',
        self::AUTH_FAIL_OnlyAllowedOnStaging => 'user is only allowed on staging',
        self::AUTH_FAIL_RoleNotSet => 'role is not set',
        self::AUTH_FAIL_RoleNotFound => 'role not found',
        self::AUTH_FAIL_RoleIsBlocked => 'role is blocked'
    );

    public function __construct(
        protected DatabaseInterface $db,
        protected LoggerInterface   $logger,
        protected DomainUser        $user,
        protected ClientTracker     $tracker,
        protected Password          $password)
    {

    }

    public function createUser(string $Username, string $Password = '', bool $ignorePassword = false, bool $isStaging = false): DomainUser|int
    {

        if (strlen($Username) < 2) {
            return self::AUTH_FAIL_UsernameTooShort;
        }


        $usr = $this->db->get('SELECT * FROM ' . UsersGateway::USER_TABLE . ' WHERE user_loginname=?', array($Username));

        if (empty($usr)) {
            return self::AUTH_FAIL_NoUsername;
        }


        if (!$ignorePassword) {

            if (strlen($Password) < 2) {
                return self::AUTH_FAIL_PasswordTooShort;
            }

            if (!$this->password->verify($usr['user_id'], $Password, $usr)) {
                return self::AUTH_FAIL_PasswordMismatch;
            }

        }

        // from here the user credentials are correct

        if ($usr['user_active'] == 'no') { // registration was not completed, so user is not allowed to log in
            return self::AUTH_FAIL_UserNotActive;
        }

        if ($usr['user_blocked'] == 'yes') { // user is blocked and is not allowed to log in
            return self::AUTH_FAIL_UserIsBlocked;
        }

        if (!$isStaging) {
            if ($usr['user_only_staging_allowed'] == 'yes') { // user is only allowed on staging-server, but we are not a staging-server
                return self::AUTH_FAIL_OnlyAllowedOnStaging;
            }
        }

        // from here, the password is correct and log in should be successful


        // check Role-Permissions
        if ($usr['group_id'] <= 0) {
            return self::AUTH_FAIL_RoleNotSet;
        }

        $role = $this->db->get('SELECT * FROM ' . Permissions::ROLES_TABLE . ' WHERE id=?', array($usr['group_id']));

        if (empty($role)) {
            return self::AUTH_FAIL_RoleNotFound;
        }

        // get role permissions
        $permissions = $this->db->getlist('SELECT permid, code FROM ' . Permissions::ROLEPERM_TABLE . ',' . Permissions::PERM_TABLE . ' WHERE ' . Permissions::ROLEPERM_TABLE . '.roleid=? AND ' . Permissions::ROLEPERM_TABLE . '.permid=' . Permissions::PERM_TABLE . '.id', array($usr['group_id']), 'code', 'permid');

        // get User-Permissions
        $upermo = $this->db->getlist('SELECT u.permid,p.code FROM ' . Permissions::USER_PERM_TABLE . ' u JOIN ' . Permissions::PERM_TABLE . ' p ON u.permid=p.id WHERE user_id=?', array($usr['user_id']), 'code', 'permid');
        if (!empty($upermo)) {
            foreach ($upermo as $feld => $inhalt) {
                $permissions[$feld] = $inhalt;
            }
        }

        // we only check if the role is blocked if we are not the root role (because the root role can never be blocked)
        // also, if 'ignore_roles_isblocked' is set in the user permission, the role-block is ignored
        if (($role['isblocked'] == 'yes') && ($role['rootrole'] == 'no') && (!isset($permissions['ignore_roles_isblocked']))) {
            return self::AUTH_FAIL_RoleIsBlocked;
        }

        // login is successful

        if (empty($role['loginhomepath'])) {
            if (isset($permissions['backend_user'])) {
                $role['loginhomepath'] = '/admin/v1/';
            } else
                $role['loginhomepath'] = '/';
        }

        $usr['loginhomepath'] = $role['loginhomepath'];

        $usr['role'] = $role;
        $usr['permissions'] = $permissions;

        // now we create the object, could be replaced in a factory
        $userobj = new DomainUser($this->db, $this->logger, $usr);

        // if address_id is set, we get the customer record of the user

        // TODO can this be done better ??
        if ($usr['address_id'] > 0) {
            $customerobj = Application::get(CustomersGateway::class)->getCustomerByID($usr['address_id']);
            if (is_object($customerobj)) {
                $userobj->setCustomer($customerobj);
            }
        }

        return $userobj;

    }


    /**
     * Logs a user in and sets all global session and system variables/arrays
     */
    public function login(string $uname = null, string $upass = null, bool $relogin = false, bool $redirect = true): bool
    {
        $request = Application::get('request');
        $session = $request->getSession();

        if (empty($uname))
            if (!$this->user->hasPermission('substitute_user'))
                return false;

        // do not log post data, there is sensitive information, like password in cleartext
        $this->tracker->dontLogPostData();

        $session->set('docommand', '');

        $ip = $request->getClientIP(); // always log ip internally for errors

        $user = $this->createUser($uname, $upass, $this->user->hasPermission('substitute_user'), Application::getApplication()->isStaging());

        if (!is_object($user)) {    // creation has failed

            if ($user != self::AUTH_FAIL_RoleIsBlocked) {
                $this->logger->warning('login failed from ipaddress ' . $ip . ' ' . $this->reasons[$user], array(
                    'msgid' => 'loginfail',
                    'ip' => $ip,
                    'reason' => $this->reasons[$user]
                ));
            } else {
                $blockurl = Application::get('config')->get('system.url.roleisblocked');
                if (!empty($blockurl)) {
                    RedirectResponse::create($blockurl)->send();
                    exit(0);
                }
            }

            return false;
        }


        if ($user->getLocaleID() > 0) {
            $request->getLocale()->setLocaleFromID($user->getLocaleID());
            $request->getLocale()->saveinSession(); // always cache actual locale after login even if user has no one set
        }

        // login was successful

        $this->logger->setUserID($user->getID());

        // TODO do not use session data directly ...
        $this->saveUserLoginFingerprintInSession($session, $ip, $_SERVER['HTTP_USER_AGENT']);

        CSRFProtector::setToken();

        $user->saveUsertoSession($session);

        if (!$relogin) {
            UsersGateway::log_userinfo($user->getAll(), 'Login', 'inbound', 'login', '', $ip);

            $data = array();
            $data['user_id'] = $user->getID();
            $data['user_login_failures'] = 0;
            $data['user_lastvisit'] = $this->db->timestamp();
            $data['user_lastclick'] = $this->db->timestamp();
            $data['user_online'] = 'yes';
            $data['user_lastip'] = $ip;

            if (Application::get('config')->get('system.tracking.userbrowser') == 'yes') {      // TODO
                $uas = $_SERVER['HTTP_USER_AGENT'];         // TODO
                if (!empty($uas)) {
                    $wt = Application::get('tracker');
                    $uar = $wt->userAgent($uas);
                    $data['client_browser'] = $uar['user_agent'];
                    $data['client_browserversion'] = $uar['user_agent_version'];
                }
            }

            $this->db->put(UsersGateway::USER_TABLE, $data, array('user_id'), false);

        }

        $this->logger->info('login successful from ipaddress ' . $ip . ' username ' . $uname, array(
            'msgid' => 'login',
            'ip' => $ip,
            'user' => $uname
        ));


        if ($redirect) {
            $nexturl = $user->getHomePath();
            if (empty($nexturl))
                $nexturl = '/';

            unset($_SESSION["nexturl"]);
            unset($_SESSION["docommand"]);

            RedirectResponse::create($nexturl)->send();
            exit(0);
        }

        return true;
    }

    /**
     * logs a user out, destroys session, updates database and sends a browser redirect as default
     *
     */
    public function logout(string $redirect = null, bool $withredirection = true): bool
    {

        $User = Application::get('user');
        $userobj = $User->getAll();

        $request = Application::get('request');


        $ipl = $request->getClientIP(); // für fehler logging immer protokollieren

        $_SESSION["docommand"] = "";

        CSRFProtector::clearToken();    // TODO check

        if ($User->isLoggedIn()) {
            UsersGateway::log_userinfo($userobj, 'Logout', 'inbound', 'logout');

            $data = array();
            $data['user_lastclick'] = $this->db->timestamp();
            $data['user_online'] = 'no';
            $data['user_id'] = $User->getID();
            $this->db->put(UsersGateway::USER_TABLE, $data, array('user_id'), false);

            $this->logger->info('logout successful from ipaddress ' . $ipl . ' username ' . $User->getLoginName(), array(
                'msgid' => 'logout',
                'ip' => $ipl,
                'user' => $userobj['user_loginname']
            ));
        } else
            $this->logger->info('logout unsuccessful from ipaddress ' . $ipl . ' not logged in', array(
                'msgid' => 'logoutfail',
                'ip' => $ipl
            ));

        $ne = '';

        if ($withredirection) {
            $ne = $_SESSION["nexturllogout"];

            if (empty($ne))
                if (!empty($_POST['nexturl']))  // TODO maybe check if in $_POST['nexturl'] really is a new URL?
                    $ne = $_POST['nexturl'];

            if (empty($ne))
                $ne = '/';

            if (!empty($redirect))
                $ne = $redirect;
        }


        $this->logger->setUserID(0);
        Application::get('user')->clear();
        Application::get('user')->saveUsertoSession($request->getSession());
        $request->getSession()->delete();

        // Redirect
        if ($withredirection) {
            RedirectResponse::create($ne)->send();
            exit(0);
        }

        return true;
    }


    public function notifyLoginFailure(array $user): void
    {

        if (empty($user)) {
            $this->logger->error('userobject empty');
            return;
        }

        $userid = $user['user_id'];
        if ($userid < 1) {
            $this->logger->error('userid<1');
            return;
        }

        $maxfail = Application::get('config')->get('system.security.auth.loginfailure.max');

        if ($maxfail < 1)
            $maxfail = 0;

        if ($user['user_blocked'] == 'yes') // User bereits gesperrt, do nothing
            return;

        // increment failed attempt counter
        if (($user['user_login_failures'] < $maxfail)) {
            $data = array();
            $data['user_login_failures'] = $user['user_login_failures'] + 1;
            $data['user_id'] = $userid;
            $this->db->put(UsersGateway::USER_TABLE, $data, array('user_id'), false);
            return;
        }

        if ($maxfail == 0) // no maximum of allowed failed attempts
            return;

        // disable user
        $data = array();
        $data['user_login_failures'] = $user['user_login_failures'] + 1;
        $data['user_id'] = $userid;
        $data['user_blocked'] = 'yes';
        $this->db->put(UsersGateway::USER_TABLE, $data, array('user_id'));

        $user['user_login_failures'] += 1; // here too
        $user['user_blocked'] = 'yes';


        // TODO feature request: make a factory that replaces this code

        // call the class
        $cla = Application::get('config')->get('system.security.auth.maxloginfailure.notification');
        if (empty($cla))
            return;

        $notify = new $cla($this->db, $this->logger);
        if (!is_object($notify)) {
            $this->logger->error('class ' . $cla . ' not ready');
            return;
        }

        $notify->send($user);
    }

    public function saveUserLoginFingerprintInSession(Session $session, string $ip, string $useragent): void
    {
        // we mask the lower 64 bits of ipv6 because they can change as a result of ipv6 privacy extensions
        if (ipv4::getIPVersion($ip) == 6)
            $ip = ipv6::inet6_filter_maskinterfacebits($ip);

        $session->set('user_login_fingerprint_ip', $ip);
        $session->set('user_login_fingerprint_user_agent', $useragent);
    }

    public function verifyUserLoginFingerprint(Session $session, string $username, string $ip, string $useragent): bool
    {
        $conf = Application::get('config');

        if ($conf->get('system.security.auth.session.sameip') == 'yes') {

            // we mask the lower 64 bits of ipv6 because they can change as a result of ipv6 privacy extensions
            if (ipv4::getIPVersion($ip) == 6)
                $ipcheck = ipv6::inet6_filter_maskinterfacebits($ip);
            else
                $ipcheck = $ip;

            if ($session->has('user_login_fingerprint_ip') && (strcmp($session->get('user_login_fingerprint_ip'), $ipcheck) != 0)) {
                $this->logger->error('usersession blocked from ipaddress ' . $ip . ' because ip has changed since login', array(
                    'msgid' => 'usersessionfail',
                    'iplogin' => $_SESSION['user_login_fingerprint_ip'],
                    'ipactual' => $ip,
                    'user' => $username
                ));

                return false;
            }
        }

        if ($conf->get('system.security.auth.session.sameua') == 'yes')
            if ($session->has('user_login_fingerprint_user_agent') && (strcmp($session->get('user_login_fingerprint_user_agent'), $useragent) != 0)) {
                $this->logger->error('usersession blocked from ipaddress ' . $ip . ' because useragent has changed since login', array(
                    'msgid' => 'usersessionfail',
                    'ualogin' => $_SESSION['user_login_fingerprint_user_agent'],
                    'uaactual' => $useragent,
                    'user' => $username
                ));

                return false;
            }

        return true;

    }

}

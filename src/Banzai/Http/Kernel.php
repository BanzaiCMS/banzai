<?php
declare(strict_types=1);

namespace Banzai\Http;

use Flux\Container\ContainerInterface;
use Flux\Core\ApplicationInterface;
use Banzai\Core\Application;
use Banzai\WAF\Wrapper as FirewallWrapper;
use Banzai\Http\Controller\ControllerFactory;
use Banzai\Domain\Customers\CustomersGateway;
use Banzai\Http\Event\ResponseEvent;
use Banzai\Http\Routing\RouteInterface;

class Kernel
{
    public function __construct(protected ApplicationInterface $app, protected ContainerInterface $container)
    {

    }

    public static function create(): static
    {
        return new static(Application::getApplication(), Application::getContainer());
    }

    public function process()
    {

        $request = $this->container->get('request');    // request is set in bootstrap.php include
        $logger = $this->container->get('logger');
        $params = $this->container->get('config');
        $filter = $this->container->get('filter');

        $tracker = $this->container->get('tracker');    // request is set in bootstrap.php include

        $score = FirewallWrapper::create($logger, $request, $params->get('path.framework.config'), $params->get('path.var'))->check();
        $maxscore = $params->get('system.security.waf.score.max', 0);
        if (($maxscore > 0) && ($score > $maxscore)) {
            $tracker->setPageError(301)->setWAFScore($score)->logRequest();
            RedirectResponse::create('/')->send();
            exit(0);
        }

        $user = $this->container->get('user');

        $locale = $request->getLocale();

        $locale->setLocaleFromHTTP($request);

        $session = $request->getSession();

        if (!$session->isStarted())
            if ($params->get('system.session.cookie', 'no') == 'yes')
                $session->start();

        if ($session->isStarted()) {

            $locale->getFromSession();
            if (isset($_REQUEST['changelanguage']) && (!empty($_REQUEST['changelanguage']))) {
                $locale->setLocaleFromCode($_REQUEST['changelanguage']);
                $locale->saveinSession();
            }

            $user->loadUserFromSession($session);
            if ($user->isLoggedIn()) {
                if ($this->container->get('auth')->verifyUserLoginFingerprint($session, $user->getLoginName(), $request->getClientIP(), $_SERVER['HTTP_USER_AGENT'])) {
                    $customer = $this->container->get(CustomersGateway::class)->getCustomerFromSession();
                    if (!is_null($customer))
                        $user->setCustomer($customer);

                } else {
                    $user->clear();
                }

            }
        }

        $FilterResponse = $filter->filterRequest($request);

        if (!$FilterResponse->blockRequest && ($FilterResponse->disableTracking))   // Only disable if not blocked, never enable
            $tracker->setTraceFlag(false);

        if ($FilterResponse->blockRequest) {
            $tracker->setTraceFlag(!$FilterResponse->disableTracking);          // always enable/disable tracking
            $tracker->setPageError($FilterResponse->StatusCode)->logRequest();
            StatusResponse::create($FilterResponse->StatusCode, array(), $FilterResponse->InfoText)->send();
            exit(0);
        }

        if (isset($_GET['preview']) && $user->isLoggedIn()) // TODO replace $_GET
            $allowonlypublished = false;
        else
            $allowonlypublished = true;

        $route = $this->container->get('router')->getRouteByRequest($request, $allowonlypublished);

        if (is_null($route)) {

            $url = $request->getAbsolutePath();

            // first we check, if we have a special extension, where we should always respond with a 404 if not found
            $ext = strtolower($request->getPathFileExtension());
            if (!empty($ext)) {
                $extlist = explode(',', strtolower($params->get('system.url.404extensions')));
                if (in_array($ext, $extlist, true)) {
                    $tracker->setPageError(404)->logRequest();
                    StatusResponse::create(404)->send();
                    exit(0);
                }
            }

            // if the rightmost character is "/" we remove it, so we can check the parent folder of a folder
            if (str_ends_with($url, '/'))
                $url = substr($url, 0, strlen($url) - 1);

            // now we cut the url to just have the path of the parent url

            $pos = strrpos($url, '/');
            if ($pos === false) {
                $tracker->setPageError(301)->logRequest();
                RedirectResponse::create('/')->send();
                exit(0);
            }

            $newurl = substr($url, 0, $pos + 1);
            $newroute = $this->container->get('router')->getRouteByPath($newurl);

            if (empty($newroute)) {
                $tracker->setPageError(301)->logRequest();
                RedirectResponse::create('/')->send();
                exit(0);
            }

            switch ($newroute->getNotfoundType()) {
                case RouteInterface::NotfoundCallFolder :   // we proceed with the route we have found and the actual requst url
                    $route = $newroute;
                    break;
                case RouteInterface::NotFoundRedirect302    :
                    $tracker->setPageError(302)->logRequest();
                    RedirectResponse::create($newurl)->send();
                    exit(0);
                case RouteInterface::NotFoundShow404    :
                    $tracker->setPageError(404)->logRequest();
                    StatusResponse::create(404)->send();
                    exit(0);
                case RouteInterface::NotFoundRedirect301    :
                default:
                    $tracker->setPageError(301)->logRequest();
                    RedirectResponse::create($newurl)->send();
                    exit(0);
            }

        }

        $this->container->setInstance('route', $route);

        if ($route->requiresPermission()) {
            if (!$user->isLoggedIn()) {

                $LoginRoute = $this->container->get('router')->getRouteByName('login');

                $tracker->setPageError(301)->logRequest();

                if (is_null($LoginRoute))
                    RedirectResponse::create('/')->send();
                else
                    RedirectResponse::create($LoginRoute->getPath())->send();


                exit(0);

            } elseif (!$user->hasPermission($route->requiredPermission())) {

                $NopermRoute = $this->container->get('router')->getRouteByName('noperm');

                $tracker->setPageError(403)->logRequest();

                if (is_null($NopermRoute))
                    StatusResponse::create(403, array(), '<h1>NO PERMISSION!</h1>')->send();
                else
                    RedirectResponse::create($NopermRoute->getPath())->send();

                exit(0);

            }

        }

        if ($request->hasHeader('if_none_match')) {
            if ($route->hasEtag($request->getHeaderLine('if_none_match'))) {
                // no logging in tracker
                StatusResponse::create(304, array('Etag' => '"' . $route->getEtag() . '"'))->send();
                exit(0);
            }
        }

        $controller = ControllerFactory::getController($this->container, $route->getControllerClassname());

        $response = $controller->handle($request);

        $event = new ResponseEvent($this, $request, $response);
        $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::RESPONSE);
        $response = $event->getResponse();

        if ($request->getMethod() == 'post') {
            $event = new ResponseEvent($this, $request, $response);
            $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::POSTRESPONSE);
            $response = $event->getResponse();
        }

        if ($response->withRequestTracking())
            $tracker->logRequest();     // TODO more details probably

        $response->send();

    }

}

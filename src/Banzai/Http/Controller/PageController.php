<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Banzai\Core\Application;
use Banzai\Http\RequestInterface;
use Banzai\Http\HtmlResponse;
use Banzai\Http\ResponseInterface;

class PageController extends AbstractController implements ControllerInterface
{

    public function handle(RequestInterface $request): ResponseInterface
    {
        $app = array(
            'params' => $this->params,
            'route' => $this->route,
            'tracker' => $this->tracker,
            'request' => $request,
            'user' => Application::get('user'),
            'service' => Application::getApplication()        // global Application Container for accessing services from templates
        );
        Application::get('twig')->addGlobal('app', $app);

        $data = $this->preparePageData();

        if (is_object($data))
            return $data;

        $response = HtmlResponse::create($data, 200, $this->getSecurityHeaders());

        if ($request->hasQuery('banner-confirm')) {
            $cookie = 'cookiebannerconfirmed=yes; HttpOnly; SameSite=Lax; Path=/';
            $response = $response->withAddedHeader('set-cookie', array($cookie));
        }

        return $response;

    }

}


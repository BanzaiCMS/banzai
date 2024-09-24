<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Banzai\Domain\Blocks\BlocksGateway;
use Flux\Config\Config;
use Flux\Container\ContainerInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Core\Application;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Http\HtmlResponse;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;
use Banzai\Http\Routing\RouteInterface;
use Banzai\Http\Tracker\ClientTracker;
use Banzai\Navigation\NavigationGateway;
use Banzai\Search\ElasticService;

class ElasticSearchController extends AbstractController implements ControllerInterface
{
    use ControllerTrait;

    public static function create(ContainerInterface $container): ControllerInterface
    {
        return new static(
            $container->get('logger'),
            $container->get('config'),
            $container->get('route'),
            $container->get('navigation'),
            $container->get('tracker'),
            $container->get('elastic'),
            $container->get(FoldersGateway::class),
            $container->get(ArticlesGateway::class),
            $container->get(BlocksGateway::class)
        );
    }

    public function __construct(LoggerInterface          $logger,
                                Config                   $params,
                                RouteInterface           $route,
                                NavigationGateway        $NavigationGateway,
                                ClientTracker            $tracker,
                                protected ElasticService $elastic,
                                FoldersGateway           $FoldersGateway,
                                ArticlesGateway          $ArticlesGateway,
                                BlocksGateway            $BlocksGateway)
    {
        parent::__construct($logger, $params, $route, $NavigationGateway, $tracker, $FoldersGateway, $ArticlesGateway, $BlocksGateway);
    }

    protected function dosearch($query): array
    {

        $ret = array('query' => $query, 'result' => array());

        $response = $this->elastic->query($query, 15);

        if (empty($response))
            return $ret;

        $ret['response'] = $response;

        $hits = $response['hits']['hits'];

        foreach ($hits as $entry) {
            $r = $entry['_source'];
            $title = $entry['_source']['Title'];
            $r['ShortTitle'] = $this->ArticlesGateway->limitString($title, 60, '...');

            $description = $entry['_source']['ContentText'];
            $description = html_entity_decode($description, ENT_COMPAT, 'UTF-8');    // nochmal, um Unlaute zu entfernen...
            $description = $this->ArticlesGateway->limitString($description, 160, '...');
            $description = str_ireplace($query, '<span class="highlight">' . $query . '</span>', $description);
            $r['ShortDescription'] = $description;

            $ret['result'][] = $r;
        }

        return $ret;

    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $app = array(
            'params' => $this->params,
            'route' => $this->route,
            'tracker' => $this->tracker,
            'request' => $request
        );
        Application::get('twig')->addGlobal('app', $app);

        $data = $this->preparePageData();

        if (is_object($data))
            return $data;

        if (isset($_GET['q']))      // TODO $qa = $request->getQueryParams();
            $q = htmlspecialchars($_GET['q'], ENT_QUOTES, 'UTF-8');
        else
            $q = '';

        $serp = $this->dosearch($q);

        $data['search'] = $serp;

        return HtmlResponse::create($data, 200, $this->getSecurityHeaders());

    }

}

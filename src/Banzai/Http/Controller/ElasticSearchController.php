<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

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
            $container->get(ArticlesGateway::class)
        );
    }

    public function __construct(LoggerInterface          $logger,
                                Config                   $params,
                                RouteInterface           $route,
                                NavigationGateway        $NavigationGateway,
                                ClientTracker            $tracker,
                                protected ElasticService $elastic,
                                FoldersGateway           $FoldersGateway,
                                ArticlesGateway          $ArticlesGateway)
    {
        parent::__construct($logger, $params, $route, $NavigationGateway, $tracker, $FoldersGateway, $ArticlesGateway);
    }


    function limit_string(string $stri, int $maxlen, string $pofi = '')
    {
        if (iconv_strlen($stri) <= $maxlen)
            return $stri;

        $a = explode(' ', $stri);
        if (!is_array($a))
            return $stri;

        $maxlen = $maxlen - iconv_strlen($pofi);

        $zaehl = 0;
        $spack = '';
        $resi = '';

        foreach ($a as $zeile) {
            $zeile = trim($zeile);
            $lenw = iconv_strlen($zeile);
            if ($lenw == 0)
                continue;

            if ($maxlen < $zaehl + $lenw)
                return ($resi . $pofi);
            $resi = $resi . $spack . $zeile;
            $zaehl = $zaehl + $lenw + 1;
            $spack = ' ';
        }
        return $resi;
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
            $r['ShortTitle'] = $this->limit_string($title, 60, '...');

            $description = $entry['_source']['ContentText'];
            $description = html_entity_decode($description, ENT_COMPAT, 'UTF-8');    // nochmal, um Unlaute zu entfernen...
            $description = $this->limit_string($description, 160, '...');
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

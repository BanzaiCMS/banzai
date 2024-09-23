<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Flux\Config\Config;
use Flux\Container\ContainerInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Blocks\BlocksGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Http\RedirectResponse;
use Banzai\Http\RequestInterface;
use Banzai\Http\ResponseInterface;
use Banzai\Http\Routing\RouteInterface;
use Banzai\Http\Tracker\ClientTracker;
use Banzai\Navigation\NavigationGateway;

abstract class AbstractController implements ControllerInterface
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
            $container->get(FoldersGateway::class),
            $container->get(ArticlesGateway::class),
            $container->get(BlocksGateway::class)
        );
    }

    public function __construct(protected LoggerInterface   $logger,
                                protected Config            $params,
                                protected RouteInterface    $route,
                                protected NavigationGateway $NavigationGateway,
                                protected ClientTracker     $tracker,
                                protected FoldersGateway    $FoldersGateway,
                                protected ArticlesGateway   $ArticlesGateway,
                                protected BlocksGateway     $BlocksGateway)
    {

    }

    public function getHtmlHeaderFieldsFromArticle(array $art): string
    {

        $ret = array();

        if (!empty($art['description']))
            $ret['description'] = $art['description'];

        if (!empty($art['meta_abstract']))
            $ret['abstract'] = $art['meta_abstract'];

        if (!empty($art['metakeywords']))
            $ret['keywords'] = $art['metakeywords'];

        if (!empty($art['metapagetype']))
            $ret['page-type'] = $art['metapagetype'];

        if (!empty($art['metapagetopic']))
            $ret['page-topic'] = $art['metapagetopic'];

        if (!empty($art['meta_author']))
            $ret['author'] = $art['meta_author'];

        if (!empty($art['meta_publisher']))
            $ret['publisher'] = $art['meta_publisher'];

        if (!empty($art['meta_company']))
            $ret['company'] = $art['meta_company'];

        if (!empty($art['meta_subject']))
            $ret['subject'] = $art['meta_subject'];

        if (!empty($art['geo'])) {
            $geo = $art['geo'];

            if ((!empty($geo['countrycode'])) && (!empty($geo['regioncode'])))
                $ret['geo.region'] = $geo['countrycode'] . '-' . $geo['regioncode'];

            if (!empty($geo['placename']))
                $ret['geo.placename'] = $geo['placename'];

            $ret['geo.position'] = $geo['lat'] . ';' . $geo['lon'];

            $ret['ICBM'] = $geo['lat'] . ',' . $geo['lon'];

        }

        $s = '';
        foreach ($ret as $name => $value)
            $s .= '<meta name="' . $name . '" content="' . $value . '"/>' . "\n";

        // user defined meta header, remove possible trailing crlf from input field and always add a final lf
        if (!empty($art['extraheaderline']))
            $s .= trim($art['extraheaderline']) . "\n";

        return $s;

    }

    protected function preparePageData(): array|ResponseInterface
    {
        $blocks = array();

        $type = $this->route->getContentType();

        if ($type == 'folder') {
            $cat = $this->FoldersGateway->getFolder($this->route->getContentID());

            if (empty($cat)) {
                return RedirectResponse::create('/');   // no folder found, this should not happen here
            }

            $aid = $this->route->getContentIndexID();
            if ($aid < 1) {
                $art = array();
            } else {
                $art = $this->ArticlesGateway->getArticle($aid);
                $art = $this->ArticlesGateway->transformArticleToShow($art);

                $blocks = $this->BlocksGateway->createBlocksTree($aid);

            }

            if (empty($art)) {  // if we do not have an asssigned index article, we can not show any page
                return RedirectResponse::create('/');
            }

            $nav = $this->NavigationGateway->createNavigation($this->route->getContentID(), $this->route);
            $teaser = $this->NavigationGateway->createTeaser($this->route->getContentID(), $this->route->getPath());

            $this->tracker->setItemID($this->route->getContentID());
            $this->tracker->setItemType($type);                // here: "folder", in legacy cms "category"
            $this->tracker->setItemName($cat['categories_pagetitle']);

        } elseif ($type == 'article') {

            $cat = $this->FoldersGateway->getFolder($this->route->getParentID());
            $art = $this->ArticlesGateway->getArticle($this->route->getContentID());
            $art = $this->ArticlesGateway->transformArticleToShow($art);

            $nav = $this->NavigationGateway->createNavigation($this->route->getParentID(), $this->route);
            $teaser = $this->NavigationGateway->createTeaser($this->route->getParentID(), $this->route->getPath());

            $blocks = $this->BlocksGateway->createBlocksTree($this->route->getContentID());

            $this->tracker->setItemID($this->route->getContentID());
            $this->tracker->setItemType($type);
            $this->tracker->setItemName($art['pagetitle']);


        } else {
            return RedirectResponse::create('/');   // wrong type. this should not happen.
        }

        $logpixel = $this->tracker->getPixel();


        // Namen des Pagetemplates ermitteln
        if (empty($cat['layout_template']))
            $basetemplate = 'base.html.twig';
        else
            $basetemplate = $cat['layout_template'] . '.html.twig';

        // Namen des Content-Templates ermitteln
        if (empty($cat['content_template']))
            $cont_template = 'content.html.twig';
        else
            $cont_template = $cat['content_template'] . '.html.twig';

        if (!empty($art['layout_template'])) // content template wird überschrieben, ja, sehr unschoen, dass dieses "layout_template" heisst, und nicht "content_template"
            $cont_template = $art['layout_template'] . '.html.twig';

        // Name of article-template
        // folder-entry is only used, if not set in article

        if (!empty($art['object_template']))
            $articletemplate = $art['object_template'] . '.html.twig';
        else if (!empty($cat['sub_element_template']))
            $articletemplate = $cat['sub_element_template'] . '.html.twig';
        else
            $articletemplate = 'article.html.twig';

        $htmlheader = array();
        $htmlheader['metaheader'] = $this->getHtmlHeaderFieldsFromArticle($art);
        $htmlheader['language'] = 'de';
        $htmlheader['charset'] = 'UTF-8';
        $htmlheader['pagetitle'] = $art['pagetitle'];

        // if we have blocks, we pre-render them

        $data = array(
            'template' => $articletemplate,
            'basetemplate' => $basetemplate,
            'contenttemplate' => $cont_template,
            'header' => $htmlheader,
            'title' => $art['titel2'],
            'content' => $art['langtext'],       // here rendering of langtext
            'navigation' => $nav,
            'blocks' => $blocks,
            'teaser' => $teaser,
            'logpixel' => $logpixel,
            'folder' => $cat,
            'item' => $art);


        return $data;

    }

    abstract public function handle(RequestInterface $request): ResponseInterface;


}

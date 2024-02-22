<?php
declare(strict_types=1);

namespace Banzai\Http\Controller;

use Banzai\Core\Application;
use Banzai\Http\RequestInterface;
use Banzai\Http\HtmlResponse;
use Banzai\Http\ResponseInterface;

class GlossaryController extends AbstractController implements ControllerInterface
{
    use ControllerTrait;

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


        $type = $this->route->getContentType();

        // a Glossary Page can be a Folder (with an index-Article) or an Article

        if ($type == 'folder') {
            $FolderID = $this->route->getContentID();
        } else {
            $FolderID = $this->route->getParentID();
        }

        $navparam = array(
            'rootfolderid' => 0,
            'prependparentfolder' => 'no',
            'expandallsubfolders' => 'yes',
            'flatlist' => 'no',
            'depth' => 0,
            'id' => 0
        );

        $navg = $this->NavigationGateway->createNavigationwithParams($navparam, $FolderID, $this->route->getPath(), OnlyNavElements: false);

        $glossary = array();
        foreach ($navg as $nav) {
            $c = strtolower(substr($nav['navtitle'], 0, 1));
            if (isset($glossary[$c]['list'])) {
                $glossary[$c]['list'][] = $nav;
            } else {
                $glossary[$c] = array('fragment' => $c, 'headline' => strtoupper($c), 'list' => array($nav));
            }
        }

        $data['glossary'] = $glossary;

        return HtmlResponse::create($data, 200, $this->getSecurityHeaders());

    }

}

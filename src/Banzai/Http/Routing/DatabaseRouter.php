<?php
declare(strict_types=1);

namespace Banzai\Http\Routing;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;
use Banzai\Http\Controller\FileController;
use Banzai\Http\Controller\PageController;
use Banzai\Http\Controller\PictureController;
use Banzai\Http\Controller\ProductController;
use Banzai\Http\Controller\VideoController;
use Banzai\Http\RequestInterface;

class DatabaseRouter implements RouteProviderInterface
{
    public const string ROUTES_PATH_TABLE = 'pathroutes';

    function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }

    public function deleteAllRoutes(): void
    {
        $this->db->statement('TRUNCATE TABLE ' . self::ROUTES_PATH_TABLE);
    }

    public function getRouteAsArrayByID(?int $id): array
    {
        return $this->db->get('SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE routeid=?', array($id));
    }

    public function getRouteAsArrayByItem(string $itemtype, int $itemid): array
    {
        $bind = array('type' => $itemtype, 'itemid' => $itemid);
        $sql = 'SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE itemtype=:type AND itemid=:itemid';

        return $this->db->get($sql, $bind);
    }

    public function getRouteByName(?string $name): ?RouteInterface
    {
        if (is_null($name))
            return null;

        $route = $this->db->get('SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE routename=?', array($name));

        if (empty($route)) {
            return null;
        }

        if (empty($route['controller']))
            $route['controller'] = $this->setControllerClass($route);

        return new DatabaseRoute($this->db, $route);
    }

    public function getRouteByPath(?string $path): ?RouteInterface
    {
        if (is_null($path))
            return null;

        $sql = 'SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE fullpath=?';
        $route = $this->db->get($sql, array($path));

        if (empty($route)) {
            return null;
        }

        if (empty($route['controller']))
            $route['controller'] = $this->setControllerClass($route);

        return new DatabaseRoute($this->db, $route);
    }


    public function getRouteByRequest(RequestInterface $request, bool $OnlyPublished = true): ?RouteInterface
    {
        $sql = 'SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE fullpath=?';

        if ($OnlyPublished)
            $sql .= " AND published='yes'";

        $route = $this->db->get($sql, array($request->getAbsolutePath()));

        if (empty($route)) {
            return null;
        }

        if (empty($route['controller']))
            $route['controller'] = $this->setControllerClass($route);

        return new DatabaseRoute($this->db, $route);
    }

    public function getRouteByItem(string $itemtype, int $itemid): ?RouteInterface
    {
        $bind = array('type' => $itemtype, 'itemid' => $itemid);

        $sql = 'SELECT * FROM ' . self::ROUTES_PATH_TABLE . ' WHERE itemtype=:type AND itemid=:itemid';
        $route = $this->db->get($sql, $bind);

        if (empty($route)) {
            return null;
        }

        if (empty($route['controller']))
            $route['controller'] = $this->setControllerClass($route);

        return new DatabaseRoute($this->db, $route);
    }

    public function getRouteIDByItem(string $itemtype, int $itemid): ?int
    {
        $bind = array('type' => $itemtype, 'itemid' => $itemid);

        $sql = 'SELECT routeid FROM ' . self::ROUTES_PATH_TABLE . ' WHERE itemtype=:type AND itemid=:itemid';
        $route = $this->db->get($sql, $bind);

        if (empty($route))
            return null;
        else
            return $route['routeid'];
    }

    public function delRouteByItem(string $itemtype, int $itemid): bool
    {

        $route = $this->db->get('SELECT routeid FROM ' . self::ROUTES_PATH_TABLE . ' WHERE itemid=? AND itemtype=?', array($itemid, $itemtype));

        if (empty($route))
            return false;

        return $this->db->del(self::ROUTES_PATH_TABLE, array('routeid' => $route['routeid']));

    }

    public function createRouteByItem(string $itemtype, int $itemid): RouteInterface
    {

        // we only set the defaults here that should be overwritten in paths-database if they are not explicitly set in the route object before storing
        $r = array('itemtype' => strtolower($itemtype), 'itemid' => $itemid);

        $r['navtitle'] = null;
        $r['etag'] = null;
        $r['controller'] = null;
        $r['routename'] = null;
        $r['navdate'] = null;
        $r['navstyle'] = null;
        $r['sortvalue'] = 0;
        $r['published'] = null;
        $r['activefrom'] = null;
        $r['activeuntil'] = null;
        $r['navid'] = 0;
        $r['permcode'] = null;
        $r['innav'] = 'yes';
        $r['inteaser'] = 'yes';

        $r['controller'] = $this->setControllerClass($r);
        return new DatabaseRoute($this->db, $r);

    }

    protected function setControllerClass(array $route): string
    {
        return match ($route['itemtype']) {
            'file' => FileController::class,
            'picture' => PictureController::class,
            'video' => VideoController::class,
            'product' => ProductController::class,
            default => PageController::class
        };

    }
}

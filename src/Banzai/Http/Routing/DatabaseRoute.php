<?php
declare(strict_types=1);

namespace Banzai\Http\Routing;

use Flux\Database\DatabaseInterface;

class DatabaseRoute implements RouteInterface
{
    public function __construct(protected DatabaseInterface $db, protected array $property)
    {

    }

    public function getID(): int
    {
        return $this->property['routeid'];
    }

    public function getControllerClassname(): string
    {
        return $this->property['controller'];
    }

    public function getContentID(): int
    {
        return $this->property['itemid'];
    }

    public function getParentID(): int
    {
        return $this->property['parentid'];
    }

    public function getContentType(): string
    {
        return $this->property['itemtype'];
    }

    public function getContentIndexID(): int
    {
        return $this->property['indexitemid'];
    }

    public function getPath(): string
    {
        return $this->property['fullpath'];
    }

    public function requiresPermission(): bool
    {
        return !empty($this->property['permcode']);
    }

    public function requiredPermission(): ?string
    {
        return $this->property['permcode'];
    }

    public function isPublished(): bool
    {
        return $this->property['published'] == 'yes';
    }

    public function getEtag(): ?string
    {
        return $this->property['etag'];
    }

    public function hasEtag(string $etag): bool
    {
        if (empty($etag) || empty($this->property['etag']))
            return false;

        return strcasecmp($this->property['etag'], $etag) == 0;
    }


    public function getNotfoundType(): int
    {
        return match ($this->property['notfoundtype']) {
            self::NotFoundRedirect302 => self::NotFoundRedirect302,
            self::NotfoundCallFolder => self::NotfoundCallFolder,
            self::NotFoundShow404 => self::NotFoundShow404,
            default => self::NotFoundRedirect301
        };

    }


    public function setItemID(int $ItemID): self
    {
        $this->property['itemid'] = $ItemID;
        return $this;

    }


    public function setParentID(int $ParentID): self
    {
        $this->property['parentid'] = $ParentID;
        return $this;
    }

    public function setIndexID(?int $IndexID): self
    {
        $this->property['indexitemid'] = $IndexID;
        return $this;
    }

    public function setFullPath(?string $FullPath): self
    {
        $this->property['fullpath'] = $FullPath;
        return $this;
    }

    public function setItemPath(?string $ItemPath): self
    {
        $this->property['itempath'] = $ItemPath;

        return $this;
    }

    public function setPageController(?string $PageController): self
    {
        if (empty($PageController))
            $this->property['controller'] = null;
        else
            $this->property['controller'] = $PageController;

        return $this;
    }

    public function setRouteName(?string $Routename): self
    {
        if (empty($Routename))
            $this->property['routename'] = null;
        else
            $this->property['routename'] = $Routename;

        return $this;
    }

    public function setNavTitle(?string $NavTitle): self
    {
        if (empty($NavTitle))
            $this->property['navtitle'] = null;
        else
            $this->property['navtitle'] = $NavTitle;

        return $this;
    }

    public function setSortValue(?int $SortValue): self
    {
        if (is_null($SortValue))
            $this['sortvalue'] = 0;
        else
            $this->property['sortvalue'] = $SortValue;

        return $this;
    }

    public function setSortType(?string $SortType): self
    {
        $this->property['sorttype'] = $SortType;
        return $this;
    }

    public function setSortOrder(?string $SortOrder): self
    {
        $this->property['sortdirection'] = $SortOrder;
        return $this;
    }

    public function setNotFoundType(?int $NotFoundType): self
    {
        if (!is_null($NotFoundType))
            $this->property['notfoundtype'] = $NotFoundType;
        return $this;
    }

    public function setNavDate(?string $NavDate): self
    {
        if (empty($NavDate))
            $this->property['navdate'] = null;
        else
            $this->property['navdate'] = $NavDate;

        return $this;
    }

    public function setisPublished(?bool $isPublished): self
    {
        if (is_null($isPublished))
            $this->property['published'] = null;
        elseif ($isPublished)
            $this->property['published'] = 'yes';
        else
            $this->property['published'] = 'no';

        return $this;
    }

    public function setActiveFrom(?string $ActiveFrom): self
    {
        if (is_null($ActiveFrom) || (strncmp($ActiveFrom, '0000', 4) == 0))
            $this->property['activefrom'] = null;
        else
            $this->property['activefrom'] = $ActiveFrom;

        return $this;
    }

    public function setActiveUntil(?string $ActiveUntil): self
    {
        if (is_null($ActiveUntil) || (strncmp($ActiveUntil, '0000', 4) == 0))
            $this->property['activeuntil'] = null;
        else
            $this->property['activeuntil'] = $ActiveUntil;

        return $this;
    }

    public function setNavID(?int $NavID): self
    {
        if (is_null($NavID))
            $this->property['navid'] = 0;
        else
            $this->property['navid'] = $NavID;

        return $this;
    }

    public function setPermissionCode(?string $PermissionCode): self
    {
        if (empty($PermissionCode))
            $this->property['permcode'] = null;
        else
            $this->property['permcode'] = $PermissionCode;

        return $this;
    }

    public function setNavStyle(?string $NavStyle): self
    {
        if (empty($NavStyle))
            $this->property['navstyle'] = null;
        else
            $this->property['navstyle'] = $NavStyle;

        return $this;
    }

    public function setEtag(?string $ETag): self
    {
        if (empty($Etag))
            $this->property['etag'] = null;
        else
            $this->property['etag'] = $Etag;

        return $this;
    }

    public function setVisibleinNav(bool $inNav = true): self
    {
        if ($inNav)
            $this->property['innav'] = 'yes';
        else
            $this->property['innav'] = 'no';
        return $this;
    }

    public function setVisibleinTeaser(bool $inTeaser = true): self
    {
        if ($inTeaser)
            $this->property['inteaser'] = 'yes';
        else
            $this->property['inteaser'] = 'no';

        return $this;
    }


    public function save(): self
    {

        // routeid as primary key is set
        if ((isset($this->property['routeid'])) && ($this->property['routeid'] > 0)) {
            $this->db->put(DatabaseRouter::ROUTES_PATH_TABLE, $this->property, array('routeid'), false);
            return $this;
        }

        // we check, if we have an entry for this item and get the primary key,routeid
        $route = $this->db->get('SELECT routeid FROM ' . DatabaseRouter::ROUTES_PATH_TABLE . ' WHERE itemid=? AND itemtype=?', array($this->property['itemid'], $this->property['itemtype']));

        if (empty($route)) {    // we have to add the route
            $this->property['routeid'] = $this->db->add(DatabaseRouter::ROUTES_PATH_TABLE, $this->property);
        } else {            // we update the route
            $this->property['routeid'] = $route['routeid'];
            $this->db->put(DatabaseRouter::ROUTES_PATH_TABLE, $this->property, array('routeid'), false);
        }

        return $this;

    }

}

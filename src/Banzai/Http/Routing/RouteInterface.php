<?php
declare(strict_types=1);

namespace Banzai\Http\Routing;

interface RouteInterface
{
    const   NotFoundRedirect301 = 1;
    const   NotFoundRedirect302 = 2;
    const   NotfoundCallFolder = 3;
    const   NotFoundShow404 = 4;

    // 'showindex', 'show404', 'redir301', 'redir302'

    public function getID(): int;

    public function getControllerClassname(): string;

    public function getContentID(): int;

    public function getParentID(): int;

    public function getContentType(): string;

    public function getContentIndexID(): int;

    public function getPath(): string;

    public function requiresPermission(): bool;

    public function requiredPermission(): ?string;

    public function isPublished(): bool;

    public function getEtag(): ?string;

    public function hasEtag(string $etag): bool;

    public function getNotfoundType(): int;

    public function setItemID(int $ItemID): self;

    public function setParentID(int $ParentID): self;

    public function setIndexID(?int $IndexID): self;

    public function setFullPath(?string $FullPath): self;

    public function setItemPath(?string $ItemPath): self;

    public function setPageController(?string $PageController): self;

    public function setRouteName(?string $Routename): self;

    public function setNavTitle(?string $NavTitle): self;

    public function setSortValue(?int $SortValue): self;

    public function setSortType(?string $SortType): self;

    public function setSortOrder(?string $SortOrder): self;

    public function setNotFoundType(?int $NotFoundType): self;

    public function setNavDate(?string $NavDate): self;

    public function setisPublished(?bool $isPublished): self;

    public function setActiveFrom(?string $ActiveFrom): self;

    public function setActiveUntil(?string $ActiveUntil): self;

    public function setNavID(?int $NavID): self;

    public function setPermissionCode(?string $PermissionCode): self;

    public function setNavStyle(?string $NavStyle): self;

    public function setEtag(?string $ETag): self;

    public function setVisibleinNav(bool $inNav = true): self;

    public function setVisibleinTeaser(bool $inTeaser = true): self;

    public function save(): self;

}

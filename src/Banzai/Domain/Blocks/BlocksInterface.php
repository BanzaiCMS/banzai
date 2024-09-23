<?php
declare(strict_types=1);

namespace Banzai\Domain\Blocks;

interface BlocksInterface
{
    public function getBlocktypeNames(): array;

    public function getNodeBlockNames(): array;

}

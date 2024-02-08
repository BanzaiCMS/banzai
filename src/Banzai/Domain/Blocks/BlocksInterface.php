<?php

namespace Banzai\Domain\Blocks;

interface BlocksInterface
{
    public function getBlocktypeNames(): array;

    public function getNodeBlockNames(): array;

}

<?php
declare(strict_types=1);

namespace Banzai\Domain\Products;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;

/**
 * Class ProductsGateway
 * @package Banzai\Domain\Products
 */
class ProductsGateway
{
    const PRODUCTS_TABLE = 'products';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }


}

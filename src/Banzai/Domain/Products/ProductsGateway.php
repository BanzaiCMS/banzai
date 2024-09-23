<?php
declare(strict_types=1);

namespace Banzai\Domain\Products;

use Flux\Database\DatabaseInterface;
use Flux\Logger\LoggerInterface;

class ProductsGateway
{
    const string PRODUCTS_TABLE = 'products';

    public function __construct(protected DatabaseInterface $db, protected LoggerInterface $logger)
    {
    }


}

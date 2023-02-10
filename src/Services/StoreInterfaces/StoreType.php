<?php

namespace TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces;

use App\Services\BaseStoreInterface as ServicesBaseStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\ShopifyStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\BaseStoreInterface;

enum StoreType
{
    case Shopify;

    public function getInterface(): string
    {
        return match ($this) {
            self::Shopify => ShopifyStoreInterface::class
        };
    }
}

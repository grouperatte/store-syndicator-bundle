<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

enum StoreType
{
    case Shopify;

    public function getInterface(): string
    {
        return match ($this) {
            self::Shopify => ShopifyStore::class
        };
    }
}

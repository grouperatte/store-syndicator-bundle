<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Pimcore\Bundle\DataHubBundle\Configuration;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreType;

class StoreFactory
{
    public static function getStore(Configuration $config): StoreInterface
    {
        //very temporary until users can choose the store type
        $storetype = StoreType::Shopify;
        $storetype = $storetype->getInterface();
        $store = new $storetype();
        $store->setup($config);
        return $store;
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreType;

class StoreFactory
{
    public static function getStore(array $config): StoreInterface
    {
        //very temporary until users can choose the store type
        $storetype = StoreType::Shopify;
        $storetype = $storetype->getInterface();
        $store = new $storetype();
        $store->setup($config);
        return $store;
    }
}

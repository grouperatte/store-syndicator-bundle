<?php

namespace TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces;

use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\BaseStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\ShopifyStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\StoreType;

class StoreInterfaceFactory
{
    public static function getExportUtil(array $config): BaseStoreInterface
    {
        //very temporary until users can choose the store type
        $storetype = StoreType::Shopify;
        $storetype = $storetype->getInterface();
        return new $storetype($config);
    }
}

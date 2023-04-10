<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Configuration;

use Pimcore\Db;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\TorqStoreExporterShopifyCredentials;

class ConfigurationRepository
{
    public function __construct()
    {
    }

    /**
     * @return Configuration[]
     **/
    public function getSameStoreConfigurations(Configuration $configuration): array
    {
        $configArray = $configuration->getConfiguration();
        if (array_key_exists("APIAccess", $configArray) && count($configArray["APIAccess"]) > 0) {
            $matchPath = $configArray["APIAccess"][0]['cpath'];
        } else {
            return []; //this config doesnt have apiAccess filled in to match on
        }
        $returnConfigs = [];
        foreach (Configuration::getList() as $config) {
            $configArray = $config->getConfiguration();
            if (array_key_exists("APIAccess", $configArray) && count($configArray["APIAccess"]) > 0) {
                if ($matchPath == $configArray["APIAccess"][0]['cpath']) {
                    $returnConfigs[] = $config;
                }
            }
        }
        return $returnConfigs;
    }
}

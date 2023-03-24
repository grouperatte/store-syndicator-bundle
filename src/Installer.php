<?php

namespace TorqIT\StoreSyndicatorBundle;

use Doctrine\DBAL\Connection;
use Pimcore\Model\User\Permission;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Service;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Pimcore\Extension\Bundle\Installer\AbstractInstaller;
use Pimcore\Bundle\DataHubBundle\Installer as PimInstaller;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class Installer extends SettingsStoreAwareInstaller
{
    const DATAHUB_ADAPTER_PERMISSION = 'plugin_datahub_adapter_storeSyndicatorDataObject';
    private array $installMap;

    public function __construct(
        BundleInterface $bundle,
        Connection $connection
    ) {
        $this->bundle = $bundle;
        $baseDir = __DIR__ . '/Resources/install_classes/';
        $this->installMap = [[
            "key" => "tse_shopify_creds",
            "name" => "TorqStoreExporterShopifyCredentials",
            "directory" => $baseDir . "class_TorqStoreExporterShopifyCredentials_export.json"
        ]];

        parent::__construct($bundle);
    }

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }


    public function install(): void
    {
        foreach ($this->installMap as $map) {
            $file = $map['directory'];
            $key = $map["key"];
            $name = $map["name"];

            $classDef = new ClassDefinition();
            $classDef->setName($name);
            $classDef->setId($key);
            Service::importClassDefinitionFromJson($classDef, file_get_contents($file));
        }

        // create backend permission
        Permission\Definition::create(self::DATAHUB_ADAPTER_PERMISSION)
            ->setCategory(PimInstaller::DATAHUB_PERMISSION_CATEGORY)
            ->save();

        parent::install();
    }
}

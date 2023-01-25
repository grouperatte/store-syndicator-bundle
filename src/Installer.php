<?php

namespace TorqIT\StoreSyndicatorBundle;

use Pimcore\Model\User\Permission;
use Pimcore\Bundle\DataHubBundle\Installer as PimInstaller;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;

class Installer extends SettingsStoreAwareInstaller
{

    const DATAHUB_ADAPTER_PERMISSION = 'plugin_datahub_adapter_storeSyndicatorDataObject';

    public function needsReloadAfterInstall(): bool
    {
        return true;
    }

    public function install(): void
    {
        // create backend permission
        Permission\Definition::create(self::DATAHUB_ADAPTER_PERMISSION)
            ->setCategory(PimInstaller::DATAHUB_PERMISSION_CATEGORY)
            ->save();

        parent::install();
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle;

use League\FlysystemBundle\FlysystemBundle;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;

class StoreSyndicatorBundle extends AbstractPimcoreBundle implements PimcoreBundleAdminClassicInterface
{
    use PackageVersionTrait;

    const LOGGER_COMPONENT_PREFIX = 'STORE_SYNDICATOR ';

    public function getEditmodeJsPaths(): array { return []; }

    public function getEditmodeCssPaths(): array { return []; }

    protected function getComposerPackageName(): string
    {
        return 'torqit/store-syndicator-bundle';
    }

    /**
     * @return string[]
     */
    public function getCssPaths(): array
    {
        return ['/bundles/storesyndicator/css/icons.css'];
    }

    /**
     * @return string[]
     */
    public function getJsPaths(): array
    {
        return [
            '/bundles/storesyndicator/js/pimcore/startup.js',
            '/bundles/storesyndicator/js/pimcore/adapter/storeExporterDataObject.js',
            '/bundles/storesyndicator/js/pimcore/configuration/configItemDataObject.js',
            '/bundles/storesyndicator/js/pimcore/helpers/objectTree.js',
            '/bundles/storesyndicator/js/pimcore/helpers/workspacePicker.js',
            '/bundles/storesyndicator/js/pimcore/helpers/APIObjectsPicker.js',
            '/bundles/storesyndicator/js/pimcore/configuration/components/admin.js',
            '/bundles/storesyndicator/js/pimcore/configuration/components/logTab.js',
        ];
    }

    public function build(ContainerBuilder $container): void
    {
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        $collection->addBundle(new FlysystemBundle());
        if (\Pimcore\Version::getMajorVersion() >= 11) {
            $collection->addBundle(\Pimcore\Bundle\ApplicationLoggerBundle\PimcoreApplicationLoggerBundle::class);
        }
    }

    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }
}

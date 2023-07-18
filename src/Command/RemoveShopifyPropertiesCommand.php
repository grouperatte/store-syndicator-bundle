<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Model\DataObject;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use \Pimcore\Cache;

class RemoveShopifyPropertiesCommand extends AbstractCommand
{
    public function __construct(private ConfigurationService $configurationService)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('torq:remove-store-properties')
            ->addArgument('store-name', InputArgument::REQUIRED)
            ->setDescription('removes any properties related to the imput argument configuration. unlinking the products from the store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument("store-name");

        $config = Configuration::getByName($name);
        $remoteStoreName = $this->configurationService->getStoreName($config);
        $shopifyIdPropertyName = "TorqSS:" . $remoteStoreName . ":shopifyId";
        $linkedPropertyName = "TorqSS:" . $remoteStoreName . ":linked";
        $remoteLastUpdatedProperty = "TorqSS:" . $remoteStoreName . ":lastUpdated";
        $db = Db::get();

        $result = $db->executeStatement('Delete from properties where name IN (?, ?, ?, ?)', [$shopifyIdPropertyName, $linkedPropertyName, 'ShopifyImageURL', $remoteLastUpdatedProperty]);
        Cache::clearAll();
        return 0;
    }
}

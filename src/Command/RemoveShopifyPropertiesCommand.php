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

class RemoveShopifyPropertiesCommand extends AbstractCommand
{

    protected function configure()
    {
        $this
            ->setName('torq:remove-shopify-properties')
            ->setDescription('Do Shopify Stuff');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = Db::get();

        $db->query('Delete from properties where name IN (?, ?)', ['ShopifyProductId', 'ShopifyImageURL']);
        return 0;
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Db;
use Pimcore\Console\AbstractCommand;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyProductLinkingService;

class LinkShopifyProductsCommand extends AbstractCommand
{
    public function __construct(private ShopifyProductLinkingService $shopifyProductLinkingService)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->setName('torq:link-shopify-products')
            ->addArgument('store-name', InputArgument::REQUIRED)
            ->setDescription('Do Shopify Stuff');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Db::get()->query('SET SESSION wait_timeout = ' . 28800); //timeout to 8 hours for this session
        $name = $input->getArgument("store-name");

        $config = Configuration::getByName($name);
        $this->shopifyProductLinkingService->link($config);
        return 0;
    }
}

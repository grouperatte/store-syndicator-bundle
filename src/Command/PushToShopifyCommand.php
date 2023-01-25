<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Config;
use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use \Pimcore\Model\Asset;
use \Pimcore\Model\Document;
use \Pimcore\Model\DataObject;
use \Shopify\Context;
use \Shopify\Auth\FileSessionStorage;
use \Shopify\Clients\Rest;
use Shopify\Rest\Admin2022_07\Metafield;

class PushToShopifyCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('torq:push-to-shopify')
            ->setDescription('Do Shopify Stuff');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        Context::initialize(
            '9632c9dce96a6aa6f8408aad3bd6009d',
            'de813dac03d6fb78c3ed21a2dc065967',
            ["read_products","write_products"],
            "mighty-spruce.myshopify.com",
            new FileSessionStorage('/tmp/php_sessions')
        );

        $client = new Rest("mighty-spruce.myshopify.com", "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");
        $response = $client->get('metafields', [], ["metafield"=>["owner_id" => "7585995653308", "owner_resource" => "product"]]);

        $yee = json_encode($response->getDecodedBody(),JSON_PRETTY_PRINT);

        $output->writeln($yee);

        return 0;
    }
}
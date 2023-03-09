<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Model\DataObject;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveShopifyPropertiesCommand extends AbstractCommand
{

    protected function configure()
    {
        $this
            ->setName('torq:remove-shopify-properties')
            ->addArgument('store-name', InputArgument::REQUIRED)
            ->setDescription('Do Shopify Stuff');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument("store-name");

        $config = Configuration::getByName($name);

        $productPaths = $config["products"]["products"];
        $classType = $config["products"]["class"];

        $classType = "Pimcore\\Model\\DataObject\\" . $classType;
        foreach ($productPaths as $pathArray) {
            $path = $pathArray["cpath"];
            $products = DataObject::getByPath($path);
            $products = $products->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);
            foreach ($products as $product) {
                $this->recursiveRemove($product, $classType);
            }
        }
        return 0;
    }

    private function recursiveRemove($dataObject, $classType)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $classType)) {
            $dataObject->removeProperty('ShopifyProductId');
            foreach ($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true) as $childVariant) {
                $childVariant->removeProperty('ShopifyProductId');
            }
        }

        $products = $dataObject->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);

        foreach ($products as $product) {
            $this->recursiveRemove($product, $classType);
        }
    }
}

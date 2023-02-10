<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\BaseStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\StoreInterfaceFactory;


class ExecutionService
{
    private array $config;
    private string $classType;
    private BaseStoreInterface $storeInterface;

    public function __construct()
    {
        # code...
    }

    public function export(array $config)
    {
        $this->config = $config;
        $this->storeInterface = StoreInterfaceFactory::getExportUtil($this->config);

        $productPaths = $this->config["products"]["products"];
        $this->classType = $this->config["products"]["class"];

        $this->classType = "Pimcore\\Model\\DataObject\\" . $this->classType;
        foreach ($productPaths as $pathArray) {
            $path = $pathArray["cpath"];
            $products = DataObject::getByPath($path);
            $products = $products->getChildren();
            foreach ($products as $product) {
                $this->recursiveExport($product);
            }
        }
        $this->storeInterface->commit();
    }

    private function recursiveExport($dataObject)
    {
        $this->push($dataObject);
        $products = $dataObject->getChildren();
        foreach ($products as $product) {
            $this->recursiveExport($product);
        }
    }

    private function push($object)
    {
        if (!(is_a($object, $this->classType))) return;

        $this->storeInterface->createOrUpdateProduct($object);
    }
}

<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Model\DataObject;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreFactory;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreInterface;

/*
    Gets the correct StoreInterface from the config file.

    It then gets all the paths from the config, and calls export on the paths.
*/
class ExecutionService
{
    private array $config;
    private string $classType;
    private StoreInterface $storeInterface;

    public function __construct()
    {
        # code...
    }

    public function export(array $config)
    {
        $this->config = $config;
        $this->storeInterface = StoreFactory::getStore($this->config);

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
        if (is_a($dataObject, $this->classType)){
            if(!$this->storeInterface->existsInStore($dataObject)){
                $this->storeInterface->createProduct($dataObject);
            }
            else{
                $this->storeInterface->updateProduct($dataObject);
            }
        }

        $products = $dataObject->getChildren();

        foreach ($products as $product) {
            $this->recursiveExport($product);
        }
    }
}

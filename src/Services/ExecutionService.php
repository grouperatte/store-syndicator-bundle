<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
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
            $products = $products->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);
            foreach ($products as $product) {
                $this->recursiveExport($product);
            }
        }

        return $this->storeInterface->commit();
    }

    private function recursiveExport($dataObject)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            if (!$this->storeInterface->existsInStore($dataObject)) {
                $this->storeInterface->createProduct($dataObject);
            } else {
                $this->storeInterface->updateProduct($dataObject);
            }
            foreach ($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true) as $childVariant) {
                $this->storeInterface->processVariant($dataObject, $childVariant);
            }
        }

        $products = $dataObject->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);

        foreach ($products as $product) {
            $this->recursiveExport($product);
        }
    }
}

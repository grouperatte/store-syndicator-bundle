<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Bundle\DataHubBundle\Configuration;
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
    private Configuration $config;
    private string $classType;
    private StoreInterface $storeInterface;

    public function __construct()
    {
        # code...
    }

    public function export(Configuration $config)
    {
        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $this->storeInterface = StoreFactory::getStore($this->config);

        $productPaths = $configData["products"]["products"];
        $this->classType = $configData["products"]["class"];

        $this->classType = "Pimcore\\Model\\DataObject\\" . $this->classType;

        $rejects = []; //array of products we cant export
        foreach ($productPaths as $pathArray) {
            $path = $pathArray["cpath"];
            $products = DataObject::getByPath($path);
            $products = $products->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);
            foreach ($products as $product) {
                $this->recursiveExport($product, $rejects);
            }
        }
        $results = $this->storeInterface->commit();
        $results->addError("products with over 100 variants: " . json_encode($rejects));
        return $results;
    }

    private function recursiveExport($dataObject, &$rejects)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            if (count($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true)) > 100) {
                $rejects[] = $dataObject->getId();
            } else {
                if (!$this->storeInterface->existsInStore($dataObject)) {
                    $this->storeInterface->createProduct($dataObject);
                } else {
                    $this->storeInterface->updateProduct($dataObject);
                }
                foreach ($dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true) as $childVariant) {
                    $this->storeInterface->processVariant($dataObject, $childVariant);
                }
            }
        }

        $products = $dataObject->getChildren([DataObject::OBJECT_TYPE_OBJECT, DataObject::OBJECT_TYPE_FOLDER], true);

        foreach ($products as $product) {
            $this->recursiveExport($product, $rejects);
        }
    }
}

<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Exception;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use TorqIT\StoreSyndicatorBundle\Services\Stores\BaseStore;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreFactory;
use TorqIT\StoreSyndicatorBundle\Services\Stores\StoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\CommitResult;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\LogRow;

/*
    Gets the correct StoreInterface from the config file.

    It then gets all the paths from the config, and calls export on the paths.
*/

class ExecutionService
{
    private Configuration $config;
    private string $classType;
    private BaseStore $storeInterface;

    public function __construct(ShopifyStore $storeInterface)
    {
        $this->storeInterface = $storeInterface;
    }

    public function export(Configuration $config)
    {
        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $this->storeInterface->setup($config);

        $configData["ExportLogs"] = [];
        $this->config->setConfiguration($configData);
        $this->config->save();

        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $this->classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());

        $productListing = $this->getClassListing($configData);

        $rejects = []; //array of products we cant export
        foreach ($productListing as $product) {
            if ($product) {
                $this->proccess($product, $rejects);
            }
        }
        $results = $this->storeInterface->commit();
        $results->addError(new LogRow("products not exported due to having over 100 variants", json_encode($rejects)));

        //save errors and logs
        $configData = $this->config->getConfiguration();
        foreach ($results->getErrors() as $error) {
            $configData["ExportLogs"][] = $error->generateRow();
        }
        foreach ($results->getLogs() as $log) {
            $configData["ExportLogs"][] = $log->generateRow();
        }
        $this->config->setConfiguration($configData);
        $this->config->save();

        return $results;
    }

    private function proccess($dataObject, &$rejects)
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
                    if ($this->storeInterface->existsInStore($childVariant)) {
                        $this->storeInterface->updateVariant($dataObject, $childVariant);
                    } else {
                        $this->storeInterface->createVariant($dataObject, $childVariant);
                    }
                }
            }
        }
    }

    private function getClassListing($configData): Dataobject\Listing
    {
        $sql = $configData["products"]["sqlCondition"];
        $listing = $this->classType . '\\Listing';
        $listing = new $listing();
        /** @var Dataobject\Listing $listing */
        $listing->setObjectTypes(['object']);
        $listing->setCondition($sql);
        return $listing;
    }
}

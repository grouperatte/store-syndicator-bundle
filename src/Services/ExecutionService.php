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
use Pimcore\Log\ApplicationLogger;
use Pimcore\Db;

/*
    Gets the correct StoreInterface from the config file.

    It then gets all the paths from the config, and calls export on the paths.
*/

class ExecutionService
{
    private Configuration $config;
    private string $classType;
    private string $configLogName;
    private int $totalProductsToCreate;
    private int $totalProductsToUpdate;
    private int $totalVariantsToCreate;
    private int $totalVariantsToUpdate;


    private BaseStore $storeInterface;

     /**
     * @var ApplicationLogger
     */
    protected ApplicationLogger $applicationLogger;

    public function __construct(ShopifyStore $storeInterface,  ApplicationLogger $applicationLogger)
    {
        $this->storeInterface = $storeInterface;
        $this->applicationLogger = $applicationLogger;
    }

    public function export(Configuration $config)
    {   
        $db = Db::get();
        $this->totalProductsToCreate = 0;
        $this->totalProductsToUpdate = 0;
        $this->totalVariantsToCreate = 0;
        $this->totalVariantsToUpdate = 0;

        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $this->storeInterface->setup($config);

        $configData["ExportLogs"] = [];
        $this->config->setConfiguration($configData);
        $this->config->save();

        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $this->classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());
        $this->configLogName = 'DATA-IMPORTER ' . $configData["general"]["name"];
        $result = $db->executeStatement('Delete from application_logs where component = ?', [$this->configLogName]);
        $this->applicationLogger->info("*Starting import*", [
            'component' => $this->configLogName,
            null,
        ]);
        $productListing = $this->getClassListing($configData);
        $this->applicationLogger->info("Processing " . count($productListing) . " products", [
            'component' => $this->configLogName,
            null,
        ]);
        $rejects = []; //array of products we cant export
        foreach ($productListing as $product) {
            if ($product) {
                $this->proccess($product, $rejects);
            }
        }
        $this->applicationLogger->info("Ready to create " .  $this->totalProductsToCreate . " products and " . $this->totalVariantsToCreate . " variants, and to update " . $this->totalProductsToUpdate . " products and " . $this->totalVariantsToUpdate . " variants", [
            'component' => $this->configLogName,
            null,
        ]);
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
        $this->applicationLogger->info("*End of import*", [
            'component' => $this->configLogName,
            null,
        ]);

        return $results;
    }

    private function proccess($dataObject, &$rejects)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            $variants = $dataObject->getChildren([Concrete::OBJECT_TYPE_VARIANT], true);
            $variantCount = count($variants);
            if ($variantCount > 100) {
                $rejects[] = $dataObject->getId();
                $this->applicationLogger->error("Product ".  $dataObject->getKey() ." not exported due to having over 100 variants", [
                    'component' => $this->configLogName,
                    null,
                ]);
            } else {
                if (!$this->storeInterface->existsInStore($dataObject)) {
                    $this->totalProductsToCreate++;
                    $this->storeInterface->createProduct($dataObject);
                } else {
                    $this->totalProductsToUpdate++;
                    $this->storeInterface->updateProduct($dataObject);
                }
               
                $this->applicationLogger->info("Processing " . $dataObject->getKey() . " and its " . $variantCount . " variants" , [
                    'component' => $this->configLogName,
                    null,
                ]);
                
                foreach ($variants as $childVariant) {
                    if ($this->storeInterface->existsInStore($childVariant)) {
                        $this->totalVariantsToUpdate++;
                        $this->storeInterface->updateVariant($dataObject, $childVariant);
                    } else {
                        $this->totalVariantsToCreate++;
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

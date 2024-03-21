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
use Pimcore\Log\ApplicationLogger;
use Pimcore\Db;
use \Pimcore\Cache;
use Carbon\Carbon;

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
    private int $totalStocksToUpdate;

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

        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $this->classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());

        $this->configLogName = 'STORE_SYNDICATOR ' . $configData["general"]["name"];
        $db->executeStatement('Delete from application_logs where component = ?', [$this->configLogName]);
        
        $this->applicationLogger->info("*Starting import*", [
            'component' => $this->configLogName,
            null,
        ]);
        Cache::clearAll();
        $this->applicationLogger->info("Cleared pimcore data cache", [
            'component' => $this->configLogName,
            null,
        ]);
        
        $productListing = $this->getClassObjectListing($configData);
        $variantListing = $this->getClassVariantListing($configData);

        $productsAndVariants = [];
        foreach ($productListing as $product) {
            if ($product) {
                $product->variants = [];
                $productsAndVariants[$product->getId()] = $product;
            }
        }
        foreach ($variantListing as $variant) {
            if ($variant && array_key_exists($variant->getParentId(), $productsAndVariants)) {
                $productsAndVariants[$variant->getParentId()]->variants[] = $variant;
            }
        }

        $this->applicationLogger->info("Processing " . count($productsAndVariants) . " products", [
            'component' => $this->configLogName,
            null,
        ]);
        foreach ($productsAndVariants as $product) {
            $this->proccess($product);
        }
        $this->applicationLogger->info("Ready to create " .  $this->totalProductsToCreate . " products and " . $this->totalVariantsToCreate . " variants, and to update " . $this->totalProductsToUpdate . " products and " . $this->totalVariantsToUpdate . " variants", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->storeInterface->commit();
        
        $this->applicationLogger->info("*End of import*", [
            'component' => $this->configLogName,
            null,
        ]);
    }

    public function pushStock(Configuration $config)
    {   
        $db = Db::get();
        
        $this->totalStocksToUpdate = 0;

        $this->config = $config;
        $configData = $this->config->getConfiguration();
        
        $this->storeInterface->setup($config);
        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $this->classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());

        $this->configLogName = 'STORE_SYNDICATOR ' . $configData["general"]["name"];

        //Clears logs
        $db->executeStatement('Delete from application_logs where component = ?', [$this->configLogName]);

        //Retrieves the last update timestamp for this config
        $lastUpdateSetting = \Pimcore\Model\WebsiteSetting::getByName($configData["general"]["name"], null, null);
        if(isset($lastUpdateSetting) && !empty($lastUpdateSetting->getData())){
            $lastUpdate = $lastUpdateSetting->getData();
        }else{
            $lastUpdate = 0;
        }

        $this->applicationLogger->info("*Starting stock update*", [
            'component' => $this->configLogName,
            null,
        ]);
        Cache::clearAll();
        $this->applicationLogger->info("Cleared pimcore data cache", [
            'component' => $this->configLogName,
            null,
        ]);
        
        $variantListing = $this->getClassVariantListingForStocks($configData, $lastUpdate);
        // $variantListing = $this->getClassVariantListing($configData);


        $this->applicationLogger->info("Processing " . count($variantListing) . " variants", [
            'component' => $this->configLogName,
            null,
        ]);
        foreach ($variantListing as $variant) {
            $this->processStock($variant);
        }
        $this->applicationLogger->info("Ready to update stock for " .  $this->totalStocksToUpdate . " variants", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->storeInterface->commitStock();
        
        $this->applicationLogger->info("*End of stock update*", [
            'component' => $this->configLogName,
            null,
        ]);

        //Sets the last update timestamp for this config
        $lastUpdateSetting->setData(Carbon::now()->timestamp);
        $lastUpdateSetting->save();

    }

    private function proccess($dataObject)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            $variantCount = count($dataObject->variants);
            if ($variantCount > 100) {
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
                
                foreach ($dataObject->variants as $childVariant) {
                    if ($this->storeInterface->existsInStore($childVariant)) {
                        if($this->storeInterface->updateVariant($dataObject, $childVariant)){
                            $this->totalVariantsToUpdate++;
                        }
                    } else {
                        $this->totalVariantsToCreate++;
                        $this->storeInterface->createVariant($dataObject, $childVariant);
                    }
                }
            }
        }
    }

    private function processStock($dataObject)
    {
        /** @var Concrete $dataObject */
        if (is_a($dataObject, $this->classType)) {
            if ($this->storeInterface->hasInventoryInStore($dataObject)) {
                if($this->storeInterface->updateVariantStock($dataObject)){
                    $this->totalStocksToUpdate++;
                }
            }
        }
    }
    
    private function getClassObjectListing($configData): Dataobject\Listing
    {
        $sql = $configData["products"]["sqlCondition"];
        $listing = $this->classType . '\\Listing';
        $listing = new $listing();
        /** @var Dataobject\Listing $listing */
        $listing->setObjectTypes(['object']);
        $listing->setCondition($sql);
        return $listing;
    }

    private function getClassVariantListing($configData): Dataobject\Listing
    {
        $sql = $configData["products"]["sqlCondition"];
        $listing = $this->classType . '\\Listing';
        $listing = new $listing();
        /** @var Dataobject\Listing $listing */
        $listing->setObjectTypes(['variant']);
        $listing->setCondition($sql);
        return $listing;
    }

    private function getClassVariantListingForStocks($configData, $lastUpdate): Dataobject\Listing
    {
        $sql = $configData["products"]["sqlCondition"] . " AND (InventoryModificationDate is NULL OR InventoryModificationDate >= " . $lastUpdate . ")";
        $listing = $this->classType . '\\Listing';
        $listing = new $listing();
        /** @var Dataobject\Listing $listing */
        $listing->setObjectTypes(['variant']);
        $listing->setCondition($sql);
        return $listing;
    }

}

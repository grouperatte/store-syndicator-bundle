<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

use DateTime;
use Exception;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\DataObject;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Db;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Product;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Db\Helper as DBHelper;
use \Pimcore\Cache;
use Pimcore\Logger;


class ShopifyProductLinkingService
{
    private string $configLogName;
    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
        protected ApplicationLogger $applicationLogger,
        private \Psr\Log\LoggerInterface $customLogLogger
    ) {

    }

    /**
     * links pimcores objects to shopifys based on the configuration's attribute with the link option selected
     * 
     * places the remoteProduct ID in the products TorqSS:*storename*:shopifyId property 
     * sets the TorqSS:*storename*:linked property to true
     *
     **/
    public function link(Configuration $configuration)
    {

        $configData = $configuration->getConfiguration();
        $this->configLogName = 'STORE_SYNDICATOR ' . $configData["general"]["name"];

        $this->applicationLogger->info("Start of property linking", [
            'component' => $this->configLogName,
            null,
        ]);
        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($configuration);
        $shopifyQueryService = new ShopifyQueryService($authenticator, $this->customLogLogger);
        $remoteStoreName = $this->configurationService->getStoreName($configuration);
        $linkedProperty = "TorqSS:" . $remoteStoreName . ":linked";
        $remoteIdProperty = "TorqSS:" . $remoteStoreName . ":shopifyId";
        $remoteLastUpdatedProperty = "TorqSS:" . $remoteStoreName . ":lastUpdated";

        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());
        
        $linkingAttribute = ConfigurationService::getMapOnRow($configuration);
       
        $remoteProductsAndVariants = $shopifyQueryService->queryForLinking(ShopifyGraphqlHelperService::buildProductLinkingQuery( $linkingAttribute['remote field']));
        
        $this->customLogLogger->info(print_r($remoteProductsAndVariants, true));
        
        $this->applicationLogger->info(count($remoteProductsAndVariants) . " products and variants queried from the Shopify Store", [
            'component' => $this->configLogName,
            null,
        ]);
        
        
        $db = Db::get();
        $query = 'select oo_id, ProductStatus, o_path from pimcore.object_' . $this->configurationService->getDataobjectClass($configuration)->getId();

        $localProductsAndVariants = [];
        foreach ($db->query($query) as $product) {
            if ($product) {
                $localProductsAndVariants[$product['oo_id']] = $product;
            }
        }

        $this->applicationLogger->info(count($localProductsAndVariants) . " products and variants queried from Pimcore", [
            'component' => $this->configLogName,
            null,
        ]);

        $result = $db->executeStatement('Delete from properties where name IN (?, ?)', [$remoteIdProperty, $linkedProperty]);
        $this->applicationLogger->info("Deleted properties from products and variants", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info("Start of data processing", [
            'component' => $this->configLogName,
            null,
        ]);
        $purgeVariantArray = [];
        $purgeProductArray = [];

        $propertySetCount = 0;
        $toPurgeNullCount = 0;
        $toPurgeNotFoundInCount = 0;
        $toPurgeNotActiveCount = 0;
        $toPurgeDuplicateCount = 0;

        foreach ($remoteProductsAndVariants as $shopifyId => $productOrVariant) {
            $purge = false;
            if(!isset($productOrVariant["title"]) || $productOrVariant["title"] !== "Default Title"){
                $pimcoreId = $productOrVariant["linkingId"]['value'] ?? null;
                if($pimcoreId){
                    $pimcoreObject = $localProductsAndVariants[$pimcoreId] ?? null;
                    if($pimcoreObject){
                        if(!$pimcoreObject['ProductStatus'] || $pimcoreObject['ProductStatus'] === "active"){
                            if(!isset($pimcoreObject['linked'])){
                                DBHelper::upsert($db, 'pimcore.properties', ['cid' => $pimcoreId, 'ctype' => 'object', 'cpath' => $pimcoreObject['o_path'], 'name' => $remoteIdProperty, 'type' => 'text', 'data' => $shopifyId, 'inheritable' => 0], ['cid', 'ctype', 'name'], false);
                                $lastUpdated = $productOrVariant["lastUpdated"]['value'] ?? null;
                                if($lastUpdated){
                                    DBHelper::upsert($db, 'pimcore.properties', ['cid' => $pimcoreId, 'ctype' => 'object', 'cpath' => $pimcoreObject['o_path'], 'name' => $remoteLastUpdatedProperty, 'type' => 'text', 'data' => $lastUpdated, 'inheritable' => 0], ['cid', 'ctype', 'name'], false);
                                }
                                $pimcoreObject['linked'] = true;
                                $propertySetCount++;
                            }else{
                                $purge = true;
                                $toPurgeDuplicateCount++;
                            }                      
                        }else{
                            $purge = true;
                            $toPurgeNotActiveCount++;
                        }
                    }else{
                        $purge = true;
                        $toPurgeNotFoundInCount++;
                    }
                }else {
                    $purge = true;
                    $toPurgeNullCount++;
                }
            }elseif(isset($productOrVariant["title"]) && $productOrVariant["title"] === "Default Title"){
                $purgeProductArray[] = $productOrVariant['__parentId'];
            }
            if($purge){
                if(isset($productOrVariant['__parentId'])){
                    $purgeVariantArray[$shopifyId] = $productOrVariant['__parentId'];
                }else{
                    $purgeProductArray[] = $shopifyId;
                }
            }
        }
       

        $this->applicationLogger->info("End of data processing", [
            'component' => $this->configLogName,
            null,
        ]);
       
        Cache::clearAll();
        $this->applicationLogger->info("Cleared pimcore data cache", [
            'component' => $this->configLogName,
            null,
        ]);
        
        $this->applicationLogger->info("Linked " . $propertySetCount . " products and variants", [
            'component' => $this->configLogName,
            null,
        ]);
        $this->applicationLogger->info($toPurgeNullCount . " products and variants are scheduled to be deleted in shopify because they do not have a Pimcore ID", [
            'component' => $this->configLogName,
            null,
        ]);
        $this->applicationLogger->info($toPurgeNotFoundInCount . " products and variants are scheduled to be deleted in shopify because their Pimcore ID didn't match any product in Pimcore", [
            'component' => $this->configLogName,
            null,
        ]);
        $this->applicationLogger->info($toPurgeNotActiveCount . " products and variants are scheduled to be deleted in shopify because the Pimcore product is not active", [
            'component' => $this->configLogName,
            null,
        ]);
        $this->applicationLogger->info($toPurgeDuplicateCount . " duplicate products and variants are scheduled to be deleted in shopify", [
            'component' => $this->configLogName,
            null,
        ]);
        $this->applicationLogger->info("Property linking is finished", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info("Start of Shopify mutations to purge products and variants. " . ($toPurgeNullCount + $toPurgeNotFoundInCount + $toPurgeNotActiveCount) . " products and variants to be deleted." , [
            'component' => $this->configLogName,
            null,
        ]);

            // $purgeProductArray = ['gid:\/\/shopify\/Product\/8435237585191', '5dfsgesg', 'dawdawd'];
        if(count($purgeProductArray) > 0){
            Logger::info(print_r($purgeProductArray, true));
            // $shopifyQueryService->deleteProducts($purgeProductArray);
            $this->applicationLogger->info("Shopify mutations to delete products have been submitted", [
                'component' => $this->configLogName,
                null,
            ]);
        }
        if(count($purgeVariantArray) > 0){
            Logger::info(print_r($purgeVariantArray, true));
            // $shopifyQueryService->deleteVariants($purgeVariantArray);
            $this->applicationLogger->info("Shopify mutations to delete variants have been submitted", [
                'component' => $this->configLogName,
                null,
            ]);
        }
        $this->applicationLogger->info("End of Shopify mutations to purge products and variants", [
            'component' => $this->configLogName,
            null,
        ]);
    }
}



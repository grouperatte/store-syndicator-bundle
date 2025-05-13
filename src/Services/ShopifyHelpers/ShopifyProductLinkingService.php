<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

use Pimcore\Db;
use \Pimcore\Cache;
use Doctrine\DBAL\Connection;
use Pimcore\Db\Helper as DBHelper;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyQueryService;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyGraphqlHelperService;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;


class ShopifyProductLinkingService
{
    private string $configLogName;
    private Connection $db;
    private array $localProductsAndVariants;
    private string $remoteIdProperty;
    private string $remoteLastUpdatedProperty;
    private string $remoteInventoryIdProperty;

    private int $propertySetCount = 0;
    private int $toPurgeNullCount = 0;
    private int $toPurgeNotFoundInCount = 0;
    private int $toPurgeNotActiveCount = 0;
    private int $toPurgeDuplicateCount = 0;
    private int $toPurgeChildlessProduct = 0;


    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
        protected ApplicationLogger $applicationLogger
    ) {}

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

        $this->applicationLogger->info("Start of property linking and shopify cleanup", [
            'component' => $this->configLogName,
            null,
        ]);
        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($configuration);
        $shopifyQueryService = new ShopifyQueryService($authenticator, $this->applicationLogger, $this->configLogName);
        $remoteStoreName = $this->configurationService->getStoreName($configuration);
        $this->remoteIdProperty = "TorqSS:" . $remoteStoreName . ":shopifyId";
        $this->remoteLastUpdatedProperty = "TorqSS:" . $remoteStoreName . ":lastUpdated";
        $this->remoteInventoryIdProperty = "TorqSS:" . $remoteStoreName . ":inventoryId";


        $classType = $configData["products"]["class"];
        $classType = ClassDefinition::getById($classType);
        $classType = "Pimcore\\Model\\DataObject\\" . ucfirst($classType->getName());

        $linkingAttribute = ConfigurationService::getMapOnRow($configuration);

        $remoteProducts = $shopifyQueryService->queryForLinking(ShopifyGraphqlHelperService::buildProductLinkingQuery($linkingAttribute['remote field']));
        $this->applicationLogger->info(count($remoteProducts) . " products queried from the Shopify Store", [
            'component' => $this->configLogName,
            null,
        ]);


        $this->db = Db::get();
        $query = 'select oo_id, ProductStatus, path from pimcore.object_' . $this->configurationService->getDataobjectClass($configuration)->getId();

        $this->localProductsAndVariants = [];
        foreach ($this->db->executeQuery($query)->fetchAllAssociative() as $product) {
            if ($product) {
                $this->localProductsAndVariants[$product['oo_id']] = $product;
            }
        }

        $this->applicationLogger->info(count($this->localProductsAndVariants) . " products and variants queried from Pimcore", [
            'component' => $this->configLogName,
            null,
        ]);

        $result = $this->db->executeStatement('Delete from properties where name IN (?) and ctype = (?)', [$this->remoteIdProperty, "object"]);
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

        foreach ($remoteProducts as $shopifyId => $product) {
            if (isset($product["variants"])) {
                foreach ($product["variants"] as $shopifyVariantId => $variant) {
                    if (!$this->linkOrCleanup($variant, $shopifyVariantId)) {
                        $purgeVariantArray[$shopifyVariantId] = $shopifyId;
                        unset($product["variants"][$shopifyVariantId]);
                    }
                }
                if (count($product["variants"]) > 0) {
                    if (!$this->linkOrCleanup($product, $shopifyId)) {
                        $purgeProductArray[] = $shopifyId;
                    }
                } else {
                    $purgeProductArray[] = $shopifyId;
                    $this->toPurgeChildlessProduct++;
                }
            } else {
                $purgeProductArray[] = $shopifyId;
                $this->toPurgeChildlessProduct++;
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

        $this->applicationLogger->info("Linked " . $this->propertySetCount . " products and variants", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info($this->toPurgeNullCount . " products and variants are scheduled to be deleted in shopify because they do not have a Pimcore ID", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info($this->toPurgeNotFoundInCount . " products and variants are scheduled to be deleted in shopify because their Pimcore ID didn't match any product in Pimcore", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info($this->toPurgeNotActiveCount . " products and variants are scheduled to be deleted in shopify because the Pimcore product is not active", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info($this->toPurgeDuplicateCount . " duplicate products and variants are scheduled to be deleted in shopify", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info($this->toPurgeChildlessProduct . " products without a variant are scheduled to be deleted in shopify", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info("Property linking is finished", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info("Start of Shopify mutations to purge products and variants. " . ($this->toPurgeNullCount + $this->toPurgeNotFoundInCount + $this->toPurgeNotActiveCount + $this->toPurgeDuplicateCount + $this->toPurgeChildlessProduct) . " products and variants to be deleted.", [
            'component' => $this->configLogName,
            null,
        ]);

        if (count($purgeProductArray) > 0) {
            $shopifyQueryService->deleteProducts($purgeProductArray);
            $this->applicationLogger->info("Shopify mutations to delete products have been submitted", [
                'component' => $this->configLogName,
                null,
            ]);
        }
        if (count($purgeVariantArray) > 0) {
            $shopifyQueryService->deleteVariants($purgeVariantArray);
            $this->applicationLogger->info("Shopify mutations to delete variants have been submitted", [
                'component' => $this->configLogName,
                null,
            ]);
        }
        $this->applicationLogger->info("End of Shopify mutations to purge products and variants", [
            'component' => $this->configLogName,
            null,
        ]);

        $this->applicationLogger->info("End of property linking and shopify cleanup", [
            'component' => $this->configLogName,
            null,
        ]);
    }

    private function linkOrCleanup($product, $shopifyId)
    {
        $pimcoreId = $product["linkingId"]['value'] ?? null;
        if ($pimcoreId) {
            $pimcoreObject = $this->localProductsAndVariants[$pimcoreId] ?? null;
            if ($pimcoreObject) {
                if (!$pimcoreObject['ProductStatus'] || $pimcoreObject['ProductStatus'] === "active") {
                    if (!isset($pimcoreObject['linked'])) {
                        DBHelper::upsert($this->db, 'pimcore.properties', ['cid' => $pimcoreId, 'ctype' => 'object', 'cpath' => $pimcoreObject['path'], 'name' => $this->remoteIdProperty, 'type' => 'text', 'data' => $shopifyId, 'inheritable' => 0], ['cid', 'ctype', 'name'], false);
                        $lastUpdated = $product["lastUpdated"]['value'] ?? null;
                        if ($lastUpdated) {
                            DBHelper::upsert($this->db, 'pimcore.properties', ['cid' => $pimcoreId, 'ctype' => 'object', 'cpath' => $pimcoreObject['path'], 'name' => $this->remoteLastUpdatedProperty, 'type' => 'text', 'data' => $lastUpdated, 'inheritable' => 0], ['cid', 'ctype', 'name'], false);
                        }
                        $inventoryItemId = $product["inventoryItem"]['id'] ?? null;
                        if ($inventoryItemId) {
                            DBHelper::upsert($this->db, 'pimcore.properties', ['cid' => $pimcoreId, 'ctype' => 'object', 'cpath' => $pimcoreObject['path'], 'name' => $this->remoteInventoryIdProperty, 'type' => 'text', 'data' => $inventoryItemId, 'inheritable' => 0], ['cid', 'ctype', 'name'], false);
                        }
                        $this->localProductsAndVariants[$pimcoreId]['linked'] = true;
                        $this->propertySetCount++;
                        return true;
                    } else {
                        $this->toPurgeDuplicateCount++;
                        return false;
                    }
                } else {
                    $this->toPurgeNotActiveCount++;
                    return false;
                }
            } else {
                $this->toPurgeNotFoundInCount++;
                return false;
            }
        } else {
            $purge = true;
            $this->toPurgeNullCount++;
        }
    }
}

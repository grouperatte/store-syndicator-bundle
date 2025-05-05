<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use DateTime;
use Exception;
use Pimcore\Db;
use DateTimeZone;
use Pimcore\Logger;
use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\DataObject;
use Pimcore\Model\Asset\Image;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Concrete;
use Shopify\Rest\Admin2023_01\Product;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Shopify\Exception\RestResourceRequestException;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyCreateProductMessage;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\LogRow;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyQueryService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyProductLinkingService;

class ShopifyStore extends BaseStore
{
    const IMAGEPROPERTYNAME = "ShopifyImageURL";
    private ShopifyQueryService $shopifyQueryService;
    private ShopifyProductLinkingService $shopifyProductLinkingService;
    private array $updateProductArrays;
    private array $createProductArrays;
    private array $updateVariantsArrays;
    private array $createVariantsArrays;
    private array $metafieldSetArrays;
    private array $updateImageMap;
    private array $metafieldTypeDefinitions;
    private string $storeLocationId;
    private array $updateStock;
    private string $configLogName;
    // private array $productMetafieldsMapping;
    //private array $variantMetafieldsMapping;


    private AttributesService $attributeService;

    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
        private ApplicationLogger $applicationLogger,
        protected \Psr\Log\LoggerInterface $customLogLogger ) {
        $this->attributeService = new AttributesService();
        $this->shopifyProductLinkingService = new ShopifyProductLinkingService($configurationRepository, $configurationService, $applicationLogger, $customLogLogger);
    }

    public function setup(Configuration $config)
    {
        $this->config = $config;
        $remoteStoreName = $this->configurationService->getStoreName($config);

        $this->propertyName = "TorqSS:" . $remoteStoreName . ":shopifyId";
        $this->remoteLastUpdatedProperty = "TorqSS:" . $remoteStoreName . ":lastUpdated";
        $this->remoteInventoryIdProperty = "TorqSS:" . $remoteStoreName . ":inventoryId";

        $configData = $this->config->getConfiguration();
        $this->configLogName = 'STORE_SYNDICATOR ' . $configData["general"]["name"];

        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($config);
        $this->shopifyQueryService = new ShopifyQueryService($authenticator, $this->customLogLogger);
        $this->metafieldTypeDefinitions = $this->shopifyQueryService->queryMetafieldDefinitions();
        $this->storeLocationId = $this->shopifyQueryService->getPrimaryStoreLocationId();

        $this->updateProductArrays = [];
        $this->createProductArrays = [];
        $this->updateVariantsArrays = [];
        $this->createVariantsArrays = [];
        $this->metafieldSetArrays = [];
        $this->updateImageMap = [];
        $this->updateStock = [];

        Db::get()->executeQuery('SET SESSION wait_timeout = ' . 28800); //timeout to 8 hours for this session
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $graphQLInput = [];
        $graphQLMedia = [];
        $graphQLInput["title"] = $object->getKey();
        $graphQLInput["metafields"][] = array(
            "namespace" => "custom",
            "key" => "last_updated",
            "type" => "single_line_text_field",
            "value" => strval(time()),
        );
        $graphQLInput["metafields"][] = array(
            "namespace" => "custom",
            "key" => "pimcore_id",
            "type" => "single_line_text_field",
            "value" => strval($object->getId()),
        );
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $graphQLInput["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
            }
            unset($fields['metafields']);
        }
        if (isset($fields["image"])) {
            /** @var Image $image */
            foreach ($fields["image"] as $image) {
                $graphQLMedia[] = array(
                    "originalSource" => $image->getFrontendFullPath(),
                    "mediaContentType" => "IMAGE"
                );
            }
            unset($fields["image"]);
        }
        unset($fields["image"]);


        if (isset($fields['base product'])) $this->processBaseProductData($fields['base product'], $graphQLInput);
        $this->createProductArrays[$object->getId()]['input'] = $graphQLInput;
        $this->createProductArrays[$object->getId()]['media'] = $graphQLMedia;
    }

    public function updateProduct(Concrete $object): void
    {
        $graphQLInput = [];
        $graphQLMedia = [];
        // //If has unsynchronised changes, save in property for later
        // if(intval($object->getProperty($this->remoteLastUpdatedProperty)) < $object->getModificationDate()){
        //     $graphQLInput['hasUpdate'] = true;
        // }

        //Skip if no new changes
        if (intval($object->getProperty($this->remoteLastUpdatedProperty)) > $object->getModificationDate()) {
            return;
        }

        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreProductId($object);

        $graphQLInput["title"] = $fields["title"][0] ?? $object->getKey();
        if (isset($fields['metafields'])) {
            $batchArray = [
                [
                    "namespace" => "custom",
                    "key" => "last_updated",
                    "type" => "single_line_text_field",
                    "value" => strval(time()),
                    "ownerId" => $remoteId
                ],
                [
                    "namespace" => "custom",
                    "key" => "pimcore_id",
                    "type" => "single_line_text_field",
                    "value" => strval($object->getId()),
                    "ownerId" => $remoteId
                ]
            ];
            foreach ($fields['metafields'] as $attribute) {
                $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
                $metafield["ownerId"] = $remoteId;
                if (count($batchArray) < 25) {
                    $batchArray[] = $metafield;
                } else {
                    $this->metafieldSetArrays[] = $batchArray;
                    $batchArray = [$metafield];
                }
            }

            if (!empty($batchArray)) {
                $this->metafieldSetArrays[] = $batchArray;
            }
            unset($fields['metafields']);
        }
        // if (isset($fields["image"])) {
        //     /** @var Image $image */
        //     foreach ($fields["image"] as $image) {
        //         $graphQLMedia[] = array(
        //             "originalSource" => $image->getFrontendFullPath(),
        //             "mediaContentType"=> "IMAGE"
        //         );
        //     }
        //     unset($fields["image"]);
        // }
        // unset($fields["image"]);

        $this->processBaseProductData($fields['base product'], $graphQLInput);
        $graphQLInput["id"] = $remoteId;
        $graphQLInput["handle"] = $graphQLInput["title"] . "-" . $remoteId;
        $this->updateProductArrays[$object->getId()]['input'] = $graphQLInput;
        // $this->updateProductArrays[$object->getId()]['media'] = $graphQLMedia;
    }

    public function createVariant(Concrete $parent, Concrete $child): void
    {
        $graphQLInput = [];
        $fields = $this->getAttributes($child);
        if (isset($fields['variant metafields'])) {
            foreach ($fields['variant metafields'] as $attribute) {
                $graphQLInput["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
            }
        }
        $graphQLInput["metafields"][] = array(
            "namespace" => "custom",
            "key" => "last_updated",
            "type" => "single_line_text_field",
            "value" => strval(time()),
        );
        $graphQLInput["metafields"][] = array(
            "namespace" => "custom",
            "key" => "pimcore_id",
            "type" => "single_line_text_field",
            "value" => strval($child->getId()),
        );

        if (isset($fields['base variant'])) $this->processBaseVariantData($fields['base variant'], $graphQLInput);
        if (isset($fields['base variant']['stock'])) {
            $graphQLInput["inventoryQuantities"]["availableQuantity"] = (float)$fields['base variant']['stock'][0];
            $graphQLInput["inventoryQuantities"]["locationId"] = $this->storeLocationId;
        }
        if (!isset($graphQLInput["options"])) {
            $graphQLInput["options"][] = $child->getKey();
        }

        $parentRemoteId = $this->getStoreProductId($parent);

        if ($this->existsInStore($parent)) {
            if (!isset($this->createVariantsArrays[$parentRemoteId])) {
                $this->createVariantsArrays[$parentRemoteId] = [];
            }
            $this->createVariantsArrays[$parentRemoteId][] = $graphQLInput;
        } else {
            $this->createProductArrays[$parent->getId()]['input']['variants'][] = $graphQLInput;
        }
    }


    public function updateVariant(Concrete $parent, Concrete $child): bool
    {

        //Skip if no new changes
        if (intval($child->getProperty($this->remoteLastUpdatedProperty)) > $child->getModificationDate()) {
            return false;
        }

        $remoteId = $this->getStoreProductId($child);

        $fields = $this->getAttributes($child);

        $batchArray = [
            [
                "namespace" => "custom",
                "key" => "last_updated",
                "type" => "single_line_text_field",
                "value" => strval(time()),
                "ownerId" => $remoteId
            ],
            [
                "namespace" => "custom",
                "key" => "pimcore_id",
                "type" => "single_line_text_field",
                "value" => strval($child->getId()),
                "ownerId" => $remoteId
            ]
        ];
        if (isset($fields['variant metafields'])) {
            foreach ($fields['variant metafields'] as $attribute) {
                $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
                $metafield["ownerId"] = $remoteId;
                if (count($batchArray) < 25) {
                    $batchArray[] = $metafield;
                } else {
                    $this->metafieldSetArrays[] = $batchArray;
                    $batchArray = [$metafield];
                }
            }
        }
        if (!empty($batchArray)) {
            $this->metafieldSetArrays[] = $batchArray;
        }

        $graphQLInput = [];

        if (isset($fields['base variant'])) $this->processBaseVariantData($fields['base variant'], $graphQLInput);
        $inventoryId = $this->getStoreInventoryId($child);
        if (isset($fields['base variant']['stock']) && $inventoryId != null) {
            $this->updateStock[$inventoryId] = $fields['base variant']['stock'][0];
        }
        if (!isset($graphQLInput["options"])) {
            $graphQLInput["options"][] = $child->getKey();
        }

        $graphQLInput["id"] = $remoteId;

        $parentRemoteId = $this->getStoreProductId($parent);

        if (!isset($this->updateVariantsArrays[$parentRemoteId])) {
            $this->updateVariantsArrays[$parentRemoteId] = [];
        }
        $this->updateVariantsArrays[$parentRemoteId][] = $graphQLInput;
        return true;
    }

    private function processBaseProductData($fields, &$graphQLInput)
    {
        foreach ($fields as $field => $value) {
            if ($field === "status") {
                $value[0] = strtoupper($value[0]);
                if (!in_array($value[0], ["ACTIVE", "ARCHIVED", "DRAFT"])) {
                    throw new Exception("invalid status value $value[0] not one of ACTIVE ARCHIVED or DRAFT");
                }
            } elseif ($field === 'tags') {
                $graphQLInput[$field] = $value;
                continue;
            }
            $graphQLInput[$field] = $value[0];
        }
    }

    private function processBaseVariantData($fields, &$thisVariantArray)
    {
        foreach ($fields as $field => $value) {
            if ($field === "stock") { // special cases
                continue;
            }
            if ($field === 'weight' || $field === 'cost' || $field === 'price') { //wants this as a non-string wrapped number
                $value[0] = (float)$value[0];
            }
            if ($field === 'tracked') {
                $value[0] = $value[0] == "true";
            }
            if ($field === 'cost' || $field === 'tracked') {
                $thisVariantArray['inventoryItem'][$field] = $value[0];
                continue;
            } elseif ($field === 'continueSellingOutOfStock') {
                $thisVariantArray['inventoryPolicy'] = $value[0] ? "CONTINUE" : "DENY";
                continue;
            } elseif ($field === 'weightUnit') {
                $value[0] = strtoupper($value[0]);
                if (!in_array($value[0], ["POUNDS", "OUNCES", "KILOGRAMS", "GRAMS"])) {
                    throw new Exception("invalid weightUnit value $value[0] not one of POUNDS OUNCES KILOGRAMS or GRAMS");
                }
            } elseif ($field === 'title') {
                $thisVariantArray["options"][] = $value[0];
                continue;
            }
            $thisVariantArray[$field] = $value[0];
        }
    }

    /**
     * @param array $attribute [key => *, namespace => *, value => *]
     * @param array $mappingArray $this->metafieldTypeDefinitions["variant"/"product"]
     * @return array full metafield shopify object
     **/
    private function createMetafield($attribute, $mappingArray): array
    {
        if (array_key_exists($attribute["namespace"] . "." .  $attribute["fieldName"], $mappingArray)) {
            $tmpMetafield = $mappingArray[$attribute["namespace"] . "." .  $attribute["fieldName"]];
            if (str_contains($mappingArray[$attribute["namespace"] . "." .  $attribute["fieldName"]]["type"], "list.")) {
                $tmpMetafield["value"] = json_encode($attribute["value"]);
            } else {
                $tmpMetafield["value"] = $attribute["value"][0];
            }
        } else {
            throw new Exception("undefined metafield definition: " . $attribute["namespace"] . "." .  $attribute["fieldName"]);
        }
        return $tmpMetafield;
    }

    public function updateVariantStock(Concrete $object): bool
    {
        $inventoryId = $this->getStoreInventoryId($object);

        $fields = $this->getAttributes($object);

        if (isset($fields['base variant']['stock'])) {
            $this->updateStock[$inventoryId] = $fields['base variant']['stock'][0];
        }

        return true;
    }

    public function commit( bool $doLinking = false )
    {
        $this->applicationLogger->info("Start of Shopify mutations", [
            'component' => $this->configLogName,
            null,
        ]);

        if (!empty($this->createProductArrays)) {
            $excludedCount = 0;
            foreach ($this->createProductArrays as $index => $product) {
                if (!isset($product['input']['variants']) || count($product['input']['variants']) == 0) {
                    unset($this->createProductArrays[$index]);
                    $excludedCount++;
                }
            }

            try {
                if (count($this->createProductArrays) > 0) {
                    $this->applicationLogger->info("Start of Shopify mutation to create " . count($this->createProductArrays) . " products and their variants. " . $excludedCount . " have been excluded because they don't have active variants", [
                        'component' => $this->configLogName,
                        null,
                    ]);
                    $resultFiles = $this->shopifyQueryService->createProducts($this->createProductArrays);
                    foreach ($resultFiles as $resultFileURL) {
                        $this->applicationLogger->info("Shopify mutation to create products and variants is finished " . $resultFileURL, [
                            'component' => $this->configLogName,
                            'fileObject' => $resultFileURL,
                            null,
                        ]);
                    }
                }
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to create products and variants : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }

        //also takes care of creating variants
        if (!empty($this->updateProductArrays)) {
            try {
                $this->applicationLogger->info("Start of Shopify mutation to update " . count($this->updateProductArrays) . " products.", [
                    'component' => $this->configLogName,
                    null,
                ]);
                if (count($this->updateProductArrays) > 0) {
                    $resultFiles = $this->shopifyQueryService->updateProducts($this->updateProductArrays);
                    foreach ($resultFiles as $resultFileURL) {
                        $this->applicationLogger->info("Shopify mutation to update products is finished " . $resultFileURL, [
                            'component' => $this->configLogName,
                            'fileObject' => $resultFileURL,
                            null,
                        ]);
                    }
                }
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to update products : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }
        if ($this->createVariantsArrays) {
            try {
                $this->applicationLogger->info("Start of Shopify mutation to create variants", [
                    'component' => $this->configLogName,
                    null,
                ]);
                $resultFiles = $this->shopifyQueryService->createBulkVariants($this->createVariantsArrays);
                $this->applicationLogger->info("Shopify mutations to create variants have been submitted", [
                    'component' => $this->configLogName,
                    null,
                ]);
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to create variants : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }
        if ($this->updateVariantsArrays) {
            try {
                $this->applicationLogger->info("Start of Shopify mutation to update variants", [
                    'component' => $this->configLogName,
                    null,
                ]);
                $resultFile = $this->shopifyQueryService->updateBulkVariants($this->updateVariantsArrays);
                // foreach ($resultFiles as $resultFileURL) {
                //     $this->applicationLogger->info("Shopify mutation to update variants is finished " . $resultFileURL, [
                //         'component' => $this->configLogName,
                //         'fileObject' => $resultFileURL,
                //         null,
                //     ]);
                // }
                $this->applicationLogger->info("Shopify mutations to update variants have been submitted", [
                    'component' => $this->configLogName,
                    null,
                ]);
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to update variants : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }

        if ($this->metafieldSetArrays) {
            try {
                $this->applicationLogger->info("Start of Shopify mutations to update metafields", [
                    'component' => $this->configLogName,
                    null,
                ]);
                $resultFiles = $this->shopifyQueryService->updateMetafields($this->metafieldSetArrays);
                foreach ($resultFiles as $resultFileURL) {
                    $this->applicationLogger->info("A Shopify mutation to update metafields is finished " . $resultFileURL, [
                        'component' => $this->configLogName,
                        'fileObject' => $resultFileURL,
                        null,
                    ]);
                }
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutations to update metafields : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }
        if ($this->updateStock) {
            try {
                $this->applicationLogger->info("Start of Shopify mutation to update inventory: " . $this->storeLocationId, [
                    'component' => $this->configLogName,
                    null,
                ]);

                $results = $this->shopifyQueryService->updateStock($this->updateStock, $this->storeLocationId);
                $this->applicationLogger->info("Shopify mutation to update inventory is finished " . json_encode($results), [
                    'component' => $this->configLogName,
                    null,
                ]);
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to update inventory : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }
        $this->applicationLogger->info("End of Shopify mutations", [
            'component' => $this->configLogName,
            null,
        ]);

        // if (!empty($this->createProductArrays) || !empty($this->updateProductArrays) || $this->updateVariantsArrays || $this->metafieldSetArrays) {
        //     $this->shopifyProductLinkingService->link($this->config);
        // }
        if( $doLinking )
            $this->shopifyProductLinkingService->link($this->config);
    }

    public function commitStock()
    {

        $this->applicationLogger->info("Start of Shopify mutations", [
            'component' => $this->configLogName,
            null,
        ]);

        if ($this->updateStock) {
            try {
                $this->applicationLogger->info("Start of Shopify mutation to update inventory: " . $this->storeLocationId, [
                    'component' => $this->configLogName,
                    null,
                ]);

                $results = $this->shopifyQueryService->updateStock($this->updateStock, $this->storeLocationId);
                $this->applicationLogger->info("Shopify mutation to update inventory is finished " . json_encode($results), [
                    'component' => $this->configLogName,
                    null,
                ]);
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to update inventory : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }

        $this->applicationLogger->info("End of Shopify mutations", [
            'component' => $this->configLogName,
            null,
        ]);
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Exception;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Product;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Config;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyQueryService;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;

class ShopifyStore extends BaseStore
{
    private ShopifyQueryService $shopifyQueryService;
    private array $updateProductArrays;
    private array $createProductArrays;
    private array $updateVariantsArrays;
    private array $createVariantsArrays;
    private array $metafieldSetArrays;
    private array $metafieldTypeDefinitions;
    private string $storeLocationId;
    private array $updateStock;
    private array $publicationIds;
    private array $addProdsToStore;
    private array $newImages; //images that need to be uploaded and linked back to pimcore asset
    private array $images; //all images in this export and their referencing products
    private string $configLogName;

    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
        private ApplicationLogger $applicationLogger,
    ) {}

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
        $this->shopifyQueryService = new ShopifyQueryService($authenticator, $this->applicationLogger, $this->configLogName);
        $this->metafieldTypeDefinitions = $this->shopifyQueryService->queryMetafieldDefinitions();
        $this->storeLocationId = $this->shopifyQueryService->getPrimaryStoreLocationId();
        $this->publicationIds = $this->shopifyQueryService->getSalesChannels();

        $this->updateProductArrays = [];
        $this->createProductArrays = [];
        $this->updateVariantsArrays = [];
        $this->createVariantsArrays = [];
        $this->metafieldSetArrays = [];
        $this->updateStock = [];
        $this->newImages = [];
        $this->images = [];
        $this->addProdsToStore = [];

        Db::get()->executeQuery('SET SESSION wait_timeout = ' . 28800); //timeout to 8 hours for this session
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $graphQLInput = [];

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

        if (isset($fields['base product'])) $this->processBaseProductData($fields['base product'], $graphQLInput);

        if (isset($fields['image'])) {
            foreach ($fields['image'] as $image) {
                $this->processImage($image, $object);
            }
        }

        $this->createProductArrays[$object->getId()]['input'] = $graphQLInput;
        $this->addProdsToStore[] = $object;
    }

    public function updateProduct(Concrete $object): void
    {
        $graphQLInput = [];

        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreId($object);

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

        $this->processBaseProductData($fields['base product'], $graphQLInput);

        if (isset($fields['image'])) {
            foreach ($fields['image'] as $image) {
                $this->processImage($image, $object);
            }
        }

        $graphQLInput["id"] = $remoteId;
        $graphQLInput["handle"] = $graphQLInput["title"] . "-" . $remoteId;
        $this->updateProductArrays[$object->getId()]['input'] = $graphQLInput;
        $this->addProdsToStore[] = $object;
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
        if (!isset($graphQLInput["optionValues"])) {
            $graphQLInput["optionValues"]["name"] = $child->getKey();
            $graphQLInput["optionValues"]["optionName"] = "Title";
        }

        $this->createVariantsArrays[$parent->getId()][] = $graphQLInput;
    }


    public function updateVariant(Concrete $parent, Concrete $child): bool
    {

        //Skip if no new changes
        if (intval($child->getProperty($this->remoteLastUpdatedProperty)) > $child->getModificationDate()) {
            return false;
        }

        $remoteId = $this->getStoreId($child);

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
        if (!isset($graphQLInput["optionValues"])) {
            $graphQLInput["optionValues"]["name"] = $child->getKey();
            $graphQLInput["optionValues"]["optionName"] = "Title";
        }

        $graphQLInput["id"] = $remoteId;

        $parentRemoteId = $this->getStoreId($parent);

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
            } elseif ($field === 'sku') {
                $thisVariantArray["inventoryItem"]["sku"] = $value[0];
            } elseif ($field === 'weight') { //wants this as a non-string wrapped number
                $thisVariantArray["inventoryItem"]["measurement"]["weight"]["value"] = (float)$value[0];
            } elseif ($field === 'weightUnit') {
                $unit = strtoupper($value[0]);
                if (!in_array($unit, ["POUNDS", "OUNCES", "KILOGRAMS", "GRAMS"])) {
                    throw new Exception("invalid weightUnit value $unit not one of POUNDS OUNCES KILOGRAMS or GRAMS");
                }
                $thisVariantArray["inventoryItem"]["measurement"]["weight"]["unit"] = $unit;
            } elseif ($field === 'cost') {
                $thisVariantArray["inventoryItem"]["cost"] = (float)$value[0];
            } elseif ($field === 'price') {
                $thisVariantArray['price'] = (float)$value[0];
            } elseif ($field === 'tracked') {
                $thisVariantArray["inventoryItem"] == "true";
            } elseif ($field === 'continueSellingOutOfStock') {
                $thisVariantArray['inventoryPolicy'] = $value[0] ? "CONTINUE" : "DENY";
            } elseif ($field === 'title') {
                $thisVariantArray["optionValues"][] = $value[0];
            } elseif ($field === "requiresShipping") {
                $thisVariantArray["inventoryItem"]["requiresShipping"] = boolval($value[0]);
            } else {
                $thisVariantArray[$field] = $value[0];
            }
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

    public function commit()
    {
        $this->applicationLogger->info("Start of Shopify mutations", [
            'component' => $this->configLogName,
            null,
        ]);

        if (!empty($this->createProductArrays)) {
            //create unmade products by pushing messages to queue for asynchronous handling
            try {
                if (count($this->createProductArrays) > 0) {
                    $this->applicationLogger->info("Start of Shopify mutation to create " . count($this->createProductArrays) . " products.", [
                        'component' => $this->configLogName,
                        null,
                    ]);
                    $idMappings = $this->shopifyQueryService->createAndLinkProducts($this->createProductArrays);
                    foreach ($idMappings as $pimId => $shopifyId) {
                        if ($obj = Concrete::getById($pimId)) {
                            $this->setStoreId($obj, $shopifyId);
                        } else {
                            $this->applicationLogger->error("Error linking remote product to local product. Pimcore id: " . $pimId . " Shopify id: " . $shopifyId, [
                                'component' => $this->configLogName,
                                null,
                            ]);
                        }
                    }
                    $this->applicationLogger->info("Shopify mutations to create products is finished ", [
                        'component' => $this->configLogName,
                        null,
                    ]);
                }
            } catch (Exception $e) {
                $this->applicationLogger->error("Error during Shopify mutation to create products and variants : " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), [
                    'component' => $this->configLogName,
                    null,
                ]);
            }
        }
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
                //updating variantCreate mapping to insert local product Ids after the above create calls
                $createVariantsArrays = [];
                foreach ($this->createVariantsArrays as $index => $variant) {
                    if ($parent = Concrete::getById($index)) {
                        $createVariantsArrays[] = ['productId' => $this->getStoreId($parent), 'variants' => $variant];
                    } else {
                        $this->applicationLogger->error("Error varient's parent does not have a shopify Id. Pimcore id: " . $index, [
                            'component' => $this->configLogName,
                            null,
                        ]);
                    }
                }
                $idMappings = $this->shopifyQueryService->createBulkVariants($createVariantsArrays);
                foreach ($idMappings as $pimId => $shopifyId) {
                    if ($obj = Concrete::getById($pimId)) {
                        $this->setStoreId($obj, $shopifyId);
                    } else {
                        $this->applicationLogger->error("Error linking remote variant to local variant. Pimcore id: " . $pimId . " Shopify id: " . $shopifyId, [
                            'component' => $this->configLogName,
                            null,
                        ]);
                    }
                }
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
        if ($this->addProdsToStore) {
            $this->applicationLogger->info("adding products to stores", [
                'component' => $this->configLogName,
                null,
            ]);
            $this->addProdsToStore = array_unique($this->addProdsToStore);
            $inputArray = [];
            foreach ($this->addProdsToStore as $product) {
                $inputArray[] = [
                    "id" => $this->getStoreId($product),
                    "input" => $this->publicationIds

                ];
            }
            $this->shopifyQueryService->addProductsToStore($inputArray);
            $inputArray = [];
        }
        if ($this->newImages) {
            $this->applicationLogger->info("Start of Shopify mutation to create media", [
                'component' => $this->configLogName,
                null,
            ]);
            $inputArray = [];
            /** @var Asset $image  */
            foreach ($this->newImages as $image) {

                $publicUrl = $image->getFrontendFullPath();

                /* public url required = cdn/pimcore-assets/assets/path */
                /* getFrontEndFullPath() is returning cdn/assets/path */
                $prefix = Config::getSystemConfiguration('assets')['frontend_prefixes']['source'];
                if ($prefix) {
                    if( !str_ends_with($prefix, '/')) {
                        $prefix .= '/';
                    }

                    $publicUrl = $prefix .  $image->getFullPath();
                }

                $inputArray["files"][] = [
                    "originalSource" => $publicUrl,
                    "filename" => $image->getFilename(),
                    "contentType" => "IMAGE",
                    "alt" => strval($image->getId()), //this is used temporarily to map back the image to the asset and is removed in the linking to product mutation below
                    "duplicateResolutionMode" => "REPLACE"
                ];
            }
            $result = $this->shopifyQueryService->createMedia($inputArray);
            $this->applicationLogger->info("Shopify mutation to create media is finished", [
                'component' => $this->configLogName,
                null,
            ]);
            foreach ($result["data"]["fileCreate"]["files"] ?? [] as $mapBack) {
                $uploadedImageAsset = $this->newImages[$mapBack["alt"]] ?? null;
                if ($uploadedImageAsset && $uploadedImageAsset instanceof Asset) {
                    $this->setStoreId($this->newImages[$mapBack["alt"]], $mapBack["id"]);
                } else {
                    $this->applicationLogger->error("Error after Shopify mutation to create media : tried to find asset with id " . $mapBack["alt"] . " but one was not found to link to uploaded image ", [
                        'component' => $this->configLogName,
                        null,
                    ]);
                }
            }
            $inputArray = [];
        }
        if ($this->images) {
            $this->applicationLogger->info("Start of Shopify mutation to update media and add them to products", [
                'component' => $this->configLogName,
                null,
            ]);
            $inputArray = [];
            foreach ($this->images as $data) {


                $publicUrl = $data['image']->getFrontendFullPath();

                /* public url required = cdn/pimcore-assets/assets/path */
                /* getFrontEndFullPath() is returning cdn/assets/path */
                $prefix = Config::getSystemConfiguration('assets')['frontend_prefixes']['source'];
                if ($prefix) {
                    if( !str_ends_with($prefix, '/')) {
                        $prefix .= '/';
                    }

                    $publicUrl = $prefix .  $data['image']->getFullPath();
                }


                $inputArray["files"][] = [
                    "alt" => "",
                    "id" => $this->getStoreId($data["image"]), //even if this was set in the upload image part above this, it will get the new id from the propery
                    "referencesToAdd" => $data["products"],
                    "originalSource" => $publicUrl
                ];
            }
            $result = $this->shopifyQueryService->updateMedia($inputArray);
            $this->applicationLogger->info("End of Shopify mutation to update media and add them to products", [
                'component' => $this->configLogName,
                null,
            ]);
        }
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

    private function processImage(Asset $image, Concrete $object)
    {
        if (!$this->existsInStore($image)) {
            $this->newImages[$image->getId()] = $image;
        }
        $this->images[$image->getId()]["products"][] = $this->getStoreId($object);
        $this->images[$image->getId()]["image"] = $image;
    }
}

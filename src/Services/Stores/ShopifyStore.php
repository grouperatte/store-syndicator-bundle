<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Exception;
use Symfony\Component\Messenger\MessageBusInterface;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Bundle\ApplicationLoggerBundle\FileObject;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyAttachImageMessage;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyUploadImageMessage;
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
    private array $productIdToStoreId;
    public string $configLogName;

    // Assets in PIM are tagged with this status based on the next stage of Shopify Syndication required
    public const STATUS_UPLOAD = 'upload';
    public const STATUS_ATTACH = 'attach';
    public const STATUS_ERROR = 'error';
    public const STATUS_DONE = 'done';


    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
        private ApplicationLogger $applicationLogger,
        private MessageBusInterface $messageBus,
    ) {}

    public function setup(Configuration $config, bool $minimal=false)
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

        if( !$minimal ) {
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

        // avoid setting these fields to empty string because in the Shopify API they are types as "money"
        foreach( ['price', 'compareAtPrice'] as $moneyField ) {
            if( isset($graphQLInput[$moneyField]) && floatval($graphQLInput[$moneyField]) <= 0 ) {
                unset($graphQLInput[$moneyField]);
            }
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
        // each field has its own checks on missing data, and specific remote fields to map to

        foreach ($fields as $field => $value) {

            switch( $field ) {
                case 'stock':  // updateStock is handled separately 
                    break;

                case 'sku':
                    $thisVariantArray['inventoryItem']['sku'] = $value[0] ?: '';
                    break;

                case 'weight':
                    $thisVariantArray['inventoryItem']['measurement']['weight']['value'] = floatval($value[0]);
                    break;

                case 'weightUnit':
                    if( $value[0] ) {
                        $unit = strtoupper($value[0]);
                        // these are the only valid values on Shopify
                        if (!in_array($unit, ['POUNDS', 'OUNCES', 'KILOGRAMS', 'GRAMS'])) {
                            
                            if( $unit == 'KG' ) $unit = 'KILOGRAMS';
                            elseif( $unit == 'OZ' ) $unit = 'OUNCES';
                            elseif( $unit == 'LB' || $unit == 'LBS' ) $unit = 'POUNDS';
                            elseif( $unit == 'G' ) $unit = 'GRAMS';
                            else break; // this will prevent the invalid unit from being sent to Shopify
                        }
                            
                        $thisVariantArray['inventoryItem']['measurement']['weight']['unit'] = $unit;
                    }

                    break;

                case 'cost':
                    $thisVariantArray['inventoryItem']['cost'] = floatval($value[0]);
                    break;

                case 'price':
                    $thisVariantArray['price'] = floatval($value[0]);
                    break;

                case 'tracked':
                    $thisVariantArray['inventoryItem']['tracked'] = boolval($value[0]);
                    break;

                case 'continueSellingOutOfStock':
                    $thisVariantArray['inventoryPolicy'] = $value[0] ? 'CONTINUE' : 'DENY';
                    break;

                case 'title':
                    if( $value[0] ) {
                        $thisVariantArray['optionValues']['name'] = $value[0];  
                        $thisVariantArray['optionValues']['optionName'] = 'Title';
                    }
                    break;

                case 'requiresShipping':
                    $thisVariantArray['inventoryItem']['requiresShipping'] = boolval($value[0]);
                    break;

                default:
                    $thisVariantArray[$field] = $value[0] ?: '';
            }
        }

        // do not send weight without unit
        if( !isset($thisVariantArray['inventoryItem']['measurement']['weight']['unit']) ) {
            unset($thisVariantArray['inventoryItem']['measurement']['weight']['value']);
        } else {
            // do not send unit without weight
            if( $thisVariantArray['inventoryItem']['measurement']['weight']['value'] <= 0 ) {
                // removes both unit and value
                unset($thisVariantArray['inventoryItem']['measurement']['weight']);
            }
        }

        // do not send empty array
        if( isset($thisVariantArray['inventoryItem']['measurement']) && empty($thisVariantArray['inventoryItem']['measurement']['weight']) ) {
            unset($thisVariantArray['inventoryItem']['measurement']['weight']);
        }

        if( isset($thisVariantArray['inventoryItem']['measurement']) && empty($thisVariantArray['inventoryItem']['measurement']) ) {
            unset($thisVariantArray['inventoryItem']['measurement']);
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
                $tmpMetafield["value"] = json_encode($attribute["value"] ?: []);
            } else {
                $tmpMetafield["value"] = $attribute["value"][0] ?: '';
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
                            'fileObject' => new FileObject(file_get_contents($resultFileURL)),
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
                    'fileObject' => new FileObject(json_encode($this->metafieldSetArrays)),
                ]);
                $resultFiles = $this->shopifyQueryService->updateMetafields($this->metafieldSetArrays);
                foreach ($resultFiles as $resultFileURL) {
                    $this->applicationLogger->info("A Shopify mutation to update metafields is finished " . $resultFileURL, [
                        'component' => $this->configLogName,
                        'fileObject' => new FileObject(file_get_contents($resultFileURL)),
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
                $storeId = $this->getStoreId($product);
                $inputArray[] = [
                    "id" => $storeId,
                    "input" => $this->publicationIds

                ];
                $this->productIdToStoreId[$product->getId()] = $storeId;
            }
            $this->shopifyQueryService->addProductsToStore($inputArray);
            $inputArray = [];
        }
        if ($this->newImages) {
            $this->applicationLogger->debug('Populating job queue to upload ' . count($this->newImages) . ' new images', [
                'component' => $this->configLogName,
                'fileObject' => new FileObject(json_encode(['newImagesKeys'=> array_keys($this->newImages), 'productIdToStoreId' => $this->productIdToStoreId])),
            ]);

            /** @var Asset $image  */
            foreach ($this->newImages as $productId => $image) {

                $image->setProperty('TorqSS:ShopifyUploadStatus', 'text', self::STATUS_UPLOAD, false, false);
                $image->save();

                $this->messageBus->dispatch(new ShopifyUploadImageMessage(
                    $this->config->getName(),
                    $image->getId(),
                    $productId,
                    $this->productIdToStoreId[$productId]
                ));
            }
        }

        if ($this->images) {
            $this->applicationLogger->debug('Populating job queue to re-attach ' . count($this->images) . ' images', [
                'component' => $this->configLogName,
                null,
            ]);

            foreach ($this->images as $productId => $image) {
                $image->setProperty('TorqSS:ShopifyUploadStatus', 'text', self::STATUS_UPLOAD, false, false);

                $this->messageBus->dispatch(new ShopifyAttachImageMessage(
                    $this->config->getName(),
                    $image->getProperty('TorqSS:ShopifyFileId'),
                    $this->productIdToStoreId[$productId],
                    'UNKNOWN',
                    $image->getId()
                ));
            }
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
                    'fileObject' => new FileObject(implode("\r\n", $results)),
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

    private function processImage(Asset|null $image, Concrete $object)
    {
        // No image was set for the product, we'll push the product without an image for now.
        if (is_null($image)) {
            return;
        }

        $shopifyFileId = $image->getProperty('TorqSS:ShopifyFileId');

        if (!$shopifyFileId)   // this not being set implies the image has not been uploaded to Shopify
        {
            $this->newImages[$object->getId()] = $image;
            $this->productIdToStoreId[$object->getId()] = $this->getStoreId($object);
        }
        elseif( empty($image->getProperty('TorqSS:ShopifyProductId')) ) // this not being set implies that the image has not been linked on Shopify
        {
            $this->images[$object->getId()] = $image;
            $this->productIdToStoreId[$object->getId()] = $this->getStoreId($object);
        }

        // if both of those properties are set on an image, we will not handle it any further for syndication
    }


    /*
     * @param Asset $image
     *
     * Uploads the main image of the given Asset to Shopify
     * @return array
     * [ ShopifyFileStatus, ShopifyFileID ]
     *
     * This wraps around shopifyQueryService::createImage()
     * Here we load from the Pimcore Asset
     * the public URL of its image, and here we choose to set the Shopify Filename to reference this
     * Pimcore Asset ID.
     */
    public function createImage(Asset $image)
    {
        $publicUrl = $image->getFrontendFullPath();

        return $this->shopifyQueryService->createImage(
            $publicUrl,                                    // the URL sent to Shopify 
            $image->getId() . '-' . $image->getFilename() // the filename sent to Shopify
        );
    }


    /*
     * Updates the image on Shopify to link it to the product on Shopify
     *
     * returns true if image was successfully linked on Shopify
     */
    public function attachImageToProduct(string $shopifyFileId, string $shopifyProductId, string $shopifyFileStatus, int $assetId) : bool
    {
        // If the file status is READY, we can link it to the product
        if( $shopifyFileStatus === 'READY') {
            $shopifyFileStatus = $this->shopifyQueryService->linkImageToProduct($shopifyFileId, $shopifyProductId);

            if( $shopifyFileStatus === self::STATUS_ERROR ) {
                $this->applicationLogger->error("Error attaching READY ($shopifyFileStatus) image to product: " . $shopifyFileId . " to product: " . $shopifyProductId, [
                    'component' => $this->configLogName,
                    null,
                ]);
                return false;
            }

        } else {
            // The file status was not ready, so let's check for an update
            $shopifyFileStatus = $this->shopifyQueryService->linkImageToProduct($shopifyFileId, $shopifyProductId);

            if( $shopifyFileStatus === self::STATUS_ERROR ) {
                $this->applicationLogger->error("Error attaching image to product: " . $shopifyFileId . " to product: " . $shopifyProductId, [
                    'component' => $this->configLogName,
                    null,
                ]);
                return false;
            }
        }

        $this->applicationLogger->debug(
            "AttachImageToProduct: ({$assetId}) updated file status {$shopifyFileStatus}", [
                'component' => $this->configLogName,
                'fileObject' => new FileObject(json_encode([
                    'shopifyFileId' => $shopifyFileId,
                    'shopifyProductId' => $shopifyProductId,
                    'shopifyFileStatus' => $shopifyFileStatus,
                    'isReady' => boolval($shopifyFileStatus === 'READY'),
                    'assetId' => $assetId
                ]))
            ]);

        // if the new $shopifyFileStatus *is* READY, we can successfully linked the image to the product
        if( ($shopifyFileStatus === 'READY') || ($shopifyFileStatus == 1) )
            return true;

        $this->applicationLogger->debug(
            "AttachImageToProduct: ({$assetId}) status {$shopifyFileStatus}; queued for later ", [
                'component' => $this->configLogName,
                'fileObject' => new FileObject(json_encode([
                    'shopifyFileId' => $shopifyFileId,
                    'shopifyProductId' => $shopifyProductId,
                    'shopifyFileStatus' => $shopifyFileStatus,
                    'assetId' => $assetId
                ]))
            ]);

        // if not, we need to retry
        $this->messageBus->dispatch(new ShopifyAttachImageMessage(
            $this->config->getName(),
            $shopifyFileId,
            $shopifyProductId,
            $shopifyFileStatus,
            $assetId
        ));

        return false;
    }

}

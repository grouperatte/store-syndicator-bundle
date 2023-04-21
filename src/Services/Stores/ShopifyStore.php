<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use DateTime;
use Exception;
use Pimcore\Db;
use DateTimeZone;
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
    private array $metafieldSetArrays;
    private array $updateImageMap;
    private array $metafieldTypeDefinitions;
    // private array $productMetafieldsMapping;
    //private array $variantMetafieldsMapping;

    private AttributesService $attributeService;

    public function __construct(
        private ConfigurationRepository $configurationRepository,
        private ConfigurationService $configurationService,
    ) {
        $this->attributeService = new AttributesService();
        $this->shopifyProductLinkingService = new ShopifyProductLinkingService($configurationRepository, $configurationService);
    }

    public function setup(Configuration $config)
    {
        $this->config = $config;
        $remoteStoreName = $this->configurationService->getStoreName($config);
        $this->propertyName = "TorqSS:" . $remoteStoreName . ":shopifyId";

        $configData = $this->config->getConfiguration();
        $configData["ExportLogs"] = [];
        $this->config->setConfiguration($configData);
        $this->config->save();

        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($config);
        $this->shopifyQueryService = new ShopifyQueryService($authenticator);
        $this->metafieldTypeDefinitions = $this->shopifyQueryService->queryMetafieldDefinitions();

        $this->updateProductArrays = [];
        $this->createProductArrays = [];
        $this->updateVariantsArrays = [];
        $this->metafieldSetArrays = [];
        $this->updateImageMap = [];

        Db::get()->query('SET SESSION wait_timeout = ' . 28800); //timeout to 8 hours for this session
    }

    public function getAllProducts()
    {
        // $query = ShopifyGraphqlHelperService::buildProductsQuery();
        // $result = $this->client->query(["query" => $query])->getDecodedBody();
        // while (!$resultFileURL = $this->queryFinished("QUERY")) {
        // }
        // $products = [];
        // if ($resultFileURL != "none") {
        //     $resultFile = fopen($resultFileURL, "r");
        //     while ($productOrMetafield = fgets($resultFile)) {
        //         $productOrMetafield = (array)json_decode($productOrMetafield);
        //         if (array_key_exists("key", $productOrMetafield)) {
        //             $products[$productOrMetafield['__parentId']]['metafields'][$productOrMetafield["key"]] = [
        //                 "namespace" => $productOrMetafield["namespace"],
        //                 "key" => $productOrMetafield["key"],
        //                 "value" => $productOrMetafield["value"],
        //                 "id" => $productOrMetafield['id'],
        //             ];
        //         } elseif (array_key_exists("title", $productOrMetafield)) {
        //             $products[$productOrMetafield["id"]]['id'] = $productOrMetafield["id"];
        //             $products[$productOrMetafield["id"]]['title'] = $productOrMetafield["title"];
        //         }
        //     }
        // }
        // return $products;
    }

    private function getAllVariants()
    {
        // $query = ShopifyGraphqlHelperService::buildVariantsQuery();
        // $result = $this->client->query(["query" => $query])->getDecodedBody();
        // while (!$resultFileURL = $this->queryFinished("QUERY")) {
        // }
        // $variants = [];
        // if ($resultFileURL != "none") {
        //     $resultFile = fopen($resultFileURL, "r");
        //     while ($variantOrMetafield = fgets($resultFile)) {
        //         $variantOrMetafield = (array)json_decode($variantOrMetafield);
        //         if (array_key_exists("key", $variantOrMetafield)) {
        //             $variants[$variantOrMetafield['__parentId']][$variantOrMetafield["namespace"] . "." . $variantOrMetafield["key"]] = $variantOrMetafield['id'];
        //         }
        //     }
        // }
        // return $variants;
    }


    public function updateProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreProductId($object);

        $graphQLInput = [];
        $graphQLInput["title"] = $fields["title"][0] ?? $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
                $metafield["ownerId"] = $remoteId;
                $this->metafieldSetArrays[] = $metafield;
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = ["update", $image];
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInput[$field] = $value[0];
        }
        $graphQLInput["id"] = $remoteId;
        $graphQLInput["handle"] = $graphQLInput["title"] . "-" . $remoteId;
        $this->updateProductArrays[$object->getId()] = $graphQLInput;
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $fields["metafields"][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($object->getId())],
            "namespace" => "custom",
        ];
        $graphQLInput = [];
        $graphQLInput["title"] = $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $graphQLInput["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["product"]);
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = ["create", $image];
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInput[$field] = $value[0];
        }
        $this->createProductArrays[$object->getId()] = $graphQLInput;
    }

    public function createVariant(Concrete $parent, Concrete $child): void
    {
        $fields = $this->getAttributes($child);

        $fields['variant metafields'][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($child->getId())],
            "namespace" => "custom",
        ];

        foreach ($fields['variant metafields'] as $attribute) {
            $graphQLInput["metafields"][] = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
        }

        foreach ($fields['base variant'] as $field => $value) {
            if ($field == 'weight') { //wants this as a non-string wrapped number
                $value[0] = (float)$value[0];
            }
            $graphQLInput[$field] = $value[0];
        }

        if (!isset($graphQLInput["title"])) {
            $graphQLInput["title"] = $child->getKey();
        }
        if (!isset($graphQLInput["options"])) {
            $graphQLInput["options"] = [$graphQLInput["title"]];
        }

        if ($this->existsInStore($parent)) {
            $this->updateProductArrays[$parent->getId()]["variants"][] = $graphQLInput;
        } else {
            $this->createProductArrays[$parent->getId()]["variants"][] = $graphQLInput;
        }
    }

    public function updateVariant(Concrete $parent, Concrete $child): void
    {
        $remoteId = $this->getStoreProductId($child);

        $fields = $this->getAttributes($child);

        $fields['variant metafields'][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($child->getId())],
            "namespace" => "custom",
        ];

        foreach ($fields['variant metafields'] as $attribute) {
            $metafield = $this->createMetafield($attribute, $this->metafieldTypeDefinitions["variant"]);
            $metafield["ownerId"] = $remoteId;
            $this->metafieldSetArrays[] = $metafield;
        }

        foreach ($fields['base variant'] as $field => $value) {
            if ($field == 'weight') { //wants this as a non-string wrapped number
                $value[0] = (float)$value[0];
            }
            $thisVariantArray[$field] = $value[0];
        }

        if (!isset($thisVariantArray["title"])) {
            $thisVariantArray["title"] = $child->getKey();
        }
        if (!isset($thisVariantArray["options"])) {
            $thisVariantArray["options"] = [$thisVariantArray["title"]];
        }

        $thisVariantArray["id"] = $remoteId;

        $this->updateVariantsArrays[] = $thisVariantArray;
    }

    /**
     * @param array $attribute [key => *, namespace => *, value => *]
     * @param array $mappingArray $this->metafieldTypeDefinitions["variant"/"product"]
     * @return array full metafield shopify object
     **/
    private function createMetafield($attribute, $mappingArray)
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

    public function commit(): Models\CommitResult
    {
        $commitResults = new Models\CommitResult();
        $changesStartTime = new DateTime('now',  new DateTimeZone("UTC"));

        //upload new images and add the src to 
        if (isset($this->updateImageMap)) {
            try {
                $pushArray = [];
                $mapBackArray = [];
                //upload assets with no shopify url
                foreach ($this->updateImageMap as $productId => $product) {
                    foreach ($product as $image) {
                        $updateOrCreate = $image[0];
                        $image = $image[1];
                        if (!$image->getProperty(self::IMAGEPROPERTYNAME)) {
                            $pushArray[] = ["filename" => $image->getLocalFile(), "resource" => "PRODUCT_IMAGE"];
                        }
                        $mapBackArray[$image->getLocalFile()] = [$image, $productId, $updateOrCreate];
                    }
                }
                $remoteFileKeys = $this->shopifyQueryService->uploadFiles($pushArray);
                $this->addLogRow("uploaded images", count($remoteFileKeys));
                //and save their url's
                foreach ($remoteFileKeys as $fileName => $remoteFileKey) {
                    /** @var Image $image */
                    $image = $mapBackArray[$fileName][0];
                    $image->setProperty(self::IMAGEPROPERTYNAME, "text", $remoteFileKey["url"]);
                    $image->save();
                }
                //add them to update/create queries
                foreach ($mapBackArray as $mapBackImage) {
                    if ($mapBackImage[2] == "create") {
                        $this->createProductArrays[$mapBackImage[1]]["images"][] = ["src" => $mapBackImage[0]->getProperty(self::IMAGEPROPERTYNAME)];
                    } elseif ($mapBackImage[2] == "update") {
                        $this->updateProductArrays[$mapBackImage[1]]["images"][] = ["src" => $mapBackImage[0]->getProperty(self::IMAGEPROPERTYNAME)];
                    }
                }
            } catch (Exception $e) {
                $commitResults->addError("error during image pushing in commit: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            }
        }

        if ($this->createProductArrays) {
            //create unmade products
            try {
                $resultFiles = $this->shopifyQueryService->createProducts($this->createProductArrays);
                foreach ($resultFiles as $resultFileURL) {
                    $this->addLogRow("create product & variant result file", $resultFileURL);
                }
            } catch (Exception $e) {
                $commitResults->addError("error during product creating in commit: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            }
        }

        //also takes care of creating variants
        if ($this->updateProductArrays) {
            try {
                $resultFileURL = $this->shopifyQueryService->updateProducts($this->updateProductArrays);
                $this->addLogRow("update products result file", $resultFileURL);
            } catch (Exception $e) {
                $commitResults->addError("error during product updating in commit: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            }
        }

        if ($this->updateVariantsArrays) {
            try {
                $resultFiles = $this->shopifyQueryService->updateVariants($this->updateVariantsArrays);
                foreach ($resultFiles as $resultFileURL) {
                    $this->addLogRow("update variant result file", $resultFileURL);
                }
            } catch (Exception $e) {
                $commitResults->addError("error during variant updating in commit: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            }
        }

        if ($this->metafieldSetArrays) {
            try {
                $resultFiles = $this->shopifyQueryService->updateMetafields($this->metafieldSetArrays);
                foreach ($resultFiles as $resultFileURL) {
                    $this->addLogRow("update metafield result file", $resultFileURL);
                }
            } catch (Exception $e) {
                $commitResults->addError("error during metafield setting in commit: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            }
        }
        $this->config->save();
        $this->shopifyProductLinkingService->link($this->config, $changesStartTime);
        return $commitResults;
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Exception;
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
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;

class ShopifyStore extends BaseStore
{
    const PROPERTTYNAME = "ShopifyProductId"; //override parent value
    const IMAGEPROPERTYNAME = "ShopifyImageURL";
    private $updateGraphQLStrings;
    private $createGraphQLStrings;
    private $variantMapping;
    private array $createObjs;
    private Session $session;
    private GraphQl $client;
    private array $metafieldTypeDefinitions;
    private array $productMetafieldsMapping;
    private array $variantMetafieldsMapping;
    private array $updateImageMap;

    private AttributesService $attributeService;

    public function __construct()
    {
        $this->attributeService = new AttributesService();
    }

    public function setup(Configuration $config)
    {
        $this->config = $config;
        $configData = $this->config->getConfiguration();
        $configData["ExportLogs"] = [];
        $this->config->setConfiguration($configData);
        $this->config->save();

        $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($config);
        $result = $authenticator->connect();
        if ($authenticator instanceof AbstractAuthenticator) {
            $authenticator = $authenticator->connect();
        } else {
            throw new Exception("invalid object type in access tab");
        }
        $this->session = $authenticator['session'];
        $this->client = $authenticator['client'];

        $this->productMetafieldsMapping = $this->getAllProducts();
        $this->variantMetafieldsMapping = $this->getAllVariants();
        $this->metafieldTypeDefinitions = $this->getMetafields();
    }

    public function getMetafields()
    {
        $defs = $this->attributeService->getRemoteFields($this->client);
        $defMap = [];
        foreach ($defs as $def) {
            if (array_key_exists("fieldDefType", $def)) {
                $defMap[$def["name"]] = $def["fieldDefType"];
            }
        }
        return $defMap;
    }

    public function getAllProducts()
    {
        $query = ShopifyGraphqlHelperService::buildProductsQuery();
        $result = $this->client->query(["query" => $query])->getDecodedBody();
        while (!$resultFileURL = $this->queryFinished("QUERY")) {
        }
        $products = [];
        if ($resultFileURL != "none") {
            $resultFile = fopen($resultFileURL, "r");
            while ($productOrMetafield = fgets($resultFile)) {
                $productOrMetafield = (array)json_decode($productOrMetafield);
                if (array_key_exists("key", $productOrMetafield)) {
                    $products[$productOrMetafield['__parentId']]['metafields'][$productOrMetafield["key"]] = [
                        "namespace" => $productOrMetafield["namespace"],
                        "key" => $productOrMetafield["key"],
                        "value" => $productOrMetafield["value"],
                        "id" => $productOrMetafield['id'],
                    ];
                } elseif (array_key_exists("title", $productOrMetafield)) {
                    $products[$productOrMetafield["id"]]['id'] = $productOrMetafield["id"];
                    $products[$productOrMetafield["id"]]['title'] = $productOrMetafield["title"];
                }
            }
        }
        return $products;
    }

    private function getAllVariants()
    {
        $query = ShopifyGraphqlHelperService::buildVariantsQuery();
        $result = $this->client->query(["query" => $query])->getDecodedBody();
        while (!$resultFileURL = $this->queryFinished("QUERY")) {
        }
        $variants = [];
        if ($resultFileURL != "none") {
            $resultFile = fopen($resultFileURL, "r");
            while ($variantOrMetafield = fgets($resultFile)) {
                $variantOrMetafield = (array)json_decode($variantOrMetafield);
                if (array_key_exists("key", $variantOrMetafield)) {
                    $variants[$variantOrMetafield['__parentId']][$variantOrMetafield["namespace"] . "." . $variantOrMetafield["key"]] = $variantOrMetafield['id'];
                }
            }
        }
        return $variants;
    }

    /*
        Not currently used
    */
    public function getProduct(string $id)
    {
        try {
            return Product::find($this->session, $id);
        } catch (RestResourceRequestException $e) {
            return null;
        }
    }

    public function updateProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreProductId($object);

        $graphQLInputString = [];
        $graphQLInputString["title"] = $fields["title"][0] ?? $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                if (array_key_exists($remoteId, $this->productMetafieldsMapping))
                    $graphQLInputString["metafields"][] = $this->createMetafield($attribute, $this->productMetafieldsMapping[$remoteId]);
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = $image;
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInputString[$field] = $value[0];
        }
        $graphQLInputString["id"] = $remoteId;
        $graphQLInputString["handle"] = $graphQLInputString["title"] . "-" . $remoteId;
        $this->updateGraphQLStrings .= json_encode(["input" => $graphQLInputString]) . PHP_EOL;
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $fields["metafields"][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($object->getId())],
            "namespace" => "custom",
        ];
        $graphQLInputString = [];
        $graphQLInputString["title"] = $object->getKey();
        if (isset($fields['metafields'])) {
            foreach ($fields['metafields'] as $attribute) {
                $graphQLInputString["metafields"][] = $this->createMetafield($attribute, null);
            }
            unset($fields['metafields']);
        }
        if (isset($fields["Images"])) {
            /** @var Image $image */
            foreach ($fields["Images"] as $image) {
                $this->updateImageMap[$object->getId()][] = $image;
            }
            unset($fields["Images"]);
        }
        foreach ($fields['base product'] as $field => $value) {
            $graphQLInputString[$field] = $value[0];
        }
        $this->createGraphQLStrings .= json_encode(["input" => $graphQLInputString]) . PHP_EOL;
        $this->createObjs[] = $object;
    }

    public function processVariant(Concrete $parent, Concrete $child): void
    {
        $fields = $this->getAttributes($child);
        if ($this->existsInStore($child)) {
            $thisVariantArray["id"] = $this->getStoreProductId($child);
        }
        $fields['variant metafields'][] = [
            "fieldName" => "pimcore_id",
            "value" => [strval($child->getId())],
            "namespace" => "custom",
        ];
        $metafields = [];
        foreach ($fields['variant metafields'] as $metafield) {
            //if we pulled this variant metafield, get its id
            if (
                $this->existsInStore($child) &&
                array_key_exists($this->getStoreProductId($child), $this->variantMetafieldsMapping) &&
                array_key_exists($metafield["namespace"] . "." . $metafield["fieldName"], $this->variantMetafieldsMapping[$this->getStoreProductId($child)])
            ) {
                $tmpmetafield = [
                    "id" => $this->variantMetafieldsMapping[$this->getStoreProductId($child)][$metafield["namespace"] . "." . $metafield["fieldName"]],
                    "value" => $metafield["value"]
                ];
                if (array_key_exists($metafield["namespace"] . "." . $metafield["fieldName"], $this->metafieldTypeDefinitions)) {
                    $tmpmetafield["type"] = $this->metafieldTypeDefinitions[$metafield["namespace"] . "." .  $metafield["fieldName"]];
                    if (str_contains($tmpmetafield["type"], "list.")) {
                        $tmpmetafield["value"] = json_encode($metafield["value"]);
                    } else {
                        $tmpmetafield["value"] = $metafield["value"][0];
                    }
                } else {
                    $tmpmetafield["value"] = $metafield["value"][0];
                }
                $metafields[] = $tmpmetafield;
            } else { //its a new variant / metafield
                $metafields[] = $this->createMetafield($metafield, null);
            }
        }
        $thisVariantArray["metafields"] = $metafields;
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

        $this->variantMapping[$parent->getId()][$child->getId()] = $thisVariantArray;
    }

    private function createMetafield($attribute, $mapping)
    {
        if (array_key_exists($attribute["namespace"] . "." .  $attribute["fieldName"], $this->metafieldTypeDefinitions)) {
            if (str_contains($this->metafieldTypeDefinitions[$attribute["namespace"] . "." .  $attribute["fieldName"]], "list.")) {
                $tmpMetafield = [
                    "key" => $attribute["fieldName"],
                    "value" => json_encode($attribute["value"]),
                    "namespace" => $attribute["namespace"],
                ];
            } else {
                $tmpMetafield = [
                    "key" => $attribute["fieldName"],
                    "value" => $attribute["value"][0],
                    "namespace" => $attribute["namespace"],
                ];
            }
            $tmpMetafield["type"] = $this->metafieldTypeDefinitions[$attribute["namespace"] . "." .  $attribute["fieldName"]];
        } else {
            $tmpMetafield = [
                "key" => $attribute["fieldName"],
                "value" => $attribute["value"][0],
                "namespace" => $attribute["namespace"],
            ];
        }
        if ($mapping && array_key_exists($attribute["fieldName"], $mapping["metafields"])) {
            $tmpMetafield["id"] = $mapping["metafields"][$attribute["fieldName"]]["id"];
        }

        return $tmpMetafield;
    }

    public function commit(): Models\CommitResult
    {
        $commitResults = new Models\CommitResult();
        if ($this->createGraphQLStrings) {
            //create unmade products
            $file = $this->makeFile($this->createGraphQLStrings);
            $filename = stream_get_meta_data($file)['uri'];
            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $this->addLogRow("create products file", $remoteFileKeys[$filename]["url"]);
            $remoteFileKey = $remoteFileKeys[$filename]["key"];
            fclose($file);

            $product_create_query = ShopifyGraphqlHelperService::buildCreateQuery($remoteFileKey);

            $result = $this->client->query(["query" => $product_create_query])->getDecodedBody();
            $this->addLogRow("create products result", json_encode($result));

            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            }
            $this->addLogRow("create products result file", $resultFileURL);
            //map created products
            $mappingQuery = ShopifyGraphqlHelperService::buildProductIdMappingQuery();
            $result = $this->client->query(["query" => $mappingQuery])->getDecodedBody();
            $this->addLogRow("created products reverse mapping result", json_encode($result));
            while (!$resultFileURL = $this->queryFinished("QUERY")) {
            }
            $this->addLogRow("created products reverse mapping result file", $resultFileURL);
            $resultFile = fopen($resultFileURL, "r");
            while ($productOrMetafield = fgets($resultFile)) {
                $productOrMetafield = (array)json_decode($productOrMetafield);
                if (array_key_exists("key", $productOrMetafield) && $productOrMetafield["key"] == "pimcore_id") {
                    if ($productObj = Concrete::getById($productOrMetafield["value"])) {
                        $this->setStoreProductId($productObj, $productOrMetafield['__parentId']);
                    }
                }
            }
        }

        if ($this->updateGraphQLStrings) {
            $file = $this->makeFile($this->updateGraphQLStrings);
            $filename = stream_get_meta_data($file)['uri'];
            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $this->addLogRow("update products file", $remoteFileKeys[$filename]["url"]);
            $remoteFileKey = $remoteFileKeys[$filename]["key"];
            fclose($file);

            $product_update_query = ShopifyGraphqlHelperService::buildUpdateQuery($remoteFileKey);
            $result = $this->client->query(["query" => $product_update_query])->getDecodedBody();
            $this->addLogRow("update products result", json_encode($result));

            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            }
            $this->addLogRow("update products result file", $resultFileURL);
        }

        if (isset($this->updateImageMap)) {
            $pushArray = [];
            $mapBackArray = [];
            //upload assets with no shopify url
            foreach ($this->updateImageMap as $product) {
                foreach ($product as $image) {
                    if (!$image->getProperty(self::IMAGEPROPERTYNAME)) {
                        $mapBackArray[$image->getLocalFile()] = $image;
                        $pushArray[] = ["filename" => $image->getLocalFile(), "resource" => "PRODUCT_IMAGE"];
                    }
                }
            }
            $remoteFileKeys = $this->uploadFiles($pushArray);
            $this->addLogRow("uploaded images", count($remoteFileKeys));
            //and save their url's
            foreach ($remoteFileKeys as $fileName => $remoteFileKey) {
                /** @var Image $image */
                $image = $mapBackArray[$fileName];
                $image->setProperty(self::IMAGEPROPERTYNAME, "text", $remoteFileKey["url"]);
                $image->save();
            }

            //build query variables
            $createMediaQuery = "";
            foreach ($this->updateImageMap as $product => $imagesArray) {
                $object = Concrete::getById($product);
                $images = [];
                /** @var Image $image */
                foreach ($imagesArray as $image) {
                    $images[] = [
                        "src" => $image->getProperty(self::IMAGEPROPERTYNAME)
                    ];
                }
                $createMediaQuery .= json_encode([
                    "input" => [
                        "id" => $object->getProperty(self::PROPERTTYNAME),
                        "images" => $images
                    ]
                ]) . PHP_EOL;
            }

            //run bulk query
            $file = $this->makeFile($createMediaQuery);
            $filename = stream_get_meta_data($file)['uri'];
            $remoteKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);

            $bulkParamsFilekey = $remoteKeys[$filename]["key"];
            fclose($file);
            $imagesCreateQuery = ShopifyGraphqlHelperService::buildCreateMediaQuery($bulkParamsFilekey);

            $results = $this->client->query(["query" => $imagesCreateQuery])->getDecodedBody();
            $this->addLogRow("update product images result", json_encode($results));
            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            }
            $this->addLogRow("update product images result file", $resultFileURL);
        }

        if (isset($this->variantMapping)) {
            $file = tmpfile();
            foreach ($this->variantMapping as $parentId => $variantMap) {
                fwrite($file, json_encode(["input" => [
                    "id" => $this->getStoreProductId(Concrete::getById($parentId)),
                    "variants" => array_values($variantMap)
                ]]) . PHP_EOL);
                if (fstat($file)["size"] >= 15000000) { //at 2mb the file upload will fail
                    $filename = stream_get_meta_data($file)['uri'];

                    $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
                    $this->addLogRow("update product variants file", $remoteFileKeys[$filename]["url"]);
                    $remoteFileKey = $remoteFileKeys[$filename]["key"];
                    $variantQuery = ShopifyGraphqlHelperService::buildUpdateVariantsQuery($remoteFileKey);
                    $result = $this->client->query(["query" => $variantQuery])->getDecodedBody();
                    $this->addLogRow("update product variants result", json_encode($result));
                    fclose($file);
                    $file = tmpfile();
                    while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                    }
                    $this->addLogRow("update product variants result file", $resultFileURL);
                }
            }
            if (fstat($file)["size"] > 0) { //if there are any variants in here
                $filename = stream_get_meta_data($file)['uri'];

                $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
                $this->addLogRow("update product variants file", $remoteFileKeys[$filename]["url"]);
                $remoteFileKey = $remoteFileKeys[$filename]["key"];
                $variantQuery = ShopifyGraphqlHelperService::buildUpdateVariantsQuery($remoteFileKey);
                $result = $this->client->query(["query" => $variantQuery])->getDecodedBody();
                $this->addLogRow("update product variants result", json_encode($result));
                fclose($file);
                $file = tmpfile();
                while (!$resultFileURL = $this->queryFinished("MUTATION")) {
                }
                $this->addLogRow("update product variants result file", $resultFileURL);
            }
            //map created variants
            $variantMappingQuery = ShopifyGraphqlHelperService::buildVariantIdMappingQuery();
            $result = $this->client->query(["query" => $variantMappingQuery])->getDecodedBody();
            $this->addLogRow("product variants reverse mapping result", json_encode($result));
            while (!$resultFileURL = $this->queryFinished("QUERY")) {
            }
            $this->addLogRow("product variants reverse mapping result file", $resultFileURL);
            $resultFile = fopen($resultFileURL, "r");
            while ($variantOrMetafield = fgets($resultFile)) {
                $variantOrMetafield = (array)json_decode($variantOrMetafield);
                if (array_key_exists("key", $variantOrMetafield) && $variantOrMetafield["key"] == "pimcore_id") {
                    if ($variantObj = Concrete::getById($variantOrMetafield["value"])) {
                        $this->setStoreProductId($variantObj, $variantOrMetafield['__parentId']);
                    }
                }
            }
        }
        $this->config->save();
        return $commitResults;
    }

    private function makeFile($content)
    {
        $file = tmpfile();
        fwrite($file, $content);
        return $file;
    }

    /**
     * uploads the files at the strings you provide
     * @param array<array<string, string>> $var [[filename, resource]..]
     * @return array<array<string, string>> [[filename => [url, remoteFileKey]..]
     **/
    private function uploadFiles(array $files): array
    {
        //build query and query variables 
        $query = ShopifyGraphqlHelperService::buildFileUploadQuery();
        $variables["input"] = [];
        $stagedUploadUrls = [];
        $count = 0;
        foreach ($files as $file) {
            $filepatharray = explode("/", $file["filename"]);
            $filename = end($filepatharray);
            $variables["input"][] = [
                "filename" => $filename,
                "resource" => $file["resource"],
                "mimeType" => mime_content_type($file["filename"]),
                //"mimeType" => "text/jsonl",
                "httpMethod" => "POST",
            ];
            $count++;
            if ($count % 200 == 0) { //if the query asks for more it will fail so loop the call if needed. 
                $response = $this->client->query(["query" => $query, "variables" => $variables])->getDecodedBody();
                $stagedUploadUrls = array_merge($stagedUploadUrls, $response["data"]["stagedUploadsCreate"]["stagedTargets"]);
                $count = 0;
                $variables["input"] = [];
            }
        }
        if ($count > 0) {
            $response = $this->client->query(["query" => $query, "variables" => $variables])->getDecodedBody();
            $stagedUploadUrls = array_merge($stagedUploadUrls, $response["data"]["stagedUploadsCreate"]["stagedTargets"]);
        }

        //upload all the files
        $fileKeys = [];
        foreach ($stagedUploadUrls as $fileInd => $uploadTarget) {
            $file = $files[$fileInd];
            $filepatharray = explode("/", $file["filename"]);
            $filename = end($filepatharray);

            $curl_opt_url = $uploadTarget["url"];
            $parameters = $uploadTarget["parameters"];
            $curl_key = $parameters[3]["value"];
            $curl_policy = $parameters[8]["value"];
            $curl_x_goog_credentials = $parameters[5]["value"];
            $curl_x_goog_algorithm = $parameters[6]["value"];
            $curl_x_goog_date = $parameters[4]["value"];
            $curl_x_goog_signature = $parameters[7]["value"];

            //send upload
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $curl_opt_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            $mimetype = mime_content_type($file["filename"]);
            $post = array(
                'key' => $curl_key,
                'x-goog-credential' => $curl_x_goog_credentials,
                'x-goog-algorithm' => $curl_x_goog_algorithm,
                'x-goog-date' => $curl_x_goog_date,
                'x-goog-signature' => $curl_x_goog_signature,
                'policy' => $curl_policy,
                'acl' => 'private',
                'Content-Type' => $mimetype,
                'success_action_status' => '201',
                'file' => new \CURLFile($file["filename"], $mimetype, $filename)
            );
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                echo 'Error:' . curl_error($ch);
            }
            $arr_result = simplexml_load_string(
                $result,
            );
            curl_close($ch);
            $fileKeys[$file["filename"]] = ["url" => (string) $arr_result->Location, "key" => (string) $arr_result->Key];
        }

        return $fileKeys;
    }

    public function queryFinished($queryType): bool|string
    {
        $query = ShopifyGraphqlHelperService::buildQueryFinishedQuery($queryType);
        $response = $this->client->query(["query" => $query])->getDecodedBody();
        if ($response['data']["currentBulkOperation"] && $response['data']["currentBulkOperation"]["completedAt"]) {
            return $response['data']["currentBulkOperation"]["url"] ?? "none"; //if the query returns nothing
        } else {
            return false;
        }
    }
}

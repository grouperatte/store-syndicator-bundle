<?php

namespace TorqIT\StoreSyndicatorBundle\Utility;

use Exception;
use Pimcore\Logger;
use Shopify\Clients\Graphql;
use GraphQL\Error\SyntaxError;
use Pimcore\Bundle\ApplicationLoggerBundle\FileObject;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyGraphqlHelperService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

/**
 * class to make queries to shopify and proccess their result for you into readable arrays
 */
class ShopifyQueryService
{
    const MAX_QUERY_OBJS = 250;


    private Graphql $graphql;
    public function __construct(
        ShopifyAuthenticator $abstractAuthenticator,
        private \Psr\Log\LoggerInterface $customLogLogger,
        private string $configLogName
    ) {
        $this->graphql = $abstractAuthenticator->connect()['client'];
    }

    public function createMedia(array $inputArray): array
    {
        return $this->runQuery(ShopifyGraphqlHelperService::buildCreateMediaQuery(), $inputArray);
    }

    public function updateMedia(array $inputArray): array
    {
        return $this->runQuery(ShopifyGraphqlHelperService::buildUpdateMediaQuery(), $inputArray);
    }

    /**
     * query all products and variants and the requested metafield
     *
     * @param string $query will run this query if provided to allow for custom variant queries like created_at.
     * @return array
     **/
    public function queryForLinking($query): array
    {
        $result = $this->runQuery($query);

        if (!empty($result['data']['bulkOperationRunQuery']['bulkOperation']) && empty($result['data']['bulkOperationRunQuery']['bulkOperation']['userErrors'])) {
            $gid = $result['data']['bulkOperationRunQuery']['bulkOperation']['id'];
            $this->customLogLogger->info("queryForLinking : " . $gid, ['component' => $this->configLogName]);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            $resultFileURL = ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        } else {
            $this->customLogLogger->info(print_r($result, true), ['component' => $this->configLogName]);
            throw new Exception("Error during query");
            return [];
        }

        $formattedResults = [];

        if ($resultFileURL == 'none') { //there were no variants returned (also not an error though)
            return $formattedResults;
        }
        $resultFile = fopen($resultFileURL, "r");
        while ($productOrVariant = fgets($resultFile)) {
            $productOrVariant = json_decode($productOrVariant, true);
            if (!isset($productOrVariant["title"]) || $productOrVariant["title"] !== "Default Title") {
                if (isset($productOrVariant["__parentId"]) && isset($formattedResults[$productOrVariant["__parentId"]])) {
                    $formattedResults[$productOrVariant["__parentId"]]['variants'][$productOrVariant["id"]] = $productOrVariant;
                } else {
                    $formattedResults[$productOrVariant["id"]] = $productOrVariant;
                }
            }
        }
        return $formattedResults;
    }

    public function queryMetafieldDefinitions()
    {

        $data = [
            "product" => [],
            "variant" => []
        ];
        $query = ShopifyGraphqlHelperService::buildMetafieldsQuery();
        $response = $this->runQuery($query);
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data["product"][$node["node"]["namespace"] . "." . $node["node"]["key"]] = [
                "namespace" => $node["node"]["namespace"],
                "key" => $node["node"]["key"],
                "type" => $node["node"]["type"]["name"]
            ];
        }

        //get variant metafields
        $query = ShopifyGraphqlHelperService::buildVariantMetafieldsQuery();
        $response = $this->runQuery($query);
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data["variant"][$node["node"]["namespace"] . "." . $node["node"]["key"]] = [
                "namespace" => $node["node"]["namespace"],
                "key" => $node["node"]["key"],
                "type" => $node["node"]["type"]["name"]
            ];
        }
        return $data;
    }

    public function updateProducts(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $inputObj) {
            fwrite($file, json_encode($inputObj) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 20mb the file upload will fail
                $resultFiles[] = $this->pushProductUpdateFile($file);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) {
            $resultFiles[] = $this->pushProductUpdateFile($file);
            fclose($file);
        }

        return $resultFiles;
    }
    private function pushProductUpdateFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];

        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $product_update_query = ShopifyGraphqlHelperService::buildUpdateQuery($remoteFileKey);
        $result = $this->runQuery($product_update_query);

        if (!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])) {
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("updateProducts: " . $gid, [
                'component' => $this->configLogName,
                'fileObject' => new FileObject(stream_get_contents($file)),
            ]);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        } else {
            return "Error in query";
        }
    }
    // public function updateProducts(array $inputArray)
    // {
    //     $inputString = "";
    //     foreach ($inputArray as $inputObj) {
    //         $inputString .= json_encode($inputObj) . PHP_EOL;
    //     }
    //     $file = $this->makeFile($inputString);
    //     $filename = stream_get_meta_data($file)['uri'];
    //     $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
    //     $remoteFileKey = $remoteFileKeys[$filename]["key"];
    //     fclose($file);

    //     $product_update_query = ShopifyGraphqlHelperService::buildUpdateQuery($remoteFileKey);
    //     $result = $this->runQuery($product_update_query);

    //     if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
    //         $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
    //         $this->customLogLogger->info("updateProducts: ".$gid);
    //         while (!$queryResult = $this->checkQueryProgress($gid)) {
    //             sleep(1);
    //         }
    //         return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
    //     }else{
    //         return "Error in query";
    //     }
    // }


    public function createAndLinkProducts(array $inputArray)
    {
        $idMappings = [];
        $file = tmpfile();
        foreach ($inputArray as $inputObj) {
            fwrite($file, json_encode($inputObj) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 20mb the file upload will fail
                $resultFile = $this->pushProductCreateFile($file);
                $this->customLogLogger->info("create products mutation sent data file", ["fileObject" => new FileObject(stream_get_contents($file, offset: 0)), 'component' => $this->configLogName]);
                $this->customLogLogger->info("create products mutation result file", ["fileObject" => $resultFile, 'component' => $this->configLogName]);
                $this->linkPushedProducts($idMappings, $resultFile);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) {
            $resultFile = $this->pushProductCreateFile($file);
            $this->customLogLogger->info("create products mutation sent data file", ["fileObject" => new FileObject(stream_get_contents($file, offset: 0)), 'component' => $this->configLogName]);
            $this->customLogLogger->info("create products mutation result file", ["fileObject" => $resultFile, 'component' => $this->configLogName]);
            $this->linkPushedProducts($idMappings, $resultFile);
            fclose($file);
        }

        return $idMappings;
    }

    private function pushProductCreateFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];

        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $product_update_query = ShopifyGraphqlHelperService::buildCreateProductsQuery($remoteFileKey);
        $result = $this->runQuery($product_update_query);

        if (!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])) {
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("createProducts: " . $gid, ['component' => $this->configLogName]);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        } else {
            return "Error in query";
        }
    }

    private function linkPushedProducts(array &$existingIdMappings, $file)
    {
        //pimcore id => shopify id
        $fileContent = file_get_contents($file);
        $fileContent = rtrim(str_replace("\n", ",", $fileContent), ",");
        $fileContent = json_decode("[" . $fileContent . "]", true);
        foreach ($fileContent as $product) {
            if (isset($product["data"]["productCreate"]["product"]["metafield"]["value"])) {
                $existingIdMappings[$product["data"]["productCreate"]["product"]["metafield"]["value"]] = $product["data"]["productCreate"]["product"]["id"];
            } else {
                $this->customLogLogger->error("Error Linking Created Product: no Pimcore Id found in metafields so a published product is now unlinked", ['component' => $this->configLogName]);
            }
        }
    }

    public function updateBulkVariants(array $inputArray)
    {
        foreach ($inputArray as $key => $input) {
            $variables = ['productId' => $key, 'variants' => $input];
            $this->pushUpdateBulkVariantQuery(ShopifyGraphqlHelperService::buildUpdateBulkVariantQuery(), $variables);
        }
    }

    private function pushUpdateBulkVariantQuery($queryString, $input)
    {
        try {
            $result = $this->runQuery($queryString, $input);
        } catch (Exception $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), ['component' => $this->configLogName]);
        }
    }

    public function createBulkVariants(array $inputArray)
    {
        $queryString = ShopifyGraphqlHelperService::buildCreateBulkVariantQuery();
        $idMappings = [];
        foreach ($inputArray as $input) {
            try {
                $result = $this->runQuery($queryString, $input);
                $this->linkPushedVariants($result, $idMappings);
            } catch (Exception $e) {
                $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), ['component' => $this->configLogName]);
            }
        }
        return $idMappings;
    }

    private function linkPushedVariants($result, &$existingIdMappings)
    {
        if( !isset($result['data']['productVariantsBulkCreate']['productVariants']) )
            return;

        foreach ( ($result["data"]["productVariantsBulkCreate"]["productVariants"] ?: []) as $variant) {
            if (isset($variant["metafield"]["value"])) {
                $existingIdMappings[$variant["metafield"]["value"]] = $variant["id"];
            } else {
                $this->customLogLogger->error("Error Linking Created Variants: no Pimcore Id found in metafields so a published variant is now unlinked", ['component' => $this->configLogName]);
            }
        }
    }

    public function getSalesChannels(): array
    {
        $query = ShopifyGraphqlHelperService::buildSalesChannelsQuery();
        $response = $this->graphql->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["publications"]["edges"] as $node) {
            $data[] = ["publicationId" => $node["node"]["id"]];
        }
        return $data;
    }

    //used to link existing products to the selected stores
    public function addProductsToStore(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $inputObj) {
            fwrite($file, json_encode($inputObj) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 20mb the file upload will fail
                $resultFiles[] = $this->pushAddProductToStoreFile($file);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) {
            $resultFiles[] = $this->pushAddProductToStoreFile($file);
            fclose($file);
        }

        return $resultFiles;
    }

    private function pushAddProductToStoreFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];

        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $product_set_store_query = ShopifyGraphqlHelperService::buildSetProductStoreIdQuery($remoteFileKey);
        $result = $this->runQuery($product_set_store_query);

        if (!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])) {
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("add product to store: " . $gid, ['component' => $this->configLogName]);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        } else {
            return "Error in query";
        }
    }

    public function updateMetafields(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $metafieldArray) {
            fwrite($file, json_encode(["metafields" => $metafieldArray]) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 2mb the file upload will fail
                $resultFiles[] = $this->pushMetafieldUpdateFile($file);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) { //if there are any variants in here
            $resultFiles[] = $this->pushMetafieldUpdateFile($file);
            fclose($file);
        }
        return $resultFiles;
    }

    private function pushMetafieldUpdateFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];
        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $metafieldSetQuery = ShopifyGraphqlHelperService::buildMetafieldSetQuery($remoteFileKey);
        $result = $this->runQuery($metafieldSetQuery);
        if (!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])) {
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("metafieldUpdate: " . $gid, ['component' => $this->configLogName]);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        } else {
            return "Error in query";
        }
    }

    public function updateStock(array $inputArray, $locationId)
    {
        $variantsSetInventoryQuery = ShopifyGraphqlHelperService::buildSetVariantsStockQuery();
        $results = [];
        $variantsInventoryInput = [];
        $changes = [];
        $count = 0;
        foreach ($inputArray as $id => $quantity) {
            $count++;
            $changes[] = [
                "quantity" => intval($quantity),
                "inventoryItemId" => $id,
                "locationId" => $locationId,
            ];
            if ($count >= self::MAX_QUERY_OBJS) {
                $variantsInventoryInput = [
                    "input" => [
                        "setQuantities" => $changes,
                        "reason" => "correction",
                    ]
                ];
                $response = $this->runQuery($variantsSetInventoryQuery, $variantsInventoryInput);
                $results[] = $response;
                $changes = [];
                $count = 0;
            }
        }
        if ($count > 0) {
            $variantsInventoryInput = [
                "input" => [
                    "setQuantities" => $changes,
                    "reason" => "correction",
                ]
            ];
            $response = $this->runQuery($variantsSetInventoryQuery, $variantsInventoryInput);
            $results[] = $response;
        }
        return $results;
    }

    public function getPrimaryStoreLocationId()
    {
        $query = ShopifyGraphqlHelperService::buildStoreLocationQuery();
        $result = $this->runQuery($query);
        return $result["data"]["location"]["id"];
    }

    private function makeFile($content)
    {
        $file = tmpfile();
        fwrite($file, $content);
        return $file;
    }

    /**
     * wrap query call for error catching and such
     *
     * @param string $query the query to be ran
     * @throws conditon
     **/
    private function runQuery($query, $variables = null, ?AbstractObject $relatedObject =null): array|string|null
    {
        $this->customLogLogger->debug('Sending GraphQL query:', [
            'component' => $this->configLogName,
            'fileObject' => new FileObject(json_encode(['query' => $query, 'variables' => $variables])),
            'relatedObject' => $relatedObject,
        ]);

        try {
            if ($variables) {
                $response = $this->graphql->query(["query" => $query, "variables" => $variables]);
            } else {
                $response = $this->graphql->query(["query" => $query]);    
            }

            $response = $response->getDecodedBody();
            $this->customLogLogger->debug('Shopify response payload:', [
                'component' => $this->configLogName,
                'fileObject' => new FileObject(json_encode($response))
            ]);

        } catch (SyntaxError $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), ['component' => $this->configLogName]);
            return null;
        }
        if (array_key_exists('data', $response) && array_key_exists('bulkOperationRunMutation', $response['data']) && count($response['data']['bulkOperationRunMutation']['userErrors']) > 0) {
            $this->customLogLogger->error("error thrown by shopify on query:\n$query" . "\nerror: " . json_encode($response['data']['bulkOperationRunMutation']['userErrors']), ['component' => $this->configLogName]);
            throw new Exception("error thrown by shopify on query:\n$query" . "\nerror: " . json_encode($response['data']['bulkOperationRunMutation']['userErrors']));
        }
        return $response;
    }

    /**
     * uploads the files at the strings you provide
     * @param array<array<string, string>> $var [[filename, resource]..]
     * @return array<array<string, string>> [[filename => [url, remoteFileKey]..]
     **/
    public function uploadFiles(array $files): array
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
                $response = $this->graphql->query(["query" => $query, "variables" => $variables])->getDecodedBody();
                $stagedUploadUrls = array_merge($stagedUploadUrls, $response["data"]["stagedUploadsCreate"]["stagedTargets"]);
                $count = 0;
                $variables["input"] = [];
            }
        }
        if ($count > 0) {
            $response = $this->graphql->query(["query" => $query, "variables" => $variables])->getDecodedBody();
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

    private function queryFinished($queryType): bool|string
    {
        try {
            $query = ShopifyGraphqlHelperService::buildQueryFinishedQuery($queryType);

            $response = $this->graphql->query(["query" => $query]);
            $response->getBody()->rewind();
            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if ($response['data']["currentBulkOperation"] && $response['data']["currentBulkOperation"]["completedAt"]) {
                    return $response['data']["currentBulkOperation"]["url"] ?? "none"; //if the query returns nothing
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    private function checkQueryProgress($gid): bool|array
    {
        try {
            $query = ShopifyGraphqlHelperService::buildQueryProgressQuery($gid);
            $response = $this->graphql->query(["query" => $query]);
            $response->getBody()->rewind();
            $response = $response->getBody()->getContents();
            $response = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {

                if ($response['data'] && $response['data']["node"] && $response['data']["node"]["status"] != "RUNNING") {
                    return $response['data']["node"];
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function deleteProducts(array $inputArray)
    {
        $queryCount = 0;
        $queryString = "";
        foreach ($inputArray as $id) {
            $queryString .= "delete" . $queryCount . ": productDeleteAsync(productId: \"" . $id . "\") {deleteProductId}\n";
            $queryCount++;
            if ($queryCount > 99) { // max 100
                $this->pushProductDeleteQueries($queryString);
                $queryString = "";
                $queryCount = 0;
            }
        }
        if ($queryCount > 0) {
            $this->pushProductDeleteQueries($queryString);
        }
    }

    private function pushProductDeleteQueries($queryString)
    {

        try {
            $result = $this->runQuery("mutation {" . $queryString . "}");
        } catch (Exception $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), ['component' => $this->configLogName]);
        }
    }

    public function deleteVariants(array $inputArray)
    {
        $queryCount = 0;
        $queryString = "";
        foreach ($inputArray as $index => $id) {
            $queryString .= "delete" . $queryCount . ": productVariantDelete(id: \"" . $index . "\") {
                deletedProductVariantId
                product{
                    id
                }
            }\n";
            $queryCount++;
            if ($queryCount > 99) { // max 100
                $this->pushVariantDeleteQueries($queryString);
                $queryString = "";
                $queryCount = 0;
            }
        }
        if ($queryCount > 0) {
            $this->pushVariantDeleteQueries($queryString);
        }
    }

    private function pushVariantDeleteQueries($queryString)
    {

        try {
            $result = $this->runQuery("mutation {" . $queryString . "}");
        } catch (Exception $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString(), ['component' => $this->configLogName]);
        }
    }


    /**
     * create a new image in shopify
     * @param string $url public-facing URL
     * @param string $filename to set in Shopify
     * @return array [fileStatus, fileId]
     * 
     * If the API response does not include the necessary file result data, returns empty strings.
     * 
     **/
    public function createImage(string $url, string $filename) : array
    {
        $response = $this->runQuery(ShopifyGraphqlHelperService::buildCreateMediaQuery(), [
            'files' => [
                [
                    'contentType' => 'IMAGE',
                    'duplicateResolutionMode' => 'REPLACE',
                    'filename' => $filename,
                    'originalSource' => $url,
                ]
            ]]
        );

        $fileStatus = '';
        $fileId = '';

        if( $response 
            && isset($response['data']['fileCreate']['files'])
            && is_array($response['data']['fileCreate']['files']) 
            && count($response['data']['fileCreate']['files']) ) 
        {
            $fileStatus = $response['data']['fileCreate']['files'][0]['fileStatus'] ?: '';    
            $fileId =     $response['data']['fileCreate']['files'][0]['id'] ?: '';
        }

        return [$fileStatus, $fileId];
    }


    /**
     * link an image to a product
     * @param string $imageId In Shopify
     * @param string $productId in Shopify
     * @return string file status returned by Shopify
     */
    public function linkImageToProduct(string $imageId, string $productId) : bool 
    {
        $response = $this->runQuery(ShopifyGraphqlHelperService::buildUpdateMediaQuery(), [
            'files' => [
                [
                    'id' => $imageId,
                    'referencesToAdd' => $productId,
                ]
            ]
        ]);

        if( $response 
            && isset($response['data']['fileUpdate']['userErrors'])
            && is_array($response['data']['fileUpdate']['userErrors'])
            && count($response['data']['fileUpdate']['userErrors']) )
        {
            // if we have an error, and the file is not ready, ignore the error so we can retry
            if( is_array($response['data']['fileUpdate']['files']) 
                && count($response['data']['fileUpdate']['files']) ) 
            {
                $fileStatus = $response['data']['fileUpdate']['files'][0]['fileStatus'] ?: '';
                if( $fileStatus != 'READY' )
                    return $fileStatus;
            }

            return ShopifyStore::STATUS_ERROR;
        }

        // no errors... so return the file status
        if( $response 
            && isset($response['data']['fileUpdate']['files'])
            && is_array($response['data']['fileUpdate']['files']) 
            && count($response['data']['fileUpdate']['files']) )
        {
            return $response['data']['fileUpdate']['files'][0]['fileStatus'] ?: '';
        }

        return '';
    }
}

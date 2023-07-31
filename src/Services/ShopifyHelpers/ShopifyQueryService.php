<?php

namespace TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers;

use Exception;
use GraphQL\Error\SyntaxError;
use Shopify\Clients\Graphql;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use Pimcore\Logger;

/**
 * class to make queries to shopify and proccess their result for you into readable arrays
 */
class ShopifyQueryService
{
    const MAX_QUERY_OBJS = 260;

    
    private Graphql $graphql;
    public function __construct(
        ShopifyAuthenticator $abstractAuthenticator,
        private \Psr\Log\LoggerInterface $customLogLogger
    ) {
        $this->graphql = $abstractAuthenticator->connect()['client'];
    }

    /**
     * query all variants and their metafields
     *
     * @param string $query will run this query if provided to allow for custom variant queries like created_at.
     * @return array
     **/
    public function queryVariants($query = null): array
    {
        if (!$query) {
            $query = ShopifyGraphqlHelperService::buildVariantsQuery();
        }
        $result = $this->runQuery($query);

        if(!empty($result['data']['bulkOperationRunQuery']['bulkOperation']) && empty($result['data']['bulkOperationRunQuery']['bulkOperation']['userErrors'])){
            $gid = $result['data']['bulkOperationRunQuery']['bulkOperation']['id'];
            $this->customLogLogger->info("queryVariants: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            $resultFileURL = ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            throw new Exception("Error during query");
            return [];
        }
        
        $formattedResults = [];

        if ($resultFileURL == 'none') { //there were no variants returned (also not an error though)
            return $formattedResults;
        }
        $resultFile = fopen($resultFileURL, "r");
        while ($variantOrMetafield = fgets($resultFile)) {
            $variantOrMetafield = json_decode($variantOrMetafield, true);
            if (array_key_exists("key", $variantOrMetafield)) {
                $formattedResults[$variantOrMetafield["__parentId"]]['metafields'][$variantOrMetafield["namespace"] . "." . $variantOrMetafield["key"]] = $variantOrMetafield;
            } else {
                $formattedResults[$variantOrMetafield["id"]] = $formattedResults[$variantOrMetafield["id"]] ?? [];
                $formattedResults[$variantOrMetafield["id"]]['title'] = $variantOrMetafield["title"];
                $formattedResults[$variantOrMetafield["id"]]['product'] = $variantOrMetafield["product"]['id'];
            }
        }
        return $formattedResults;
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

        if(!empty($result['data']['bulkOperationRunQuery']['bulkOperation']) && empty($result['data']['bulkOperationRunQuery']['bulkOperation']['userErrors'])){
            $gid = $result['data']['bulkOperationRunQuery']['bulkOperation']['id'];
            $this->customLogLogger->info("queryForLinking : " . $gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            $resultFileURL = ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            $this->customLogLogger->info(print_r($result, true));
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
            if(isset($productOrVariant["__parentId"]) && isset($formattedResults[$productOrVariant["__parentId"]])){
                $formattedResults[$productOrVariant["__parentId"]]['variants'][] = $productOrVariant;
            }else{
                $formattedResults[$productOrVariant["id"]] = $productOrVariant;
            }
         
        }
        return $formattedResults;
    }

    /**
     * query all variants and their metafields
     *
     * @param string $query will run this query if provided to allow for custom product queries like created_at.
     * @return array
     **/
    public function queryProducts($query = null): array
    {
        if (!$query) {
            $query = ShopifyGraphqlHelperService::buildProductsQuery();
        }
        $result = $this->runQuery($query);

        if(!empty($result['data']['bulkOperationRunQuery']['bulkOperation']) && empty($result['data']['bulkOperationRunQuery']['bulkOperation']['userErrors'])){
            $gid = $result['data']['bulkOperationRunQuery']['bulkOperation']['id'];
            $this->customLogLogger->info("queryProducts: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            $resultFileURL = ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            throw new Exception("Error during query");
            return [];
        }
        $formattedResults = [];

        if ($resultFileURL == 'none') { //there were no products returned (also not an error though)
            return $formattedResults;
        }
        $resultFile = fopen($resultFileURL, "r");
        while ($productOrMetafield = fgets($resultFile)) {
            $productOrMetafield = (array)json_decode($productOrMetafield);
            if (array_key_exists("key", $productOrMetafield)) {
                $formattedResults[$productOrMetafield["__parentId"]]['metafields'][$productOrMetafield["namespace"] . "." . $productOrMetafield["key"]] = $productOrMetafield;
            } else {
                $formattedResults[$productOrMetafield["id"]] = $formattedResults[$productOrMetafield["id"]] ?? [];
                $formattedResults[$productOrMetafield["id"]]['title'] = $productOrMetafield["title"];
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
        $inputString = "";
        foreach ($inputArray as $inputObj) {
            $inputString .= json_encode(["input" => $inputObj]) . PHP_EOL;
        }
        $file = $this->makeFile($inputString);
        $filename = stream_get_meta_data($file)['uri'];
        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        fclose($file);

        $product_update_query = ShopifyGraphqlHelperService::buildUpdateQuery($remoteFileKey);
        $result = $this->runQuery($product_update_query);
       
        if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("updateProducts: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            return "Error in query";
        }
    }

    public function createProducts(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $inputObj) {
            fwrite($file, json_encode(["input" => $inputObj]) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 2mb the file upload will fail
                $resultFiles[] = $this->pushProductCreateFile($file);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) {
            $resultFiles[] = $this->pushProductCreateFile($file);
            fclose($file);
        }

        return $resultFiles;
    }

    private function pushProductCreateFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];

        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $product_update_query = ShopifyGraphqlHelperService::buildCreateProductsQuery($remoteFileKey);
        $result = $this->runQuery($product_update_query);

        if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("createProducts: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            return "Error in query";
        }
        
    }

    public function updateProductMedia(array $inputArray)
    {
        $inputString = "";
        foreach ($inputArray as $inputObj) {
            $inputString .= json_encode(["input" => $inputObj]) . PHP_EOL;
        }
        $file = $this->makeFile($inputString);
        $filename = stream_get_meta_data($file)['uri'];
        $remoteKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);

        $bulkParamsFilekey = $remoteKeys[$filename]["key"];
        fclose($file);
        $imagesCreateQuery = ShopifyGraphqlHelperService::buildCreateMediaQuery($bulkParamsFilekey);

        $result = $this->runQuery($imagesCreateQuery);
        if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("productMedia: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
            return "Error in query";
        }
    }

    public function updateVariants(array $inputArray)
    {
        $resultFiles = [];
        $file = tmpfile();
        foreach ($inputArray as $parentId => $variantMap) {
            fwrite($file, json_encode(["input" => $variantMap]) . PHP_EOL);
            if (fstat($file)["size"] >= 19000000) { //at 2mb the file upload will fail
                $resultFiles[] = $this->pushVariantsUpdateFile($file);
                fclose($file);
                $file = tmpfile();
            }
        }
        if (fstat($file)["size"] > 0) { //if there are any variants in here
            $resultFiles[] = $this->pushVariantsUpdateFile($file);
            fclose($file);
        }
        return $resultFiles;
    }

    private function pushVariantsUpdateFile($file): string
    {
        $filename = stream_get_meta_data($file)['uri'];

        $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
        $remoteFileKey = $remoteFileKeys[$filename]["key"];
        $variantQuery = ShopifyGraphqlHelperService::buildUpdateVariantsQuery($remoteFileKey);
        $result = $this->runQuery($variantQuery);

        if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("variantUpdate: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
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
        if(!empty($result['data']['bulkOperationRunMutation']['bulkOperation'])){
            $gid = $result['data']['bulkOperationRunMutation']['bulkOperation']['id'];
            $this->customLogLogger->info("metafieldUpdate: ".$gid);
            while (!$queryResult = $this->checkQueryProgress($gid)) {
                sleep(1);
            }
            return ($queryResult['url'] ?? $queryResult['partialDataUrl'] ?? "none");
        }else{
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
                $this->customLogLogger->info("Stock: ". print_r( $changes, true));
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
            $this->customLogLogger->info("Stock: ". print_r( $changes, true));
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
     * @return type
     * @throws conditon
     **/
    private function runQuery($query, $variables = null)
    {
        try {
            if ($variables) {
                $response = $this->graphql->query(["query" => $query, "variables" => $variables]);
            } else {
                $response = $this->graphql->query(["query" => $query]);
            }
            $response = $response->getDecodedBody();
        } catch (SyntaxError $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
            return null;
        }
        if(array_key_exists('data', $response) && array_key_exists('bulkOperationRunMutation', $response['data']) && count($response['data']['bulkOperationRunMutation']['userErrors']) > 0 ){
            $this->customLogLogger->error("error thrown by shopify on query:\n$query" . "\nerror: " . json_encode($response['data']['bulkOperationRunMutation']['userErrors']));
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
        }catch (Exception $e) {
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
        }catch (Exception $e) {
            return false;
        }
    }

    public function deleteProducts(array $inputArray)
    {
        $queryCount = 0;
        $queryString = "";
        foreach ($inputArray as $id) {
            $queryString .= "delete".$queryCount.": productDeleteAsync(productId: \"".$id."\") {deleteProductId}\n";
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
            $result = $this->runQuery("mutation {".$queryString."}");
            $this->customLogLogger->info(print_r($result, true));
        }catch (Exception $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
        }
    }

    public function deleteVariants(array $inputArray)
    {
        $queryCount = 0;
        $queryString = "";
        foreach ($inputArray as $id) {
            $queryString .= "delete".$queryCount.": productVariantDelete(id: \"".$id."\") {
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
            $result = $this->runQuery("mutation {".$queryString."}");
            $this->customLogLogger->info(print_r($result, true));
        }catch (Exception $e) {
            $this->customLogLogger->error("Syntax Error" . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
        }
    }

}

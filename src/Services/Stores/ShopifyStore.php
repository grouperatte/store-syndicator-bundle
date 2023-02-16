<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Concrete;
use Shopify\Rest\Admin2023_01\Product;
use Shopify\Exception\RestResourceRequestException;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;

class ShopifyStore extends BaseStore
{
    const PROPERTTYNAME = "ShopifyProductId"; //override parent value
    private $updateGraphQLStrings;
    private $createGraphQLStrings;
    private array $createObjs;
    private Session $session;
    private GraphQl $client;
    private array $productMetafieldsMapping;
    private ShopifyGraphqlHelperService $shopifyGraphqlHelperService;

    public function __construct()
    {
        $this->shopifyGraphqlHelperService = new ShopifyGraphqlHelperService();
    }

    public function setup(array $config)
    {
        $this->config = $config;

        $shopifyConfig = $this->config["APIAccess"];
        $host = $shopifyConfig["host"];
        Context::initialize(
            $shopifyConfig["key"],
            $shopifyConfig["secret"],
            ["read_products", "write_products"],
            $host,
            new FileSessionStorage('/tmp/php_sessions')
        );
        $offlineSession = new Session("offline_$host", $host, false, 'state');
        $offlineSession->setScope(Context::$SCOPES->toString());
        $offlineSession->setAccessToken($shopifyConfig["token"]);
        $this->session = $offlineSession;
        $this->client = new Graphql($this->session->getShop(), $this->session->getAccessToken());

        $this->productMetafieldsMapping = $this->getAllProducts();
    }

    /*
       Not currently used. 
    */
    public function getAllProducts()
    {
        $query = <<<QUERY
        query {
            products(first:10) {
            edges {
                node {
                id
                title
                metafields(first: 50) {
                    edges {
                        node {
                            namespace
                            key
                            value
                            id
                        }
                    }
                }
                }
            }
            }
        }
        QUERY;
        $result = $this->client->query(["query" => $query])->getDecodedBody();
        $products = [];
        foreach ($result['data']['products']["edges"] as $product) {
            $metafields = [];
            foreach ($product['node']["metafields"]["edges"] as $metafield) {
                $metaId = $metafield["node"]["id"];
                $metafields[$metafield["node"]["key"]] = [
                    "namespace" => $metafield["node"]["namespace"],
                    "key" => $metafield["node"]["key"],
                    "value" => $metafield["node"]["value"],
                    "id" => $metaId,
                ];
            }
            $prodId = $product['node']['id'];
            $products[$prodId] = [
                "id" => $prodId,
                "title" => $product['node']['title'],
                "metafields" => $metafields,
            ];
        }
        return $products;
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

    /*
        Checks if our local object has a remote id property. 
        If the object has a remote id we queue it for update. 
        Otherwise, we queue the product for create.


    */


    public function updateProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);
        $remoteId = $this->getStoreProductId($object);

        $graphQLInputString = [];
        $graphQLInputString["title"] = $object->getKey();
        foreach ($fields['metafields'] as $attribute) {
            if (array_key_exists($remoteId, $this->productMetafieldsMapping))
                $graphQLInputString["metafields"][] = $this->createMetafield($attribute, $this->productMetafieldsMapping[$remoteId]);
        }
        unset($fields['metafields']);
        foreach ($fields as $field => $value) {
            $graphQLInputString[$field] = $value;
        }
        $graphQLInputString["id"] = $remoteId;
        $this->updateGraphQLStrings .= json_encode(["input" => $graphQLInputString]) . PHP_EOL;
    }

    public function createProduct(Concrete $object): void
    {
        $fields = $this->getAttributes($object);

        $graphQLInputString = [];
        $graphQLInputString["title"] = $object->getKey();
        foreach ($fields['metafields'] as $attribute) {
            $graphQLInputString["metafields"][] = [
                "key" => $attribute["fieldName"],
                "value" => $attribute["value"],
                "namespace" => $attribute["namespace"],
            ];
        }
        unset($fields['metafields']);
        foreach ($fields as $field => $value) {
            $graphQLInputString[$field] = $value;
        }
        $this->createGraphQLStrings .= json_encode(["input" => $graphQLInputString]) . PHP_EOL;
        $this->createObjs[] = $object;
    }

    private function createMetafield($attribute, $mapping)
    {
        $tmpMetafield = [
            "key" => $attribute["fieldName"],
            "value" => $attribute["value"],
            "namespace" => $attribute["namespace"],
        ];
        if (array_key_exists($attribute["fieldName"], $mapping["metafields"])) {
            $tmpMetafield["id"] = $mapping["metafields"][$attribute["fieldName"]]["id"];
        }
        return $tmpMetafield;
    }

    public function createVariantsField(array $variants)
    {
        $variantsField = [];
        foreach ($variants as $options) {
            $variantsCustomizationField = [];
            foreach ($options as $field => $option) {
                $variantsCustomizationField[] = strval($option);
            }
            $variantsField[] = ["options" => $variantsCustomizationField];
        }
        return $variantsField;
    }

    public function commit(): Models\CommitResult
    {
        if ($this->createGraphQLStrings) {
            //create unmade products
            $file = $this->makeFile($this->createGraphQLStrings);
            $filename = stream_get_meta_data($file)['uri'];
            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $remoteFileKey = $remoteFileKeys[$filename];
            fclose($file);

            $product_create_query = $this->shopifyGraphqlHelperService->buildCreateQuery($remoteFileKey);

            $this->client->query(["query" => $product_create_query])->getDecodedBody();

            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            }
            //map created products
            $result = file_get_contents($resultFileURL);
            $result = '[' . str_replace(PHP_EOL, ',', $result);
            $result = substr($result, 0, strlen($result) - 1) . "]";
            $result = json_decode($result, true);
            foreach ($result as $ind => $createdProduct) {
                $this->setStoreProductId($this->createObjs[$ind], $createdProduct["data"]["productCreate"]["product"]["id"]);
            }
        }

        if ($this->updateGraphQLStrings) {
            $file = $this->makeFile($this->updateGraphQLStrings);
            $filename = stream_get_meta_data($file)['uri'];
            $remoteFileKeys = $this->uploadFiles([["filename" => $filename, "resource" => "BULK_MUTATION_VARIABLES"]]);
            $remoteFileKey = $remoteFileKeys[$filename];
            fclose($file);

            $product_update_query = $this->shopifyGraphqlHelperService->buildUpdateQuery($remoteFileKey);
            $result = $this->client->query(["query" => $product_update_query])->getDecodedBody();

            while (!$resultFileURL = $this->queryFinished("MUTATION")) {
            }
        }

        return new Models\CommitResult();
    }

    private function makeFile($content)
    {
        $file = tmpfile();
        fwrite($file, $content);
        return $file;
    }

    /**
     * uploads the filesat the strings you provide
     * @param array<array<string, string>> $var [[filename, resource]..]
     * @return array<array<string, string>> [[filename, remoteFileKey]..]
     **/
    private function uploadFiles(array $files): array
    {
        //build query and query variables 
        $query = $this->shopifyGraphqlHelperService->buildFileUploadQuery();
        $variables["input"] = [];
        foreach ($files as $file) {
            $filepatharray = explode("/", $file["filename"]);
            $filename = end($filepatharray);
            $variables["input"][] = [
                "filename" => $filename,
                "resource" => $file["resource"],
                //"mimeType" => mime_content_type($file["filename"]),
                "mimeType" => "text/jsonl",
                "httpMethod" => "POST",
            ];
        }

        //get upload instructions
        $response = $this->client->query(["query" => $query, "variables" => $variables])->getDecodedBody();
        $response = $response["data"]["stagedUploadsCreate"]["stagedTargets"];

        //upload all the files
        $fileKeys = [];
        foreach ($response as $fileInd => $uploadTarget) {
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
            $post = array(
                'key' => $curl_key,
                'x-goog-credential' => $curl_x_goog_credentials,
                'x-goog-algorithm' => $curl_x_goog_algorithm,
                'x-goog-date' => $curl_x_goog_date,
                'x-goog-signature' => $curl_x_goog_signature,
                'policy' => $curl_policy,
                'acl' => 'private',
                'Content-Type' => 'text/jsonl',
                'success_action_status' => '201',
                'file' => new \CURLFile($file["filename"], mime_content_type($file["filename"]), $filename)
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
            $fileKeys[$file["filename"]] = (string) $arr_result->Key;
        }

        return $fileKeys;
    }

    public function queryFinished($queryType): bool|string
    {
        $query = $this->shopifyGraphqlHelperService->buildQueryFinishedQuery($queryType);
        $response = $this->client->query(["query" => $query])->getDecodedBody();
        if ($response['data']["currentBulkOperation"] && $response['data']["currentBulkOperation"]["completedAt"]) {
            return $response['data']["currentBulkOperation"]["url"];
        } else {
            return false;
        }
    }
}

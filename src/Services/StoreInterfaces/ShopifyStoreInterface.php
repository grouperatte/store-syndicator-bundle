<?php

namespace TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\BaseStoreInterface;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Concrete;
use Shopify\Rest\Admin2023_01\Product;
use Shopify\Exception\RestResourceRequestException;

class ShopifyStoreInterface extends BaseStoreInterface
{
    const PROPERTTYNAME = "ShopifyProductId"; //override parent value
    private $graphQLStrings;
    private Session $session;
    private GraphQl $client;
    private array $productMetafieldsMapping;

    public function __construct()
    {
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

    public function getProduct(string $id)
    {
        try {
            return Product::find($this->session, $id);
        } catch (RestResourceRequestException $e) {
            return null;
        }
    }

    public function createOrUpdateProduct(Concrete $object, ?string $remoteId, array $params = [])
    {
        $graphQLInputString = [];
        if ($remoteId) {
            $graphQLInputString["id"] = $remoteId;
        }
        $graphQLInputString["title"] = $object->getKey();
        $fields = $this->getAttributes($object);
        foreach ($fields['metafields'] as $attribute) {
            //$graphQLInputString["metafields"][] = $this->createMetafield($attribute, $this->productMetafieldsMapping[$remoteId]);
        }
        $graphQLInputString["options"] = $fields["options"];
        $graphQLInputString["variants"] = $this->createVariantsField($this->getVariantsOptions($object, $fields["options"]));

        $this->graphQLStrings .= json_encode(["input" => $graphQLInputString]) . PHP_EOL;
    }

    private function createMetafield($attribute, $mapping)
    {
        $tmpMetafield = [
            "key" => $attribute["key"],
            "value" => $attribute["value"],
            "namespace" => 'custom',
        ];
        if (array_key_exists($attribute["key"], $mapping["metafields"])) {
            $tmpMetafield["id"] = $mapping["metafields"][$attribute["key"]]["id"];
            $tmpMetafield["namespace"] = $mapping["metafields"][$attribute["key"]]["namespace"];
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

    public function commit()
    {


        $remoteFileKey = $this->uploadProductFile($this->graphQLStrings);

        //actually tell spotify to run a command using the file\

        // metafields(first:10) {
        //     edges {
        //         node {
        //             id
        //             key
        //             value
        //             namespace
        //         }
        //     }
        // }
        $product_update_query =
            'mutation {
                bulkOperationRunMutation(
                mutation: "mutation call($input: ProductInput!) { productUpdate(input: $input) {
                    product {
                        title
                        id
                        options {
                            name
                        }
                        variants(first: 10) {
                            edges {
                                node {
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                    } userErrors { message field } } }",
                stagedUploadPath: "' . $remoteFileKey . '") {
                bulkOperation {
                id
                url
                status
                }
                userErrors {
                message
                field
                }
            }
        }';
        $product_update_response = $this->client->query(["query" => $product_update_query])->getDecodedBody();

        while (!$resultFileURL = $this->queryFinished()) {
        }

        $tmp = '';
        //commit new product mapping

    }

    private function uploadProductFile(string $productString)
    {
        $file = tmpfile();
        fwrite($file, $productString);
        $path = stream_get_meta_data($file)['uri'];
        $filepatharray = explode("/", $path);
        $filename = end($filepatharray);
        $query = <<<QUERY
        mutation {
            stagedUploadsCreate(input:{
              resource: BULK_MUTATION_VARIABLES,
              filename: "$filename",
              mimeType: "text/jsonl",
              httpMethod: POST
            }){
              userErrors{
                field,
                message
              },
              stagedTargets{
                url,
                resourceUrl,
                parameters {
                  name,
                  value
                }
              }
            }
          }
        QUERY;

        //get upload instructions
        $response = $this->client->query(["query" => $query])->getDecodedBody()["data"]["stagedUploadsCreate"]["stagedTargets"][0];
        $curl_opt_url = $response["url"];
        $response = $response["parameters"];
        $curl_key = $response[3]["value"];
        $curl_policy = $response[8]["value"];
        $curl_x_goog_credentials = $response[5]["value"];
        $curl_x_goog_algorithm = $response[6]["value"];
        $curl_x_goog_date = $response[4]["value"];
        $curl_x_goog_signature = $response[7]["value"];

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
            'file' => new \CURLFile($path, "text/jsonl", $filename)
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
        fclose($file);

        return (string) $arr_result->Key;
    }

    public function queryFinished(): bool|string
    {
        $query = <<<QUERY
        query {
            currentBulkOperation(type: MUTATION) {
            id
            status
            errorCode
            createdAt
            completedAt
            objectCount
            fileSize
            url
            partialDataUrl
            }
        }
        QUERY;
        $response = $this->client->query(["query" => $query])->getDecodedBody();
        if ($response['data']["currentBulkOperation"]["completedAt"]) {
            return $response['data']["currentBulkOperation"]["url"];
        } else {
            return false;
        }
    }
}

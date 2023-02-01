<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Bundle\DataHubBundle\Configuration;


class ShopifyAttributesService
{
    static array $baseFields = [
        "description",
        "price",
        "compare at price",
        "tax code",
        "cost per item",
        "SKU",
        "Barcode",
        "Inventory policy",
        "Available",
        "Incoming",
        "Committed",
        "This is a physical product",
        "Weight",
        "Country/Region of origin",
        "HS code",
        "Fulfillment service",
    ];
    public function getRemoteFields(Configuration $config): array
    {
        $config = $config->getConfiguration();
        $config = $config["APIAccess"];

        Context::initialize(
            $config["key"],
            $config["secret"],
            ["read_products", "write_products"],
            $config["host"],
            new FileSessionStorage('/tmp/php_sessions')
        );
        $host = $config["host"];
        $offlineSession = new Session("offline_$host", $host, false, 'state');
        $offlineSession->setScope(Context::$SCOPES->toString());
        $offlineSession->setAccessToken($config["token"]);

        $client = new Graphql($config["host"], $config["token"]);
        $query = <<<QUERY
        query {
            metafieldDefinitions(first: 250, ownerType: PRODUCT) {
                edges {
                    node {
                        namespace
                        key
                    }
                }
            }
        }
        QUERY;
        $response = $client->query(["query" => $query])->getDecodedBody();
        $data = [];
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = $node["node"]["namespace"] .  "." . $node["node"]["key"];
        }
        $data = array_merge($data, self::$baseFields);
        return $data;
    }
}

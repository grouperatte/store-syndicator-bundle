<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Bundle\DataHubBundle\Configuration;


class ShopifyAttributesService
{
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
        return $data;
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;
use Pimcore\Model\DataObject\Fieldcollection\Definition as FieldcollectionDefinition;

class AttributesService
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

    public function getLocalFields(Configuration $configuration): array
    {
        $config = $configuration->getConfiguration();

        $class = $config["products"]["class"];
        $class = ClassDefinition::getByName($class);
        $brick = $class->getFieldDefinitions()['testBrick'];
        //$huh = ObjectbrickDefinition::getByKey($brick->getAllowedTypes()[0]);

        $attributes = [];
        $this->getFieldDefinitionsRecursive($class, $attributes, "");

        return $attributes;
    }

    private function getFieldDefinitionsRecursive($class, &$attributes, $prefix)
    {
        $fields = $class->getFieldDefinitions();
        foreach ($fields as $field) {
            if ($field instanceof Objectbricks) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = ObjectbrickDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . ".");
                }
            } elseif ($field instanceof Fieldcollections) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = FieldcollectionDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . ".");
                }
            } else {
                $attributes[] = $prefix . $field->getName();
            }
            if (method_exists($field, "getAllowedTypes") && $allowedTypes = $field->getAllowedTypes()) {
            }
        }
    }
}

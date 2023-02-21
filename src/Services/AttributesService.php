<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore as ClassificationStoreDefinition;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;
use Pimcore\Model\DataObject\Fieldcollection\Definition as FieldcollectionDefinition;

class AttributesService
{
    static array $baseFields = [
        "descriptionHtml",
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
    public function getRemoteFields($apiAccess): array
    {
        Context::initialize(
            $apiAccess["key"],
            $apiAccess["secret"],
            ["read_products", "write_products"],
            $apiAccess["host"],
            new FileSessionStorage('/tmp/php_sessions')
        );
        $host = $apiAccess["host"];
        $offlineSession = new Session("offline_$host", $host, false, 'state');
        $offlineSession->setScope(Context::$SCOPES->toString());
        $offlineSession->setAccessToken($apiAccess["token"]);

        $client = new Graphql($apiAccess["host"], $apiAccess["token"]);
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
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . "." . $allowedType . ".");
                }
            } elseif ($field instanceof Fieldcollections) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = FieldcollectionDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . ".");
                }
            } elseif ($field instanceof ClassificationStoreDefinition) {
                $fields = $this->getStoreKeys($field->getStoreId());
                foreach ($fields as $field) {
                    $attributes[] = $prefix . $field->getName();
                }
            } else {
                $attributes[] = $prefix . $field->getName();
            }
        }
    }

    private function getStoreKeys($storeId)
    {
        $db = \Pimcore\Db::get();

        $condition = '(storeId = ' . $db->quote($storeId) . ')';
        $list = new Classificationstore\KeyConfig\Listing();
        $list->setCondition($condition);
        return $list->load();
    }
}

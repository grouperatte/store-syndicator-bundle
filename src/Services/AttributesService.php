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
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;

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
        "title",
    ];

    static array $fieldTypes = [
        "base product",
        "Images",
        "metafields",
        "options",
        "variant options",
        "variant metafields",
        "base variant"
    ];

    public function __construct(
        private ShopifyGraphqlHelperService $shopifyGraphqlHelperService
    ) {
    }

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

        $data = [];

        //get metafields
        $client = new Graphql($apiAccess["host"], $apiAccess["token"]);
        $query = $this->shopifyGraphqlHelperService->buildMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "metafields"];
        }

        //get variant metafields
        $client = new Graphql($apiAccess["host"], $apiAccess["token"]);
        $query = $this->shopifyGraphqlHelperService->buildVariantMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "variant metafields"];
        }

        foreach (self::$baseFields as $field) {
            $data[] = ["name" => $field, "type" => "base product"];
            $data[] = ["name" => $field, "type" => "base variant"];
        }
        $data[] = ["name" => "Image", "type" => "Images"];
        return $data;
    }

    public function getRemoteTypes(): array
    {
        return self::$fieldTypes;
    }

    public function getLocalFields(Configuration $configuration): array
    {
        $config = $configuration->getConfiguration();

        $class = $config["products"]["class"];
        $class = ClassDefinition::getByName($class);

        $attributes = ["Key"];
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

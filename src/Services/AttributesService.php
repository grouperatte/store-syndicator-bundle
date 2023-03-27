<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyRelation;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyObjectRelation;
use Pimcore\Model\DataObject\Fieldcollection\Definition as FieldcollectionDefinition;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore as ClassificationStoreDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\Localizedfield;

class AttributesService
{
    static array $baseFields = [
        "descriptionHtml",
        "price",
        "compare at price",
        "tax code",
        "cost per item",
        "SKU",
        "barcode",
        "Inventory policy",
        "Available",
        "Incoming",
        "Committed",
        "This is a physical product",
        "weight",
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

    public function getRemoteFields(Graphql $client): array
    {
        $query = $this->shopifyGraphqlHelperService->buildMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        //get variant metafields
        $query = $this->shopifyGraphqlHelperService->buildVariantMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "variant metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        foreach (self::$baseFields as $field) {
            if ($field != "SKU") {
                $data[] = ["name" => $field, "type" => "base product"];
            }
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
        if (!$class = ClassDefinition::getByName($class)) {
            return [];
        }

        $attributes = ["Key"];
        $this->getFieldDefinitionsRecursive($class, $attributes, "");

        return $attributes;
    }

    private function getFieldDefinitionsRecursive($class, &$attributes, $prefix)
    {
        if (!method_exists($class, "getFieldDefinitions")) {
            $attributes[] = $prefix . $class->getName();
            return;
        }
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
            } elseif ($field instanceof Localizedfields) {
                $fields = $field->getChildren();
                foreach ($fields as $childField) {
                    if (!method_exists($childField, "getFieldDefinitions")) {
                        $attributes[] = $prefix . $childField->getName();
                    } else {
                        $this->getFieldDefinitionsRecursive($childField, $attributes, $prefix . $childField->getName() . ".");
                    }
                }
                if ($fields = $field->getReferencedFields()) {
                    $names = [];
                    foreach ($fields as $field) {
                        if (!in_array($field->getName(), $names)) { //sometimes returns the same field multiple times...
                            $this->getFieldDefinitionsRecursive($field, $attributes, $prefix);
                            $names[] = $field->getName();
                        }
                    }
                }
            } elseif ($field instanceof AbstractRelations) {
                if ($field instanceof AdvancedManyToManyRelation || $field instanceof AdvancedManyToManyObjectRelation) {
                    $classes = [["classes" => $field->getAllowedClassId()]];
                } else {
                    $classes = $field->classes;
                }
                foreach ($classes as $allowedClass) {
                    $allowedClass = ClassDefinition::getByName($allowedClass["classes"]);
                    $this->getFieldDefinitionsRecursive($allowedClass, $attributes, $prefix . $field->getName() . ".");
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

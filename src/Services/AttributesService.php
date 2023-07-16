<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Carbon\Carbon;
use Shopify\Context;
use Shopify\Auth\Session;
use Shopify\Clients\Graphql;
use Pimcore\Model\Asset\Image;
use Shopify\Auth\FileSessionStorage;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\Data\BlockElement;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\ClassDefinition\Data\Objectbricks;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Fieldcollections;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyRelation;
use Pimcore\Model\DataObject\Objectbrick\Definition as ObjectbrickDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Relations\AbstractRelations;
use Pimcore\Model\DataObject\ClassDefinition\Data\AdvancedManyToManyObjectRelation;
use Pimcore\Model\DataObject\Fieldcollection\Definition as FieldcollectionDefinition;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyGraphqlHelperService;
use Pimcore\Model\DataObject\ClassDefinition\Data\Classificationstore as ClassificationStoreDefinition;
use Pimcore\Model\DataObject\Product\Listing;

class AttributesService
{
    static array $baseFields = [
        "descriptionHtml",
        "title",
        "options", //array
        "productType",
        "vendor",
        "tags", //array
        "status", // "ACTIVE" "ARCHIVED" or "DRAFT"
    ];

    static array $fieldTypes = [
        "base product",
        "Images",
        "metafields",
        "variant metafields",
        "base variant",
    ];

    static array $variantFields = [
        "cost", //productVariantInput.inventoryItem
        "tracked", //productVariantInput.inventoryItem
        "price",
        "compareAtPrice",
        "taxCode",
        "taxable",
        "sku",
        "barcode",
        "continueSellingOutOfStock", //inventoryPolicy "CONTINUE" or "DENY"
        "weight",
        "weightUnit", //needs to be "POUNDS" "OUNCES" "KILOGRAMS" or "GRAMS"
        "requiresShipping",
        "imageSrc", //variants can only have one image
        "title",
        "stock",
    ];

    const CURRENT_TIME_OPTION = 'Current Time';
    static array $staticLocalFields = [
        self::CURRENT_TIME_OPTION,
    ];

    public function getRemoteFields(Graphql $client): array
    {
        //get metafields
        $query = ShopifyGraphqlHelperService::buildMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        //get variant metafields
        $query = ShopifyGraphqlHelperService::buildVariantMetafieldsQuery();
        $response = $client->query(["query" => $query])->getDecodedBody();
        foreach ($response["data"]["metafieldDefinitions"]["edges"] as $node) {
            $data[] = ["name" => $node["node"]["namespace"] .  "." . $node["node"]["key"], "type" => "variant metafields", "fieldDefType" => $node["node"]["type"]["name"]];
        }

        //get base fields
        foreach (self::$baseFields as $baseField) {
            $data[] = ["name" => $baseField, "type" => "base product"];
        }

        //get base variant fields
        foreach (self::$variantFields as $variantField) {
            $data[] = ["name" => $variantField, "type" => "base variant"];
        }
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

        $attributes = self::$staticLocalFields;
        $attributes[] = 'Key';
        $this->getFieldDefinitionsRecursive($class, $attributes, "", []);

        return $attributes;
    }

    //builds an array of "." separated paths to all fields on the $class passed into initial call
    private function getFieldDefinitionsRecursive($class, &$attributes, $prefix, array $checkedClasses, string $suffix = null)
    {
        //the new field path to build from on the next recursion
        $newFieldPath = $prefix;
        //this is used if the previous field was a localized field, we need to prepend the suffix (it will be a local)
        $newFieldPathSuffix = ($suffix ? "." . $suffix . "." : ".");
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
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . "." . $allowedType . $newFieldPathSuffix, $checkedClasses);
                }
            } elseif ($field instanceof Fieldcollections) {
                $allowedTypes = $field->getAllowedTypes();
                foreach ($allowedTypes as $allowedType) {
                    $allowedTypeClass = FieldcollectionDefinition::getByKey($allowedType);
                    $this->getFieldDefinitionsRecursive($allowedTypeClass, $attributes, $prefix . $field->getName() . $newFieldPathSuffix, $checkedClasses);
                }
            } elseif ($field instanceof ClassificationStoreDefinition) {
                $fields = $this->getStoreKeys($field->getStoreId());
                foreach ($fields as $field) {
                    $attributes[] = $prefix . $field->getName();
                }
            } elseif ($field instanceof Localizedfields) {
                $langs = \Pimcore\Tool::getValidLanguages();
                $fields = $field->getChildren();
                foreach ($fields as $childField) {
                    if (($childField instanceof AbstractRelations)) {
                        foreach ($langs as $lang) {
                            $this->proccessRelationField($childField, $checkedClasses, $attributes, $prefix, $lang);
                        }
                    } elseif (!method_exists($childField, "getFieldDefinitions")) {
                        $attributes = array_merge($attributes, array_map(fn ($lang) => $prefix . $childField->getName() . "." . $lang, $langs));
                    } else {
                        $this->getFieldDefinitionsRecursive($childField, $attributes, $prefix . $childField->getName() . $newFieldPathSuffix, $checkedClasses);
                    }
                }
                if ($fields = $field->getReferencedFields()) {
                    $names = [];
                    foreach ($fields as $field) {
                        if (!in_array($field->getName(), $names)) { //sometimes returns the same field multiple times...
                            foreach ($langs as $lang) {
                                $this->getFieldDefinitionsRecursive($field, $attributes, $prefix, $checkedClasses, $lang);
                            }
                            $names[] = $field->getName();
                        }
                    }
                }
            } elseif ($field instanceof AbstractRelations) {
                $this->proccessRelationField($field, $checkedClasses, $attributes, $prefix, $suffix);
            } else {
                $attributes[] = $prefix . $field->getName() . ($suffix ? "." .  $suffix : "");
            }
        }
    }

    private function proccessRelationField($field, $checkedClasses, &$attributes, $prefix, $suffix = null)
    {
        if ($field instanceof AdvancedManyToManyRelation || $field instanceof AdvancedManyToManyObjectRelation) {
            $classes = [["classes" => $field->getAllowedClassId()]];
        } else {
            $classes = $field->classes;
        }
        foreach ($classes as $allowedClass) {
            $allowedClass = ClassDefinition::getByName($allowedClass["classes"]);
            if (!in_array($allowedClass, $checkedClasses)) {
                $this->getFieldDefinitionsRecursive($allowedClass, $attributes, $prefix . $field->getName() . "." . ($suffix ? $suffix . "." : "."), array_merge([$allowedClass], $checkedClasses));
            }
        }
    }

    //get the value(s) at the end of the fieldPath array on an object
    public static function getObjectFieldValues($rootField, array $fieldPath)
    {
        $field = $fieldPath[0];
        array_shift($fieldPath);
        $getter = "get$field"; //need to do this instead of getValueForFieldName for bricks
        $fieldVal = $rootField->$getter();
        if (is_iterable($fieldVal)) { //this would be like manytomany fields
            $vals = [];
            foreach ($fieldVal as $singleVal) {
                if ($singleVal && is_object($singleVal) && method_exists($singleVal, "get" . $fieldPath[0])) {
                    $vals[] = self::getObjectFieldValues($singleVal, $fieldPath);
                } elseif ($singleVal && is_array($singleVal) && array_key_exists($fieldPath[0], $singleVal)) { //blocks
                    $vals[] = self::processLocalValue($singleVal[$fieldPath[0]]->getData());
                } else {
                    $vals[] = $singleVal;
                }
            }
            return count($vals) > 0 ? $vals : null;
        } elseif (count($fieldPath) == 0) {
            return self::processLocalValue($fieldVal);
        } elseif ($fieldVal instanceof BlockElement) {
            $vals = [];
            foreach ($fieldVal as $blockItem) {
                //assuming the next fieldname is the value we want
                $vals[] = self::processLocalValue($blockItem[$fieldPath[0]]->getData());
            }
            return count($vals) > 0 ? $vals : null;
        } else {
            if ($fieldVal instanceof Localizedfield && array_key_exists(0, $fieldPath) && $local = $fieldPath[0]) {
                array_shift($fieldPath);
                if (count($fieldPath) == 0) {
                    return self::processLocalValue($rootField->$getter($local));
                }
                return self::getObjectFieldValues($rootField->$getter($local), $fieldPath);
            } elseif ($fieldVal && method_exists($fieldVal, "get" . $fieldPath[0])) {
                return self::getObjectFieldValues($fieldVal, $fieldPath);
            }
        }
    }

    private static function processLocalValue($field)
    {
        if ($field instanceof Image) {
            return $field;
        } elseif ($field instanceof ImageGallery) {
            $returnArray = [];
            foreach ($field->getItems() as $hotspot) {
                $returnArray[] = $hotspot->getImage();
            }
            return $returnArray;
        } elseif ($field instanceof QuantityValue) {
            return self::processLocalValue($field->getValue());
        } elseif (is_bool($field)) {
            return $field ? "true" : "false";
        } elseif (is_numeric($field)) {
            return strval($field);
        } elseif (empty($field)) {
            return null;
        } else {
            return strval($field);
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

    public static function getStaticValue($field)
    {
        switch ($field) {
            case self::CURRENT_TIME_OPTION:
                return Carbon::now()->toISOString();
            default:
                return '';
        }
    }
}

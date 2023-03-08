<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Data\QuantityValue;

abstract class BaseStore implements StoreInterface
{
    protected const PROPERTTYNAME = "Default";
    protected array $config;
    abstract public function __construct();
    abstract public function setup(array $config);
    abstract public function getAllProducts();
    abstract public function getProduct(string $id);

    abstract public function createProduct(Concrete $object): void;
    abstract public function updateProduct(Concrete $object): void;
    abstract public function processVariant(Concrete $parent, Concrete $child): void;

    /**
     * call to perform an final actions between the app and the store
     * one mandatory asction is to update the webstore's product -> remoteId mapping if the remote store uses one
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    abstract public function commit(): Models\CommitResult;

    public function getStoreProductId(Concrete $object): string|null
    {
        return $object->getProperty(static::PROPERTTYNAME);
    }

    function setStoreProductId(Concrete $object, string $id)
    {
        $object->setProperty(static::PROPERTTYNAME, "text", $id);
        $object->save();
    }

    public function getAttributes(Concrete $object): array
    {
        $attributeMap = $this->config["attributeMap"];
        $returnMap = [];
        foreach ($attributeMap as $row) {
            $fieldType = $row['field type'];
            $localAttribute = $row['local field'];
            $remoteAttribute = $row['remote field'];
            //getting local value of field
            $localFieldPath = explode(".", $localAttribute);
            $remoteFieldPath = explode(".", $remoteAttribute);
            $localValue = $this->getFieldValues($object, $localFieldPath);
            if ($localValue !== null) {
                if (!is_array($localValue)) {
                    $localValue = [$localValue];
                }
                $value = array();

                if (in_array($fieldType, ['metafields', 'variant metafields'])) {
                    array_push($value, [
                        'namespace' => $remoteFieldPath[0],
                        'fieldName' => $remoteFieldPath[1],
                        'value' => $localValue[0]
                    ]);
                } elseif (!in_array($fieldType, ["Images"])) {
                    $value[$remoteAttribute] = $localValue;
                } else {
                    $value = $localValue;
                }
                if (count($value) > 0) {
                    $returnMap[$fieldType] = array_merge($returnMap[$fieldType] ?? [], $value);
                }
            }
        }
        return $returnMap;
    }

    //get the value(s) at the end of the fieldPath array on an object
    private function getFieldValues($rootField, array $fieldPath)
    {
        $field = $fieldPath[0];
        array_shift($fieldPath);
        $getter = "get$field"; //need to do this instead of getValueForFieldName for bricks
        $fieldVal = $rootField->$getter();
        if (count($fieldPath) == 0) {
            return $this->processLocalValue($fieldVal);
        } elseif (is_iterable($fieldVal)) { //this would be like manytomany fields
            $vals = [];
            foreach ($fieldVal as $singleVal) {
                if ($singleVal && method_exists($singleVal, "get" . $fieldPath[0])) {
                    $vals[] = $this->getFieldValues($singleVal, $fieldPath);
                }
            }
            return $vals;
        } else {
            if ($fieldVal && method_exists($fieldVal, "get" . $fieldPath[0])) {
                return $this->getFieldValues($fieldVal, $fieldPath);
            }
        }
    }

    public function processLocalValue($field)
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
            return $field->getValue();
        } else {
            return $field;
        }
    }
    public function getVariantsOptions(Concrete $object, array $fields): array
    {
        $variants = $object->getChildren([Concrete::OBJECT_TYPE_VARIANT]);
        $variantsOptions = [];
        foreach ($variants as $variant) {
            $options = [];
            foreach ($fields as $field) {
                if ($value = $variant->getValueForFieldName($field)) {
                    $options[$field] = $value;
                }
            }
            $variantsOptions[] = $options;
        }
        return $variantsOptions;
    }

    public function existsInStore(Concrete $object): bool
    {
        return $this->getStoreProductId($object) != null;
    }
}

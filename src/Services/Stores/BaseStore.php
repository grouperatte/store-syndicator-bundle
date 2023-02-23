<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ImageGallery;

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
            $currentField = $object;
            foreach ($localFieldPath as $field) {
                $getter = "get$field"; //need to do this instead of getValueForFieldName for bricks
                if ($currentField && method_exists($currentField, $getter)) {
                    $currentField = $currentField->$getter();
                } else {
                    $currentField = null;
                    break;
                }
            }
            $value = array();
            if ($fieldType == 'metafields') {
                array_push($value, [
                    'namespace' => $remoteFieldPath[0],
                    'fieldName' => $remoteFieldPath[1],
                    'value' => strval($currentField)
                ]);
            } elseif ($currentField instanceof Image) {
                array_push($value, $currentField);
            } elseif ($currentField instanceof ImageGallery) {
                foreach ($currentField->getItems() as $hotspot) {
                    array_push($value, $hotspot->getImage());
                }
            } elseif ($currentField != null) {
                $value[$remoteAttribute] = strval($currentField);
            }
            if (count($value) > 0) {
                $returnMap[$fieldType] = array_merge($returnMap[$fieldType] ?? [], $value);
            }
        }
        return $returnMap;
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

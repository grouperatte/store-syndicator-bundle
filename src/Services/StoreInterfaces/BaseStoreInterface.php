<?php

namespace TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces;

use Exception;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;

abstract class BaseStoreInterface
{
    protected const PROPERTTYNAME = "Default";
    protected array $config;
    abstract public function __construct();
    abstract public function setup(array $config);
    abstract public function getAllProducts();
    abstract public function getProduct(string $id);
    abstract public function createOrUpdateProduct(Concrete $object, array $params = []);
    /**
     * call to perform an final actions between the app and the store
     * one mandatory asction is to update the webstore's product -> remoteId mapping if the remote store uses one
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    abstract public function commit();

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
            $localAttribute = $row['local field'];
            $remoteAttribute = $row['remote field'];
            //getting local value of field
            $localFieldPath = explode(".", $localAttribute);
            $remoteFieldPath = explode(".", $remoteAttribute);
            $currentField = $object;
            foreach ($localFieldPath as $field) {
                $currentField = strval($currentField->getValueForFieldName($field));
            }
            if (count($remoteFieldPath) > 1) { //metafields have paths
                $returnMap["metafields"][] = [
                    'namespace' => $remoteFieldPath[0],
                    'fieldName' => $remoteFieldPath[1],
                    'value' => $currentField
                ];
            } else {
                $returnMap[$remoteAttribute] = $currentField;
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
}

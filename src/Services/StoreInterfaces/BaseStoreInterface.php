<?php

namespace TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces;

use Exception;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;

abstract class BaseStoreInterface
{
    protected array $config;
    abstract public function __construct(array $config);
    abstract public function getAllProducts();
    abstract public function getProduct(string $id);
    abstract public function createOrUpdateProduct(Concrete $object, ?string $remoteId, array $params = []);
    /**
     * call to perform an final actions between the app and the store
     * one mandatory asction is to update the webstore's product -> remoteId mapping if the remote store uses one
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    abstract public function commit();

    public function getAttributes(Concrete $object): array
    {
        $attributeMap = $this->config["attributeMap"];
        $returnMap = [];
        foreach ($attributeMap as $row) {
            $localAttribute = $row['local field'];
            $remoteAttribute = $row['remote field'];
            //getting local value of field
            $localFieldPath = explode(".", $localAttribute);
            $currentField = $object;
            foreach ($localFieldPath as $field) {
                $currentField = $currentField->getValueForFieldName($field);
            }
            $returnMap[$remoteAttribute] = $currentField;
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

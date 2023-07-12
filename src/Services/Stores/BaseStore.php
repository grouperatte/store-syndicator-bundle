<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Exception;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use PhpParser\Node\Expr\Cast\Bool_;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\Data\BlockElement;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationRepository;

abstract class BaseStore implements StoreInterface
{
    protected string $propertyName = "Default";
    protected Configuration $config;
    abstract public function __construct(ConfigurationRepository $configurationRepository, ConfigurationService $configurationService);
    abstract public function setup(Configuration $config);
    abstract public function getAllProducts();

    abstract public function createProduct(Concrete $object): void;
    abstract public function updateProduct(Concrete $object): void;
    abstract public function createVariant(Concrete $parent, Concrete $child): void;
    abstract public function updateVariant(Concrete $parent, Concrete $child): void;

    /**
     * call to perform an final actions between the app and the store
     * one mandatory asction is to update the webstore's product -> remoteId mapping if the remote store uses one
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    abstract public function commit(): Models\CommitResult;

    public function getStoreProductId(Concrete $object): string|null
    {
        return $object->getProperty($this->propertyName);
    }

    function setStoreProductId(Concrete $object, string $id)
    {
        $object->setProperty($this->propertyName, "text", $id);
        $object->save();
    }

    public function getAttributes(Concrete $object): array
    {
        $attributeMap = $this->config->getConfiguration()["attributeMap"];
        $returnMap = [];
        foreach ($attributeMap as $row) {
            $fieldType = $row['field type'];
            $localAttribute = $row['local field'];
            $remoteAttribute = $row['remote field'];
            //getting local value of field
            $localFieldPath = explode(".", $localAttribute);
            $remoteFieldPath = explode(".", $remoteAttribute);
            if (in_array($localAttribute, AttributesService::$staticLocalFields)) {
                $localValue = AttributesService::getStaticValue($localAttribute);
            } else {
                $localValue = $this->getFieldValues($object, $localFieldPath);
            }
            if ($localValue !== null) {
                if (!is_array($localValue)) {
                    $localValue = [$localValue];
                }
                $value = array();

                if (in_array($fieldType, ['metafields', 'variant metafields'])) {
                    array_push($value, [
                        'namespace' => $remoteFieldPath[0],
                        'fieldName' => $remoteFieldPath[1],
                        'value' => $localValue
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
    private function getFieldValues(Concrete $rootField, array $fieldPath)
    {
        $field = $fieldPath[0];
        array_shift($fieldPath);
        $getter = "get$field"; //need to do this instead of getValueForFieldName for bricks
        if (array_key_exists(0, $fieldPath) && $local = self::isLocalizedField($fieldPath[0])) {
            array_shift($fieldPath);
            $fieldVal = $rootField->$getter($local);
        } else {
            $fieldVal = $rootField->$getter();
        }
        if (is_iterable($fieldVal)) { //this would be like manytomany fields
            $vals = [];
            foreach ($fieldVal as $singleVal) {
                if ($singleVal && is_object($singleVal) && method_exists($singleVal, "get" . $fieldPath[0])) {
                    $vals[] = $this->getFieldValues($singleVal, $fieldPath);
                } elseif ($singleVal && is_array($singleVal) && empty($fieldPath)) { //blocks
                    $vals[] = $this->processLocalValue(array_values($singleVal)[0]->getData());
                } elseif ($singleVal && is_array($singleVal) && array_key_exists($fieldPath[0], $singleVal)) { //blocks
                    $vals[] = $this->processLocalValue($singleVal[$fieldPath[0]]->getData());
                } else {
                    $vals[] = $singleVal;
                }
            }
            return count($vals) > 0 ? $vals : null;
        } elseif (count($fieldPath) == 0) {
            return $this->processLocalValue($fieldVal);
        } elseif ($fieldVal instanceof BlockElement) {
            $vals = [];
            foreach ($fieldVal as $blockItem) {
                //assuming the next fieldname is the value we want
                $vals[] = $this->processLocalValue($blockItem[$fieldPath[0]]->getData());
            }
            return count($vals) > 0 ? $vals : null;
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
            return $this->processLocalValue($field->getValue());
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

    private static function isLocalizedField($nextField): string|null
    {
        $langs = \Pimcore\Tool::getValidLanguages();
        if (in_array($nextField, $langs)) {
            return $nextField;
        }
        return null;
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

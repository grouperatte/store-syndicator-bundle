<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\AbstractElement;

interface StoreInterface
{
    /**
     * call to perform an final actions between the app and the store
     * 
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    public function commit();

    public function existsInStore(Concrete $object): bool;

    public function createProduct(Concrete $object): void;

    public function updateProduct(Concrete $object): void;

    public function createVariant(Concrete $parent, Concrete $child): void;

    public function updateVariant(Concrete $parent, Concrete $child): bool;

    public function getStoreId(AbstractElement $object): string|null;

    public function setStoreId(AbstractElement $object, string $id);

    public function getAttributes(Concrete $object): array;

    public function getVariantsOptions(Concrete $object, array $fields): array;
}

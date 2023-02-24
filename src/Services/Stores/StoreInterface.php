<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Stores;

use Pimcore\Model\DataObject\Concrete;

interface StoreInterface
{
    /**
     * call to perform an final actions between the app and the store
     * one mandatory asction is to update the webstore's product -> remoteId mapping if the remote store uses one
     *
     * @param Webstore $webstore to webstore with the product mapping
     **/
    public function commit(): Models\CommitResult;

    public function existsInStore(Concrete $object): bool;

    public function createProduct(Concrete $object): void;

    public function updateProduct(Concrete $object): void;

    public function processVariant(Concrete $parent, Concrete $child): void;

    public function getStoreProductId(Concrete $object): string|null;

    public function setStoreProductId(Concrete $object, string $id);

    public function getAttributes(Concrete $object): array;

    public function getVariantsOptions(Concrete $object, array $fields): array;
}

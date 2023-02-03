<?php

namespace Services;

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\ElementMetadata;
use Pimcore\Model\DataObject;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\BaseStoreInterface;
use TorqIT\StoreSyndicatorBundle\Services\StoreInterfaces\StoreInterfaceFactory;


class ExecutionService
{
    private array $config;
    private BaseStoreInterface $storeInterface;

    public function __construct()
    {
        # code...
    }

    public function export(array $config)
    {
        $this->config = $config;
        $this->storeInterface = StoreInterfaceFactory::getExportUtil($this->config);

        $productIds = json_decode($this->config["products"]["products"]);
        $classType = $this->config["products"]["class"];
        /** @var DataObject $class */
        $class = "DataObject\\" . $classType;
        foreach ($productIds as $productId) {
            $object = $class::getById($productId);
            if ($object) {
                $this->push($object);
            }
        }
    }

    private function push($object)
    {
        if (!($object instanceof Concrete)) return;

        $remoteId = $this->getStoreProductId($object);

        $this->storeInterface->createOrUpdateProduct($object, $remoteId);
    }

    private function getStoreProductId(Concrete $object): ?string
    {
        return "";
    }

    private function setStoreProductId(Concrete $object, $id)
    {
    }
}

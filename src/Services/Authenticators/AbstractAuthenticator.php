<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Authenticators;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;

abstract class AbstractAuthenticator extends Concrete
{
    abstract public function connect(): array;
    public static function getAuthenticatorFromConfig(Configuration $configuration): self
    {
        $config = $configuration->getConfiguration();
        $path = $config["APIAccess"][0]["cpath"];
        return DataObject::getByPath($path);
    }
}

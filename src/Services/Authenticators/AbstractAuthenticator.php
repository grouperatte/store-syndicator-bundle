<?php

namespace TorqIT\StoreSyndicatorBundle\Services\Authenticators;

use Pimcore\Model\DataObject\Concrete;

abstract class AbstractAuthenticator extends Concrete
{
    abstract public function connect(): array;
}

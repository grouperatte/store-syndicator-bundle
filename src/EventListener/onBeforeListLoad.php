<?php

namespace TorqIT\StoreSyndicatorBundle\EventListener;
use Pimcore\Model\DataObject\ClassDefinition\Data\EncryptedField;

class onBeforeListLoad
{
    public function onBeforeListLoad(\Symfony\Component\EventDispatcher\GenericEvent $event): void
    {
        EncryptedField::setStrictMode(false);
    }
}

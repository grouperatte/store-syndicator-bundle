<?php

namespace TorqIT\StoreSyndicatorBundle\EventListener;


class onPreSaveListener
{
    public function onPreUpdate(\Pimcore\Event\Model\DataObjectEvent $event): void
    {
        $element = $event->getObject();
        $arguments = $event->getArguments();
        
        if((empty($arguments) || !$arguments['isAutoSave']) && (method_exists($element, 'getDontSaveModificationDate') && $element->getDontSaveModificationDate())){
            $element->markFieldDirty("modificationDate", true);
            $element->setDontSaveModificationDate(false);
        }
    }
}

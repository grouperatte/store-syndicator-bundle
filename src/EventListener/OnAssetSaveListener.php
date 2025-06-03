<?php

namespace TorqIT\StoreSyndicatorBundle\EventListener;


class OnAssetSaveListener
{
    // Assets in Pimcore that have been linked to a Shopify product will have an attribute `shopifyId` set.
    // This listener will remove the `shopifyId` attribute from the asset when it is saved.
    // Because the property has been removed, the asset will be re-uploaded to Shopify on the next sync.
    public function removeShopifyFileId(\Pimcore\Event\Model\AssetEvent $event): void
    {
        $asset = $event->getAsset();
        
        $asset->removeProperty('TorqSS:ShopifyFileId');
        $asset->removeProperty('TorqSS:ShopifyUploadStatus');
        $asset->removeProperty('TorqSS:ShopifyFileStatus');
    }
}

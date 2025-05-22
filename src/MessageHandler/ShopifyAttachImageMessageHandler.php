<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyAttachImageMessage;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

#[AsMessageHandler]
final class ShopifyAttachImageMessageHandler
{
    private Configuration $dataHubConfig;
    private Asset $asset;

    public function __construct(
        private ApplicationLogger $applicationLogger,
        private ShopifyStore $shopifyStore,
    ) { }

    public function __invoke(ShopifyAttachImageMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);
        $this->shopifyStore->setup($this->dataHubConfig, true);
        $this->asset = Asset::getById($message->assetId);
        if (!$this->asset instanceof Asset) {
            $this->applicationLogger->error(
                "ShopifyAttachImageMessageHandler: Asset not found ({$message->assetId})", [
                    'component' => $this->shopifyStore->configLogName
                ]
            );
            return;
        }

        if( empty($message->shopifyFileId) || empty($message->shopifyProductId) ) {
            $this->applicationLogger->error(
                "ShopifyAttachImageMessageHandler: Missing ShopifyFileId or ShopifyProductId ({$message->assetId})", [
                    'component' => $this->shopifyStore->configLogName
                ]
            );
            return;
        }

        try {
            // attach Shopify image to Shopify Product
            if( $this->shopifyStore->attachImageToProduct( $message->shopifyFileId, $message->shopifyProductId, $message->shopifyFileStatus, $message->assetId ) ) {
                // update PIM property for ShopifyUploadStatus
                $this->asset->setProperty( 'TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_DONE, false, false );
                $this->asset->save();
            }

        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Processing ShopifyUploadImageMessage ({$message->dataHubConfigName}): " . $e->getMessage(), 
                $e,
                component: $this->shopifyStore->configLogName,
            );
        }
    }
}

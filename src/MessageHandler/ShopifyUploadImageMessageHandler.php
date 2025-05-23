<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Product;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyUploadImageMessage;
use TorqIT\StoreSyndicatorBundle\Services\ExecutionService;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

#[AsMessageHandler]
final class ShopifyUploadImageMessageHandler
{
    private Configuration $dataHubConfig;
    private Asset $asset;
    private Product $product;

    public function __construct(
        private ApplicationLogger $applicationLogger,
        private ShopifyStore $shopifyStore,
        ) 
    { 

    }

    public function __invoke(ShopifyUploadImageMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);

        $this->shopifyStore->setup($this->dataHubConfig, true);

        $this->asset = Asset::getById($message->assetId);
        if (!$this->asset instanceof Asset) {
            $this->applicationLogger->error(
                "ShopifyUploadImageMessageHandler: Asset not found ({$message->assetId})", [
                    'component' => $this->shopifyStore->configLogName
                ]
            );
            return;
        }

        try {

            // send fileCreate request to Shopify
            list( $shopifyFileStatus, $shopifyFileId ) = $this->shopifyStore->createImage( $this->asset );

            // if these are empty, we cannot continue
            if( empty($shopifyFileId) ) {
                $this->applicationLogger->error(
                    "ShopifyUploadImageMessageHandler: Missing ShopifyFileId ({$message->assetId}) after upload", [
                        'component' => $this->shopifyStore->configLogName
                    ]
                );
                $this->asset->setProperty( 'TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_ERROR, false, false );
                $this->asset->save();
                return;
            }

            // set PIM property with Shopify path/id
            $this->asset->setProperty( 'TorqSS:ShopifyFileId', 'text', $shopifyFileId, false, false );

            // update PIM property for ShopifyUploadStatus
            $this->asset->setProperty( 'TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_ATTACH, false, false );
            $this->asset->save();

            // attach Shopify image to Shopify Product
            // the 3rd parameter will allow the attach to run immediately if the file is READY
            if( $this->shopifyStore->attachImageToProduct( $shopifyFileId, $message->shopifyProductId, $shopifyFileStatus, $message->assetId ) ) {
                // update PIM property for ShopifyUploadStatus
                $this->asset->setProperty( 'TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_DONE, false, false );
                $this->asset->save();
            }

        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Processing ShopifyUploadImageMessage ({$message->dataHubConfigName}): " . $e->getMessage(),
                $e,
                component: 'StoreSyndicator'
            );
        }
    }
}

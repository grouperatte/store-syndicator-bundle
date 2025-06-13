<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Bundle\ApplicationLoggerBundle\FileObject;
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
    ) {}

    public function __invoke(ShopifyAttachImageMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);
        $this->shopifyStore->setup($this->dataHubConfig, true);
        $this->asset = Asset::getById($message->assetId);
        if (!$this->asset instanceof Asset) {
            $this->applicationLogger->error(
                "ShopifyAttachImageMessageHandler: Asset not found ({$message->assetId})",
                [
                    'component' => $this->shopifyStore->configLogName,
                    'fileObject' => new FileObject($message->toJson()),
                ]
            );
            return;
        }

        if (empty($message->shopifyFileId) || empty($message->shopifyProductId)) {
            $this->applicationLogger->error(
                "ShopifyAttachImageMessageHandler: Missing ShopifyFileId or ShopifyProductId ({$message->assetId})",
                [
                    'component' => $this->shopifyStore->configLogName,
                    'fileObject' => new FileObject($message->toJson()),
                ]
            );
            return;
        }

        $this->applicationLogger->debug(
            "ShopifyAttachImageMessageHandler: Processing attach image for asset ({$message->assetId})",
            [
                'component' => $this->shopifyStore->configLogName,
                'relatedObject' => $this->asset,
                'fileObject' => new FileObject($message->toJson())
            ]
        );

        try {
            // The current attempt number (starting from 1)
            $currentAttempt = $message->messageRetryAttempts + 1;

            // attach Shopify image to Shopify Product
            $shopifyFileStatus = $this->shopifyStore->attachImageToProduct(
                $message->shopifyFileId,
                $message->shopifyProductId,
                $message->shopifyFileStatus,
                $message->assetId,
                $currentAttempt
            );

            if ($shopifyFileStatus) {
                // Success: update PIM property for ShopifyUploadStatus
                $this->asset->setProperty('TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_DONE, false, false);
                $this->asset->setProperty('TorqSS:ShopifyProductId', 'text', $message->shopifyProductId, false, false);
                $this->asset->save();

                $this->applicationLogger->debug(
                    "ShopifyAttachImageMessageHandler: Attached ({$message->assetId})",
                    [
                        'component' => $this->shopifyStore->configLogName,
                        'fileObject' => new FileObject($message->toJson()),
                    ]
                );
            } elseif ($currentAttempt >= $this->shopifyStore->getMaxRetryAttempts()) {
                // Failed and reached max attempts: clean up properties
                $this->asset->removeProperty('TorqSS:ShopifyUploadStatus');
                $this->asset->removeProperty('TorqSS:ShopifyProductId');
                $this->asset->removeProperty('TorqSS:ShopifyFileStatus');
                $this->asset->save();

                $this->applicationLogger->error(
                    "Max attempts ({$this->shopifyStore->getMaxRetryAttempts()}) reached for ShopifyAttachImageMessage, properties removed",
                    [
                        'component' => $this->shopifyStore->configLogName,
                        'fileObject' => new FileObject(json_encode($message->toJson()))
                    ]
                );
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

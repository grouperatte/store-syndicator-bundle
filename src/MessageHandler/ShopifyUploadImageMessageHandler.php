<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Bundle\ApplicationLoggerBundle\FileObject;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Messenger\MessageBusInterface;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyUploadImageMessage;
use TorqIT\StoreSyndicatorBundle\Services\Stores\ShopifyStore;

#[AsMessageHandler]
final class ShopifyUploadImageMessageHandler
{
    private Configuration $dataHubConfig;
    private Asset $asset;

    public function __construct(
        private ApplicationLogger $applicationLogger,
        private ShopifyStore $shopifyStore,
        private MessageBusInterface $messageBus,
    ) {}

    public function __invoke(ShopifyUploadImageMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);

        $this->shopifyStore->setup($this->dataHubConfig, true);

        $this->asset = Asset::getById($message->assetId);
        if (!$this->asset instanceof Asset) {
            $this->applicationLogger->error(
                "ShopifyUploadImageMessageHandler: Asset not found ({$message->assetId})",
                [
                    'component' => $this->shopifyStore->configLogName
                ]
            );
            return;
        }

        $this->applicationLogger->debug(
            "ShopifyUploadImageMessageHandler: Processing upload for asset ({$message->assetId})",
            [
                'component' => $this->shopifyStore->configLogName,
                'relatedObject' => $this->asset,
                'fileObject' => new FileObject($message->toJson())
            ]
        );

        $shopifyProductId = $message->shopifyProductId;

        // if the message does not know the ShopifyProductId, we can look it up from the product
        if (empty($message->shopifyProductId)) {

            $product = Product::getById($message->productId);
            if ($product) {
                $shopifyProductId = $this->shopifyStore->getStoreId($product);
            }
        }

        if (empty($shopifyProductId)) {
            $this->applicationLogger->error(
                "ShopifyUploadImageMessageHandler: ShopifyProductId not found for asset ({$message->assetId})",
                [
                    'component' => $this->shopifyStore->configLogName,
                    'fileObject' => new FileObject($message->toJson()),
                ]
            );
            return;
        }

        try {

            // send fileCreate request to Shopify
            list($shopifyFileStatus, $shopifyFileId) = $this->shopifyStore->createImage($this->asset);
            $this->applicationLogger->debug(
                "ShopifyUploadImageMessageHandler: Upload result ({$message->assetId}) $shopifyFileId / $shopifyFileStatus",
                ['component' => $this->shopifyStore->configLogName]
            );


            // if these are empty, we cannot continue
            if (empty($shopifyFileId)) {
                $this->applicationLogger->error(
                    "ShopifyUploadImageMessageHandler: Upload failed; missing ShopifyFileId ({$message->assetId})",
                    [
                        'component' => $this->shopifyStore->configLogName
                    ]
                );
                $this->asset->setProperty('TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_ERROR, false, false);
                $this->asset->save();
                return;
            }

            // set PIM property with Shopify path/id
            $this->asset->setProperty('TorqSS:ShopifyFileId', 'text', $shopifyFileId, false, false);

            // update PIM property for ShopifyUploadStatus
            $this->asset->setProperty('TorqSS:ShopifyFileStatus', 'text', $shopifyFileStatus, false, false);
            $this->asset->setProperty('TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_ATTACH, false, false);
            $this->asset->save();

            if ($shopifyFileStatus === 'READY') // returned from Shopify and we can only link if READY
            {
                $this->applicationLogger->debug(
                    "ShopifyUploadImageMessageHandler: Attaching now ({$message->assetId})",
                    ['component' => $this->shopifyStore->configLogName]
                );

                // attach Shopify image to Shopify Product
                if ($this->shopifyStore->attachImageToProduct($shopifyFileId, $message->shopifyProductId, $shopifyFileStatus, $message->assetId)) {
                    // update PIM property for ShopifyUploadStatus
                    $this->asset->setProperty('TorqSS:ShopifyUploadStatus', 'text', ShopifyStore::STATUS_DONE, false, false);
                    $this->asset->save();
                }
            } else {
                $this->applicationLogger->debug(
                    "ShopifyUploadImageMessageHandler: Attaching later ({$message->assetId})",
                    ['component' => $this->shopifyStore->configLogName]
                );

                // since it is not ready right now, process this later
                $this->messageBus->dispatch(
                    $this->shopifyStore->newDelayedShopifyAttachImageEnvelope(
                        $message->dataHubConfigName,
                        $shopifyFileId,
                        $message->shopifyProductId,
                        $shopifyFileStatus,
                        $message->assetId
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Processing ShopifyUploadImageMessage: " . $e->getMessage(),
                $e,
                component: $this->shopifyStore->configLogName
            );
        }
    }
}

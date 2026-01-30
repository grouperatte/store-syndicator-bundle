<?php

namespace TorqIT\StoreSyndicatorBundle\Message;


final class ShopifyAttachImageMessage
{
    public function __construct(
        public readonly string $dataHubConfigName,
        public readonly string $shopifyFileId,
        public readonly string $shopifyProductId,
        public readonly string $shopifyFileStatus,
        public readonly int $assetId,
        public int $messageRetryAttempts = 0,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'dataHubConfigName' => $this->dataHubConfigName,
            'shopifyFileId' => $this->shopifyFileId,
            'shopifyProductId' => $this->shopifyProductId,
            'shopifyFileStatus' => $this->shopifyFileStatus,
            'assetId' => $this->assetId,
            'messageRetryAttempts' => $this->messageRetryAttempts
        ]);
    }
}

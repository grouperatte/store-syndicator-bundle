<?php

namespace TorqIT\StoreSyndicatorBundle\Message;


final class ShopifyUploadImageMessage
{
    public function __construct(
        public readonly string $dataHubConfigName,
        public readonly int $assetId,
        public readonly string $shopifyProductId
    ) { }


    public function toJson(): string
    {
        return json_encode([
            'dataHubConfigName' => $this->dataHubConfigName,
            'assetId' => $this->assetId,
            'shopifyProductId' => $this->shopifyProductId
        ]);
    }
}
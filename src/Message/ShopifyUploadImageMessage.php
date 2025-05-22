<?php

namespace TorqIT\StoreSyndicatorBundle\Message;


final class ShopifyUploadImageMessage
{
    public function __construct(
        public readonly string $dataHubConfigName,
        public readonly int $assetId,
        public readonly string $shopifyProductId
    ) { }
}
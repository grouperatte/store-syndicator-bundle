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
    ) { }
}
<?php

namespace TorqIT\StoreSyndicatorBundle\Message;


final class ShopifyCreateProductMessage
{
    public function __construct(
        public readonly int $productId,
        public readonly string $dataHubConfigName,
    ) { }
}
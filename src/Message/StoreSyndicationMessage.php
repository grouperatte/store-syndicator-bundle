<?php

namespace TorqIT\StoreSyndicatorBundle\Message;


final class StoreSyndicationMessage
{
    public function __construct(
        public readonly string $dataHubConfigName,
        public readonly int $offset = 0,
    ) { }
}
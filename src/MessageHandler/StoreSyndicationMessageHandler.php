<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\DataHubBundle\Configuration;

use TorqIT\StoreSyndicatorBundle\Message\StoreSyndicationMessage;
use TorqIT\StoreSyndicatorBundle\Services\ExecutionService;

#[AsMessageHandler]
final class StoreSyndicationMessageHandler
{
    private Configuration $dataHubConfig;

    public function __construct(
        private ApplicationLogger $applicationLogger,
        private ExecutionService $executionService,
        ) 
    { }

    public function __invoke(StoreSyndicationMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);
        
        try {
            $this->executionService->export($this->dataHubConfig);

        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Processing ShopifyStoreSyndication Message ({$message->dataHubConfigName}): " . $e->getMessage(),
                $e,
                component: 'StoreSyndicator'
            );
        }
    }
}

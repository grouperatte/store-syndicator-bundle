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

        // TODO: Make sure we can use the pushStock method which is a very much more slim version of the export, right now, it does not work because the linking process cannot be executed safely.
        // $method = str_contins($message->dataHubConfigName, "Stock") ? 'pushStock' : 'export';
        $method = "export";
        
        try {
            $this->executionService->$method($this->dataHubConfig);

        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Processing ShopifyStoreSyndication Message ({$message->dataHubConfigName}): " . $e->getMessage(),
                $e,
                component: 'STORE_SYNDICATOR ' . $message->dataHubConfigName,
            );
        }
    }
}

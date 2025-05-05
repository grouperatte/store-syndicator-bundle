<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Psr\Log\LogLevel;
use Symfony\Component\Messenger\MessageBusInterface;
use TorqIT\StoreSyndicatorBundle\Message\StoreSyndicationMessage;
use TorqIT\StoreSyndicatorBundle\Services\ExecutionService;

#[AsMessageHandler]
final class StoreSyndicationMessageHandler
{
    private Configuration $dataHubConfig;
    private static int $batchSize;
    private string $logComponentName;

    public function __construct(
        private ApplicationLogger $applicationLogger,
        private ExecutionService $executionService,
        private MessageBusInterface $messageBus,
        ) 
    { 
        self::$batchSize = 50; // ToDo: make this configurable
    }

    public function __invoke(StoreSyndicationMessage $message): void
    {
        // this naming matches logging in the execution service 
        $this->logComponentName = 'STORE_SYNDICATOR ' . $message->dataHubConfigName;

        // load the actual DataHub config from its name in the message
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);
        
        try {
            $hasMoreBatches = $this->executionService->export($this->dataHubConfig, $message->offset, self::$batchSize);

        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "StoreSyndicationMessageHandler: Error Processing Message ({$message->dataHubConfigName}): " . $e->getMessage(),
                $e,
                component: $this->logComponentName,                  
            );
        }

        // execution service will return true if we should continue with more batches
        if( $hasMoreBatches ) {
            $nextOffset = $message->offset + self::$batchSize;

            $this->applicationLogger->log( LogLevel::INFO,
                "StoreSyndicationMessageHandler: Completed batch for {$message->dataHubConfigName} ({$message->offset}); queueing next batch ({$nextOffset})",
                ['component' => $this->logComponentName ],
            );

            // Requeue the message with the next offset
            $this->messageBus->dispatch(new StoreSyndicationMessage($message->dataHubConfigName, $nextOffset));

        } else {
            $this->applicationLogger->log( LogLevel::INFO,
                "StoreSyndicationMessageHandler: Finished processing all batches for {$message->dataHubConfigName} at offset {$message->offset}",
                ['component' => $this->logComponentName ],
            );
        }
    }
}

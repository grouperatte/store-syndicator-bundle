<?php

namespace TorqIT\StoreSyndicatorBundle\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject;
use Psr\Log\LoggerInterface;
use TorqIT\StoreSyndicatorBundle\Message\ShopifyCreateProductMessage;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use TorqIT\StoreSyndicatorBundle\Services\ShopifyHelpers\ShopifyQueryService;

#[AsMessageHandler]
final class ShopifyCreateProductMessageHandler
{
    private ShopifyQueryService $shopifyQueryService;
    private Configuration $dataHubConfig;

    public function __construct(
        private ApplicationLogger $applicationLogger, 
        protected LoggerInterface $customLogLogger,
        ) 
    { }

    public function __invoke(ShopifyCreateProductMessage $message): void
    {
        $this->dataHubConfig = Configuration::getByName($message->dataHubConfigName);
        
        $this->shopifyQueryService = new ShopifyQueryService(
            ShopifyAuthenticator::getAuthenticatorFromConfig($this->dataHubConfig), 
            $this->customLogLogger
        );

        try {
            $product = DataObject::getById($message->productId);
            if ($product) {
                
                $this->shopifyQueryService->createProduct($product);

            }
        } catch (\Throwable $e) {
            $this->applicationLogger->logException(
                "Error Syndicating Product {$message->productId}: " . $e->getMessage(),
                $e,
                component: 'StoreSyndicator'
            );
        }
    }
}

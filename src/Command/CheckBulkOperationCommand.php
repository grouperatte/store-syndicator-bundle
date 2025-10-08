<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Console\AbstractCommand;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyQueryService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;

class CheckBulkOperationCommand extends AbstractCommand
{
    public function __construct(
        private ApplicationLogger $applicationLogger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('torq:check-bulk-operation')
            ->addArgument('store-name', InputArgument::REQUIRED, 'The name of the store configuration')
            ->setDescription('Check if there is a bulk operation currently running on Shopify');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeName = $input->getArgument('store-name');
        $configLogName = "STORE_SYNDICATOR " . $storeName;

        try {
            // Get the configuration
            $config = Configuration::getByName($storeName);
            if (!$config) {
                $output->writeln("<error>Configuration '$storeName' not found.</error>");
                return self::FAILURE;
            }

            // Create authenticator and query service
            $authenticator = ShopifyAuthenticator::getAuthenticatorFromConfig($config);
            $shopifyQueryService = new ShopifyQueryService($authenticator, $this->applicationLogger, $configLogName);

            // Check bulk operation status
            $operationStatus = $shopifyQueryService->checkBulkOperation();

            if ($operationStatus['status'] === 'ERROR') {
                $output->writeln("<error>Error checking bulk operation: " . ($operationStatus['error'] ?? 'Unknown error') . "</error>");
                return self::FAILURE;
            }

            if ($operationStatus['status'] === 'NONE') {
                $output->writeln("<info>No bulk operation currently running.</info>");
                return self::SUCCESS;
            }

            // Display operation details in a table
            $output->writeln("<info>Bulk Operation Status:</info>");
            
            $table = new Table($output);
            $table->setHeaders(['Property', 'Value']);
            
            $rows = [
                ['Is Running', $operationStatus['isRunning'] ? 'Yes' : 'No'],
                ['Status', $operationStatus['status']],
                ['ID', $operationStatus['id']],
                ['Type', $operationStatus['type'] ?? 'N/A'],
                ['Created At', $operationStatus['createdAt'] ?? 'N/A'],
                ['Completed At', $operationStatus['completedAt'] ?? 'N/A'],
                ['Object Count', $operationStatus['objectCount'] ?? 'N/A'],
                ['File Size', $operationStatus['fileSize'] ? number_format($operationStatus['fileSize']) . ' bytes' : 'N/A'],
                ['Error Code', $operationStatus['errorCode'] ?? 'None'],
            ];

            if (!empty($operationStatus['url'])) {
                $rows[] = ['Result URL', $operationStatus['url']];
            }

            if (!empty($operationStatus['partialDataUrl'])) {
                $rows[] = ['Partial Data URL', $operationStatus['partialDataUrl']];
            }

            $table->setRows($rows);
            $table->render();

            if ($operationStatus['isRunning']) {
                $output->writeln("<comment>A bulk operation is currently running. You may need to wait for it to complete or cancel it before starting a new one.</comment>");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return self::FAILURE;
        }
    }
}
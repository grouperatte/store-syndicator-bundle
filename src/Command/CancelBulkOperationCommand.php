<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Console\AbstractCommand;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TorqIT\StoreSyndicatorBundle\Utility\ShopifyQueryService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\ShopifyAuthenticator;
use Pimcore\Bundle\ApplicationLoggerBundle\ApplicationLogger;

class CancelBulkOperationCommand extends AbstractCommand
{
    public function __construct(
        private ApplicationLogger $applicationLogger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('torq:cancel-bulk-operation')
            ->addArgument('store-name', InputArgument::REQUIRED, 'The name of the store configuration')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The ID of the bulk operation to cancel')
            ->setDescription('Cancel a running bulk operation on Shopify');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeName = $input->getArgument('store-name');
        $operationId = $input->getOption('id');
        $configLogName = "STORE_SYNDICATOR " . $storeName;

        if (!$operationId) {
            $output->writeln("<error>The --id option is required. Please provide the bulk operation ID to cancel.</error>");
            return self::FAILURE;
        }

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

            $output->writeln("<info>Attempting to cancel bulk operation: $operationId</info>");

            // Cancel the bulk operation
            $result = $shopifyQueryService->cancelBulkOperation($operationId);

            if ($result['success']) {
                $output->writeln("<info>" . $result['message'] . "</info>");
                
                if (isset($result['operation'])) {
                    $operation = $result['operation'];
                    $output->writeln("<info>Operation Details:</info>");
                    $output->writeln("  ID: " . ($operation['id'] ?? 'N/A'));
                    $output->writeln("  Status: " . ($operation['status'] ?? 'N/A'));
                    $output->writeln("  Error Code: " . ($operation['errorCode'] ?? 'None'));
                }
                
                return self::SUCCESS;
            } else {
                $output->writeln("<error>Failed to cancel bulk operation:</error>");
                foreach ($result['errors'] as $error) {
                    $output->writeln("  - $error");
                }
                
                if (isset($result['operation'])) {
                    $operation = $result['operation'];
                    $output->writeln("<info>Current Operation Status:</info>");
                    $output->writeln("  ID: " . ($operation['id'] ?? 'N/A'));
                    $output->writeln("  Status: " . ($operation['status'] ?? 'N/A'));
                    $output->writeln("  Error Code: " . ($operation['errorCode'] ?? 'None'));
                }
                
                return self::FAILURE;
            }

        } catch (\Exception $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return self::FAILURE;
        }
    }
}
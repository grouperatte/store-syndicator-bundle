<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Console\Input\InputArgument;
use TorqIT\StoreSyndicatorBundle\Services\ExecutionService;
use TorqIT\StoreSyndicatorBundle\Services\Stores\Models\LogRow;

class PushToShopifyCommand extends AbstractCommand
{
    private ExecutionService $executionService;

    public function __construct(ExecutionService $executionService)
    {
        parent::__construct();

        $this->executionService = $executionService;
    }

    protected function configure()
    {
        $this
            ->setName('torq:push-to-shopify')
            ->addArgument('store-name', InputArgument::REQUIRED)
            ->setDescription('Do Shopify Stuff');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $initialTime = time();
        $output->writeln("start time: " . $initialTime);
        $name = $input->getArgument("store-name");

        $config = Configuration::getByName($name);

        $this->executionService->export($config);

        $finalTime = time();
        $diff = $finalTime - $initialTime;
        $output->writeln("final time: " . time());
        $output->writeln("execution duration: " . $diff);

        return self::SUCCESS;
    }
}

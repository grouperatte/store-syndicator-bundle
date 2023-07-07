<?php

namespace TorqIT\StoreSyndicatorBundle\Command;

use Pimcore\Model\DataObject;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\Product;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TorqIT\StoreSyndicatorBundle\Services\Configuration\ConfigurationService;

class ClearLogsCommand extends AbstractCommand
{
    public function __construct(private ConfigurationService $configurationService)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('torq:clear-logs')
            ->addArgument('store-name', InputArgument::REQUIRED)
            ->setDescription('Deletes the logs for export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument("store-name");
        $configName = "DATA-IMPORTER " . $name;
        $db = Db::get();

        $result = $db->executeStatement('Delete from application_logs where component = ?', [$configName]);
        return 0;
    }
}

<?php
namespace GPM\CLI;

use GPM\Model\GitPackage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BuildPublish extends Command {
    private $buildPublish;
    private $package;

    public function __construct($name, GitPackage $package, \GPM\Operations\BuildPublish $buildPublish)
    {
        $this->buildPublish = $buildPublish;
        $this->package = $package;

        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Build transport package and publish it with Packeteer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output);
        $this->buildPublish->setLogger($logger);

        $this->buildPublish->execute($this->package->dir_name);

        return Command::SUCCESS;
    }
}

<?php
namespace GPM\Commands\Package;

use GPM\Commands\GPMCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Publish extends GPMCommand
{
    protected function configure()
    {
        $this
            ->setName('package:publish')
            ->setDescription('Build, upload and publish a transport package.')
            ->addOption(
                'pkg',
                null,
                InputOption::VALUE_REQUIRED,
                'Package or folder name'
            )
            ->addOption(
                'useKey',
                null,
                InputOption::VALUE_NONE,
                'If passed package key will be used instead of package name'
            )
            ->addOption(
                'encryptKey',
                null,
                InputOption::VALUE_OPTIONAL,
                'If passed the package will be optional encrypted with that key'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getOption('pkg');
        if (empty($name)) {
            $this->error($output, 'Option pkg is required.');
            return 1;
        }

        $pkgMatcher = $input->getOption('useKey') ? 'key' : 'name';
        $c = array($pkgMatcher => $name);
        if ($pkgMatcher == 'name') {
            $c['OR:dir_name:='] = $name;
        }

        $pkg = $this->getApplication()->modx->getObject('GitPackage', $c);

        if (empty($pkg)) {
            $this->error($output, 'Package ' . ($input->getOption('useKey') ? 'with key ' : '') . $name . ' was not found.');
            return 1;
        }

        $options = array(
            'id' => $pkg->id,
            'encryptKey' => $input->getOption('encryptKey')
        );

        /** @var \modProcessorResponse $response */
        $response = $this->getApplication()->gpm->runProcessor('mgr/gitpackage/buildpackagepublish', $options);

        if (!$response->isError()) {
            $message = $response->getMessage();
            $output->writeln('Package published.' . ($message ? ' ' . strip_tags(str_replace('<br>', "\n", $message)) : ''));
            return 0;
        }

        $this->error($output, strip_tags(str_replace('<br>', "\n", $response->getMessage())));
        return 1;
    }
}

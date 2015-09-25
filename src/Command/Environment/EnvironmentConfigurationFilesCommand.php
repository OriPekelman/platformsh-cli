<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Helper\ConfigHelper;

class EnvironmentConfigurationFilesCommand extends PlatformCommand
{

    protected function configure()
    {
        parent::configure();
        $this
          ->setName('environment:configuration_files')
          ->setAliases(array('config'))
          ->setDescription('Get current configuration files status');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

      $configHelper = new ConfigHelper($this->getApplication());

       $output->writeln("Has Platform Yaml ". $configHelper->hasPlatformYaml());
       $output->writeln("Has Services Yaml ". $configHelper->hasServicesYaml());
       $output->writeln("Has Routes Yaml ". $configHelper->hasRoutesYaml());
      return 0;
    }
}

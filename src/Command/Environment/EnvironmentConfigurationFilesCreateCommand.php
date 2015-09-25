<?php
/*
* FIXME: This is just a rough example.
* We want a wizard. Asking:
* 1. app name and validating it is ok with our rules
* 2. Runtime? PHP/HHVM .. + version (and later node etc)
* 3. drupal or symfony? Or later others?
* 4. Services (by default just propose MySQL)
* 5. Do you want to serve static content (and propose a default whitelist)
* This could be used in the "init command" + / Create project command.
*/
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Helper\ConfigHelper;

class EnvironmentConfigurationFilesCreateCommand extends PlatformCommand
{

    protected function configure()
    {
        parent::configure();
        $this
          ->setName('environment:configuration_files_create')
          ->setAliases(array('create_config'))
          ->setDescription('Create configuration files');
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
      $configHelper = new ConfigHelper($this->getApplication());
      if($configHelper->hasPlatformYaml()==0) {
        $output->writeln("Creating .platform.app.yaml you should really edit it");
        $configHelper->createPlatformYaml();
      };
      if($configHelper->hasServicesYaml()==0) {
        $output->writeln("Creating .platform/services.yaml you should really edit it");
        $configHelper->createServicesYaml();
      };
      if($configHelper->hasRoutesYaml()==0) {
        $output->writeln("Creating .platform/routes.yaml you should really edit it");
        $configHelper->createRoutesYaml();
      };
      return 0;
    }
}

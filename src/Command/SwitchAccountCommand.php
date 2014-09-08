<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SwitchAccountCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('platform:switch_account')
          ->setAliases(array('switch'))
          ->setDescription('Switch account you use to login to platform');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelper('dialog');
        $configs = $this->listConfigs();

        if(empty($configs)) {
           $this->saveConfig();
            $output->writeln('No configs found creating default');
          } else {
        $config = $dialog->select(
            $output,
            'Select account to activate',
            array_map(create_function('$config', 'return $config["label"];'), $configs ),
            0
        );
        $this->activateConfig($configs[$config]["path"]);
        $output->writeln('You have just selected: ' . $configs[$config]["label"]);
        
      }
        $this->__destruct();
    }
}

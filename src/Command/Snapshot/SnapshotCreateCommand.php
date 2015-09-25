<?php
namespace Platformsh\Cli\Command\Snapshot;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SnapshotCreateCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('snapshot:create')
          ->setHiddenAliases(array('backup', 'environment:backup'))
          ->setDescription('Make a snapshot of an environment')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the snapshot to complete');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->setHelp('See https://docs.platform.sh/use-platform/backup-and-restore.html');
        $this->addExample('Make a snapshot of the current environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment['id'];
        if (!$selectedEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
              "Operation not available: cannot create a snapshot of <error>$environmentId</error>"
            );

            return 1;
        }

        $activity = $selectedEnvironment->backup();

        $this->stdErr->writeln("Creating a snapshot of <info>$environmentId</info>");

        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
              "A snapshot of environment <info>$environmentId</info> has been created",
              "The snapshot failed"
            );
            if (!$success) {
                return 1;
            }
        }

        if (!empty($activity['payload']['backup_name'])) {
            $name = $activity['payload']['backup_name'];
            $output->writeln("Snapshot name: <info>$name</info>");
        }

        return 0;
    }
}

<?php
/*
 * This file is part of the Magallanes package.
 *
 * (c) Andrés Montañez <andres@andresmontanez.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mage\Command\BuiltIn;

use Mage\Runtime\Exception\RuntimeException;
use Mage\Runtime\Runtime;
use Mage\Task\ExecuteOnRollbackInterface;
use Mage\Task\AbstractTask;
use Mage\Task\Exception\ErrorException;
use Mage\Task\Exception\SkipException;
use Mage\Task\TaskFactory;
use Mage\Utils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Mage\Command\AbstractCommand;

/**
 * The Deployment Command
 *
 * @author Andrés Montañez <andresmontanez@gmail.com>
 */
class DeployCommand extends AbstractCommand
{
    /**
     * @var int
     */
    protected $statusCode = 0;

    /**
     * @var TaskFactory
     */
    protected $taskFactory;

    /**
     * Configure the Command
     */
    protected function configure()
    {
        $this
            ->setName('deploy')
            ->setDescription('Deploy code to hosts')
            ->addArgument('environment', InputArgument::REQUIRED, 'Name of the environment to deploy to')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Force to switch to a branch other than the one defined', false)
        ;
    }

    /**
     * Execute the Command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|mixed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Starting <fg=blue>Magallanes</>');
        $output->writeln('');

        try {
            $this->runtime->setEnvironment($input->getArgument('environment'));

            $output->writeln(sprintf('    Environment: <fg=green>%s</>', $this->runtime->getEnvironment()));
            $this->log(sprintf('Environment: %s', $this->runtime->getEnvironment()));

            if ($this->runtime->getEnvironmentConfig('releases', false)) {
                $this->runtime->generateReleaseId();
                $output->writeln(sprintf('    Release ID: <fg=green>%s</>', $this->runtime->getReleaseId()));
                $this->log(sprintf('Release ID: %s', $this->runtime->getReleaseId()));
            }

            if ($this->runtime->getConfigOptions('log_file', false)) {
                $output->writeln(sprintf('    Logfile: <fg=green>%s</>', $this->runtime->getConfigOptions('log_file')));
            }

            if ($input->getOption('branch') !== false) {
                $this->runtime->setEnvironmentConfig('branch', $input->getOption('branch'));
            }

            if ($this->runtime->getEnvironmentConfig('branch', false)) {
                $output->writeln(sprintf('    Branch: <fg=green>%s</>', $this->runtime->getEnvironmentConfig('branch')));
            }

            $output->writeln('');

            $this->taskFactory = new TaskFactory($this->runtime);
            $this->runDeployment($output);

        } catch (RuntimeException $exception) {
            $output->writeln('');
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));
            $output->writeln('');
            $this->statusCode = 7;
        }

        $output->writeln('Finished <fg=blue>Magallanes</>');

        return $this->statusCode;
    }

    /**
     * Run the Deployment Process
     *
     * @param OutputInterface $output
     * @throws RuntimeException
     */
    protected function runDeployment(OutputInterface $output)
    {
        // Run "Pre Deploy" Tasks
        $this->runtime->setStage(Runtime::PRE_DEPLOY);
        $preDeployTasks = $this->runtime->getTasks();

        if ($this->runtime->getEnvironmentConfig('branch', false) && !$this->runtime->inRollback()) {
            if (!in_array('git/change-branch', $preDeployTasks)) {
                array_unshift($preDeployTasks, 'git/change-branch');
            }
        }

        if ($this->runtime->getEnvironmentConfig('releases', false) && !$this->runtime->inRollback()) {
            if (!in_array('deploy/targz/prepare', $preDeployTasks)) {
                array_push($preDeployTasks, 'deploy/targz/prepare');
            }
        }

        if (!$this->runTasks($output, $preDeployTasks)) {
            throw new RuntimeException(sprintf('Stage "%s" did not finished successfully, halting command.', $this->getStageName()), 50);
        }

        // Run "On Deploy" Tasks
        $hosts = $this->runtime->getEnvironmentConfig('hosts');
        if (count($hosts) == 0) {
            $output->writeln('    No hosts defined, skipping On Deploy tasks');
            $output->writeln('');
        } else {
            $this->runtime->setStage(Runtime::ON_DEPLOY);
            $onDeployTasks = $this->runtime->getTasks();

            if (!$this->runtime->inRollback()) {
            }

            if ($this->runtime->getEnvironmentConfig('releases', false)) {
                if (!in_array('deploy/targz/copy', $onDeployTasks) && !$this->runtime->inRollback()) {
                    array_unshift($onDeployTasks, 'deploy/targz/copy');
                }

                if (!in_array('deploy/release/prepare', $onDeployTasks) && !$this->runtime->inRollback()) {
                    array_unshift($onDeployTasks, 'deploy/release/prepare');
                }
            } else {
                if (!in_array('deploy/rsync', $onDeployTasks)) {
                    array_unshift($onDeployTasks, 'deploy/rsync');
                }
            }

            foreach ($hosts as $host) {
                $this->runtime->setWorkingHost($host);
                if (!$this->runTasks($output, $onDeployTasks)) {
                    $this->runtime->setWorkingHost(null);
                    throw new RuntimeException(sprintf('Stage "%s" did not finished successfully, halting command.', $this->getStageName()), 50);
                }
                $this->runtime->setWorkingHost(null);
            }
        }

        // Run "On Release" Tasks
        $hosts = $this->runtime->getEnvironmentConfig('hosts');
        if (count($hosts) == 0) {
            $output->writeln('    No hosts defined, skipping On Release tasks');
            $output->writeln('');
        } else {
            $this->runtime->setStage(Runtime::ON_RELEASE);
            $onReleaseTasks = $this->runtime->getTasks();

            if ($this->runtime->getEnvironmentConfig('releases', false)) {
                if (!in_array('deploy/release', $onReleaseTasks)) {
                    array_unshift($onReleaseTasks, 'deploy/release');
                }
            }

            foreach ($hosts as $host) {
                $this->runtime->setWorkingHost($host);
                if (!$this->runTasks($output, $onReleaseTasks)) {
                    $this->runtime->setWorkingHost(null);
                    throw new RuntimeException(sprintf('Stage "%s" did not finished successfully, halting command.', $this->getStageName()), 50);
                }
                $this->runtime->setWorkingHost(null);
            }
        }

        // Run "Post Release" Tasks
        $hosts = $this->runtime->getEnvironmentConfig('hosts');
        if (count($hosts) == 0) {
            $output->writeln('    No hosts defined, skipping Post Release tasks');
            $output->writeln('');
        } else {
            $this->runtime->setStage(Runtime::POST_RELEASE);
            $postReleaseTasks = $this->runtime->getTasks();

            if ($this->runtime->getEnvironmentConfig('releases', false) && !$this->runtime->inRollback()) {
                if (!in_array('deploy/release/cleanup', $postReleaseTasks)) {
                    array_unshift($postReleaseTasks, 'deploy/release/cleanup');
                }
            }

            foreach ($hosts as $host) {
                $this->runtime->setWorkingHost($host);
                if (!$this->runTasks($output, $postReleaseTasks)) {
                    $this->runtime->setWorkingHost(null);
                    throw new RuntimeException(sprintf('Stage "%s" did not finished successfully, halting command.', $this->getStageName()), 50);
                }
                $this->runtime->setWorkingHost(null);
            }
        }

        // Run "Post Deploy" Tasks
        $this->runtime->setStage(Runtime::POST_DEPLOY);
        $postDeployTasks = $this->runtime->getTasks();
        if ($this->runtime->getEnvironmentConfig('releases', false) && !$this->runtime->inRollback()) {
            if (!in_array('deploy/targz/cleanup', $postDeployTasks)) {
                array_unshift($postDeployTasks, 'deploy/targz/cleanup');
            }
        }

        if ($this->runtime->getEnvironmentConfig('branch', false) && !$this->runtime->inRollback()) {
            if (!in_array('git/change-branch', $postDeployTasks)) {
                array_push($postDeployTasks, 'git/change-branch');
            }
        }

        if (!$this->runTasks($output, $postDeployTasks)) {
            throw new RuntimeException(sprintf('Stage "%s" did not finished successfully, halting command.', $this->getStageName()), 50);
        }
    }

    /**
     * Runs all the tasks
     *
     * @param OutputInterface $output
     * @param $tasks
     * @return bool
     * @throws RuntimeException
     */
    protected function runTasks(OutputInterface $output, $tasks)
    {
        if (count($tasks) == 0) {
            $output->writeln(sprintf('    No tasks defined for <fg=black;options=bold>%s</> stage', $this->getStageName()));
            $output->writeln('');
            return true;
        }

        if ($this->runtime->getWorkingHost()) {
            $output->writeln(sprintf('    Starting <fg=black;options=bold>%s</> tasks on host <fg=black;options=bold>%s</>:', $this->getStageName(), $this->runtime->getWorkingHost()));
        } else {
            $output->writeln(sprintf('    Starting <fg=black;options=bold>%s</> tasks:', $this->getStageName()));
        }

        $totalTasks = count($tasks);
        $succeededTasks = 0;

        foreach ($tasks as $taskName) {
            /** @var AbstractTask $task */
            $task = $this->taskFactory->get($taskName);
            $output->write(sprintf('        Running <fg=magenta>%s</> ... ', $task->getDescription()));
            $this->log(sprintf('Running task %s (%s)', $task->getDescription(), $task->getName()));

            if ($this->runtime->inRollback() && !$task instanceof ExecuteOnRollbackInterface) {
                $succeededTasks++;
                $output->writeln('<fg=yellow>SKIPPED</>');
                $this->log(sprintf('Task %s (%s) finished with SKIPPED, it was in a Rollback', $task->getDescription(), $task->getName()));
            } else {
                try {
                    if ($task->execute()) {
                        $succeededTasks++;
                        $output->writeln('<fg=green>OK</>');
                        $this->log(sprintf('Task %s (%s) finished with OK', $task->getDescription(), $task->getName()));
                    } else {
                        $output->writeln('<fg=red>FAIL</>');
                        $this->statusCode = 180;
                        $this->log(sprintf('Task %s (%s) finished with FAIL', $task->getDescription(), $task->getName()));
                    }

                } catch (SkipException $exception) {
                    $succeededTasks++;
                    $output->writeln('<fg=yellow>SKIPPED</>');
                    $this->log(sprintf('Task %s (%s) finished with SKIPPED, thrown SkipException', $task->getDescription(), $task->getName()));

                } catch (ErrorException $exception) {
                    $output->writeln(sprintf('<fg=red>ERROR</> [%s]', $exception->getTrimmedMessage()));
                    $this->log(sprintf('Task %s (%s) finished with FAIL, with Error "%s"', $task->getDescription(), $task->getName(), $exception->getMessage()));
                    $this->statusCode = 190;
                }
            }

            if ($this->statusCode !== 0) {
                break;
            }
        }

        if ($succeededTasks != $totalTasks) {
            $alertColor = 'red';
        } else {
            $alertColor = 'green';
        }

        $output->writeln(sprintf('    Finished <fg=%s>%d/%d</> tasks for <fg=black;options=bold>%s</>.', $alertColor, $succeededTasks, $totalTasks, $this->getStageName()));
        $output->writeln('');

        return ($succeededTasks == $totalTasks);
    }

    /**
     * Get the Human friendly Stage name
     *
     * @return string
     */
    protected function getStageName()
    {
        return Utils::getStageName($this->runtime->getStage());
    }
}

<?php

namespace Kanboard\Plugin\Foreman\Console;

use Closure;
use Pimple\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    private Closure $clock;

    public function __construct(private Container $c, ?callable $clock = null)
    {
        $this->clock = Closure::fromCallable($clock ?? 'time');
        parent::__construct('foreman:run');
    }

    protected function configure(): void
    {
        $this->setDescription('Run scheduled Foreman jobs')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run a single enabled job until it finishes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = $input->getOption('job');
        if ($jobId !== null && !$this->isEnabledJob($jobId)) {
            $output->writeln('Unknown or disabled job: '.$jobId, OutputInterface::OUTPUT_RAW);

            return self::INVALID;
        }

        $this->c['foremanSettings']->markCliSeen();
        $runner = $this->c['foremanRunner'];
        $exitCode = self::SUCCESS;
        do {
            $repeat = false;
            foreach ($runner->tick('cli', ($this->clock)() + 50, $jobId) as $result) {
                $status = $result['status'];
                $line = $result['job_id'].': '.$status;
                if ($result['summary'] !== '') {
                    $line .= ' — '.$result['summary'];
                }
                $output->writeln($line, OutputInterface::OUTPUT_RAW);

                if ($status === 'failed') {
                    $exitCode = self::FAILURE;
                }
                $repeat = $jobId !== null && $status === 'more';
            }
        } while ($repeat);

        return $exitCode;
    }

    private function isEnabledJob(string $jobId): bool
    {
        $registry = $this->c['foremanRegistry'];
        if (!isset($registry->jobs()[$jobId])) {
            return false;
        }

        $registry->sync();
        $statement = $this->c['db']->getConnection()->prepare(
            'SELECT 1 FROM foreman_state WHERE job_id = ? AND enabled = 1'
        );
        $statement->execute([$jobId]);

        return $statement->fetchColumn() !== false;
    }
}

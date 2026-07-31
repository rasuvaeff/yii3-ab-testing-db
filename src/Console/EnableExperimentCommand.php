<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Console;

use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @api */
#[AsCommand(name: 'ab-testing:enable', description: 'Enable an experiment')]
final class EnableExperimentCommand extends Command
{
    public function __construct(private readonly ExperimentRepository $repository)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Experiment name')
            ->addArgument('revision', InputArgument::REQUIRED, 'Expected revision');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $record = $this->repository->enable(
                name: (string) $input->getArgument('name'),
                expectedRevision: (int) $input->getArgument('revision'),
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Enabled %s at revision %d', $record->experiment->name, $record->revision));

        return Command::SUCCESS;
    }
}

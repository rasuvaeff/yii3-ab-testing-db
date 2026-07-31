<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Console;

use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 */
#[AsCommand(name: 'ab-testing:validate', description: 'Validate every stored experiment')]
final class ValidateExperimentsCommand extends Command
{
    public function __construct(private readonly ExperimentRepository $repository)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $count = \count($this->repository->list());
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Valid experiments: %d', $count));

        return Command::SUCCESS;
    }
}

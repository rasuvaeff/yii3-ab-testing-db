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
#[AsCommand(name: 'ab-testing:reweight', description: 'Replace experiment variant weights')]
final class ReweightExperimentCommand extends Command
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
            ->addArgument('variants', InputArgument::REQUIRED, 'JSON object of variant weights')
            ->addArgument('revision', InputArgument::REQUIRED, 'Expected revision');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $variants = json_decode((string) $input->getArgument('variants'), true, flags: JSON_THROW_ON_ERROR);

            if (!\is_array($variants) || array_is_list($variants)) {
                throw new \InvalidArgumentException('Variants must be a JSON object');
            }

            foreach ($variants as $name => $weight) {
                if (!\is_string($name) || !\is_int($weight) || $weight < 0) {
                    throw new \InvalidArgumentException('Variant weights must be non-negative integers');
                }
            }

            /** @var array<string, int<0, max>> $variants */
            $record = $this->repository->reweight(
                name: (string) $input->getArgument('name'),
                variants: $variants,
                expectedRevision: (int) $input->getArgument('revision'),
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Reweighted %s at revision %d', $record->experiment->name, $record->revision));

        return Command::SUCCESS;
    }
}

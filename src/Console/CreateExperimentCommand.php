<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Console;

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 */
#[AsCommand(name: 'ab-testing:create', description: 'Create an experiment')]
final class CreateExperimentCommand extends Command
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
            ->addArgument('fallback', InputArgument::REQUIRED, 'Fallback variant')
            ->addOption('salt', null, InputOption::VALUE_REQUIRED, 'Assignment salt')
            ->addOption('run', null, InputOption::VALUE_NONE, 'Start the experiment immediately');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $name = (string) $input->getArgument('name');
            $variants = $this->variants((string) $input->getArgument('variants'));
            $run = $input->getOption('run') === true;
            $record = $this->repository->create(
                experiment: new Experiment(
                    name: $name,
                    enabled: $run,
                    salt: (string) ($input->getOption('salt') ?? $name),
                    fallbackVariant: (string) $input->getArgument('fallback'),
                    variants: $variants,
                ),
                state: $run ? ExperimentState::Running : ExperimentState::Draft,
            );
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Created %s at revision %d', $record->experiment->name, $record->revision));

        return Command::SUCCESS;
    }

    /** @return array<string, int<0, max>> */
    private function variants(string $json): array
    {
        $value = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Variants must be a JSON object');
        }

        $variants = [];

        foreach ($value as $name => $weight) {
            if (!\is_string($name) || !\is_int($weight) || $weight < 0) {
                throw new \InvalidArgumentException('Variant weights must be non-negative integers');
            }

            $variants[$name] = $weight;
        }

        return $variants;
    }
}

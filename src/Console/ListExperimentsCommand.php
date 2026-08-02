<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Console;

use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @api
 */
#[AsCommand(name: 'ab-testing:list', description: 'List stored experiments')]
final class ListExperimentsCommand extends Command
{
    public function __construct(private readonly ExperimentRepository $repository)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];

        foreach ($this->repository->list() as $record) {
            $rows[] = [
                $record->experiment->name,
                $record->state->value,
                $record->revision,
                $record->experiment->enabled ? 'yes' : 'no',
                json_encode($record->experiment->variants, JSON_THROW_ON_ERROR),
            ];
        }

        (new Table($output))
            ->setHeaders(['Name', 'State', 'Revision', 'Enabled', 'Variants'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}

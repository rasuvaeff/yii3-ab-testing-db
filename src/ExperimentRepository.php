<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\Experiment;

/**
 * Operational write-side for experiment definitions.
 *
 * @api
 */
interface ExperimentRepository
{
    /** @return array<string, ExperimentRecord> */
    public function list(): array;

    public function get(string $name): ExperimentRecord;

    public function create(Experiment $experiment, ?ExperimentState $state = null): ExperimentRecord;

    public function upsert(
        Experiment $experiment,
        ?int $expectedRevision = null,
        ?ExperimentState $state = null,
    ): ExperimentRecord;

    public function enable(string $name, int $expectedRevision): ExperimentRecord;

    public function disable(string $name, int $expectedRevision): ExperimentRecord;

    /** @param array<string, int<0, max>> $variants */
    public function reweight(string $name, array $variants, int $expectedRevision): ExperimentRecord;

    public function archive(string $name, int $expectedRevision): ExperimentRecord;
}

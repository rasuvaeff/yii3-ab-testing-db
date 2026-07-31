<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;

/** @var \Yiisoft\Db\Connection\ConnectionInterface $db */
$db = require __DIR__ . '/bootstrap.php';
$repository = new DbExperimentRepository(db: $db);

$record = $repository->upsert(
    experiment: new Experiment(
        name: 'checkout-button',
        enabled: false,
        salt: 'checkout-v1',
        fallbackVariant: 'control',
        variants: ['control' => 50, 'green' => 50],
    ),
    state: ExperimentState::Draft,
);
$record = $repository->enable('checkout-button', $record->revision);
$record = $repository->reweight(
    name: 'checkout-button',
    variants: ['control' => 10, 'green' => 90],
    expectedRevision: $record->revision,
);

echo sprintf("%s state=%s revision=%d\n", $record->experiment->name, $record->state->value, $record->revision);

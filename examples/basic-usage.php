<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var ConnectionInterface $db */
$db = require __DIR__ . '/bootstrap.php';

$ab = new AbTesting(
    provider: new DbExperimentProvider(db: $db),
    strategy: new WeightedHashAssignmentStrategy(),
);

foreach (['user-1', 'user-2', 'user-3'] as $userId) {
    $assignment = $ab->assign(experiment: 'checkout-button', subjectId: $userId);
    echo sprintf("checkout-button / %s -> %s\n", $userId, $assignment->variant);
}

// Disabled experiment returns its fallback variant.
$assignment = $ab->assign(experiment: 'pricing-page', subjectId: 'user-1');
echo sprintf("pricing-page (disabled) / user-1 -> %s (fallback: %s)\n", $assignment->variant, $assignment->isFallback ? 'yes' : 'no');

$db->close();

<?php

declare(strict_types=1);

use Rasuvaeff\Yii3AbTestingDb\Console\CreateExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\DisableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\EnableExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ListExperimentsCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ReweightExperimentCommand;
use Rasuvaeff\Yii3AbTestingDb\Console\ValidateExperimentsCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'ab-testing:validate' => ValidateExperimentsCommand::class,
            'ab-testing:list' => ListExperimentsCommand::class,
            'ab-testing:create' => CreateExperimentCommand::class,
            'ab-testing:enable' => EnableExperimentCommand::class,
            'ab-testing:disable' => DisableExperimentCommand::class,
            'ab-testing:reweight' => ReweightExperimentCommand::class,
        ],
    ],
    'rasuvaeff/yii3-ab-testing-db' => [
        // one source of truth: both migrations and DbExperimentProvider read
        // the resulting name through AbExperimentsTableName
        'table' => 'ab_experiments',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        'assignments_table' => 'ab_assignments',
        'cache' => [
            'enabled' => true,
            'ttl' => 60,
            // null derives the namespace from the DB table; set a stable value
            // to isolate tenants/connections that intentionally share a table name
            'namespace' => null,
        ],
    ],
];

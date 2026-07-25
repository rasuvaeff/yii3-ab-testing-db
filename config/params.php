<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-ab-testing-db' => [
        // one source of truth: both migrations and DbExperimentProvider read
        // the resulting name through AbExperimentsTableName
        'table' => 'ab_experiments',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        'cache' => [
            'enabled' => false,
            'ttl' => 60,
        ],
    ],
];

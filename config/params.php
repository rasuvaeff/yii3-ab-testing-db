<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-ab-testing-db' => [
        'table' => 'ab_experiments',
        'cache' => [
            'enabled' => false,
            'ttl' => 60,
        ],
    ],
];

<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the experiments table read by {@see \Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider}.
 *
 * The table name defaults to `ab_experiments` and must match the `table` argument
 * of {@see \Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider}. To use a custom name,
 * bind the constructor argument in your DI configuration:
 *
 * ```php
 * M260610000000CreateAbExperimentsTable::class => [
 *     '__construct()' => ['table' => 'my_ab_experiments'],
 * ],
 * ```
 *
 * `variants` holds a JSON object mapping each variant name to its non-negative
 * integer weight, e.g. `{"control":50,"green":50}`. `fallback_variant` must be
 * one of those variant names.
 */
final class M260610000000CreateAbExperimentsTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private readonly string $table = 'ab_experiments',
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table,
            [
                'name' => 'string(190) NOT NULL PRIMARY KEY',
                'enabled' => 'boolean NOT NULL DEFAULT TRUE',
                'salt' => "string(190) NOT NULL DEFAULT ''",
                'fallback_variant' => "string(190) NOT NULL DEFAULT ''",
                'variants' => "text NOT NULL DEFAULT '{}'",
            ],
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table);
    }
}

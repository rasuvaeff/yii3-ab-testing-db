<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Migration;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the experiments table read by {@see \Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider}.
 *
 * The table name defaults to `ab_experiments` and must match the `table` argument
 * The table name comes from {@see AbExperimentsTableName}, which
 * `config/di.php` builds from params — one source of truth for both migrations
 * and the provider alike. Register them by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\Yii3AbTestingDb\Migration']],
 * ],
 * ```
 *
 * `variants` holds a JSON object mapping each variant name to its non-negative
 * integer weight, e.g. `{"control":50,"green":50}`. `fallback_variant` must be
 * one of those variant names.
 *
 * @api
 */
final readonly class M260610000000CreateAbExperimentsTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private AbExperimentsTableName $table = new AbExperimentsTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table->value,
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
        $b->dropTable($this->table->value);
    }
}

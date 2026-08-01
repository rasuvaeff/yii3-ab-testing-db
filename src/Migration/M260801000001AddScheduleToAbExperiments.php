<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Migration;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds the planned run window to the operational model.
 *
 * Both columns are nullable, and an existing experiment gets NULL for both —
 * "already begun, runs until stopped", which is exactly what it was doing
 * before the columns existed.
 *
 * @api
 */
final readonly class M260801000001AddScheduleToAbExperiments implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private AbExperimentsTableName $table = new AbExperimentsTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(table: $this->table->value, column: 'starts_at', type: 'string(32) NULL');
        $b->addColumn(table: $this->table->value, column: 'ends_at', type: 'string(32) NULL');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(table: $this->table->value, column: 'ends_at');
        $b->dropColumn(table: $this->table->value, column: 'starts_at');
    }
}

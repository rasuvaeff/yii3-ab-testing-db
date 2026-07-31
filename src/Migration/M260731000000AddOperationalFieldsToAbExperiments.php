<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Migration;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds the R1 operational control-plane fields without changing assignment data.
 *
 * @api
 */
final readonly class M260731000000AddOperationalFieldsToAbExperiments implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private AbExperimentsTableName $table = new AbExperimentsTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(table: $this->table->value, column: 'state', type: "string(20) NOT NULL DEFAULT 'running'");
        $b->addColumn(table: $this->table->value, column: 'revision', type: 'integer NOT NULL DEFAULT 1');
        $b->addColumn(table: $this->table->value, column: 'created_at', type: "string(32) NOT NULL DEFAULT '1970-01-01 00:00:00.000000'");
        $b->addColumn(table: $this->table->value, column: 'updated_at', type: "string(32) NOT NULL DEFAULT '1970-01-01 00:00:00.000000'");
        $b->update(table: $this->table->value, columns: ['state' => ExperimentState::Paused->value], condition: ['enabled' => false]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $b->update(table: $this->table->value, columns: ['created_at' => $now, 'updated_at' => $now]);
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(table: $this->table->value, column: 'updated_at');
        $b->dropColumn(table: $this->table->value, column: 'created_at');
        $b->dropColumn(table: $this->table->value, column: 'revision');
        $b->dropColumn(table: $this->table->value, column: 'state');
    }
}

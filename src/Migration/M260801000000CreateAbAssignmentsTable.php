<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Migration;

use Rasuvaeff\Yii3AbTestingDb\AbAssignmentsTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the server-side sticky assignment table.
 *
 * The primary key is (experiment, subject_id): one variant per subject per
 * experiment, so the table grows with participants rather than with requests,
 * and the upsert in {@see \Rasuvaeff\Yii3AbTestingDb\DbAssignmentStore} has a
 * unique target to conflict on.
 *
 * @api
 */
final readonly class M260801000000CreateAbAssignmentsTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private AbAssignmentsTableName $table = new AbAssignmentsTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable($this->table->value, [
            'experiment' => 'string(64) NOT NULL',
            'subject_id' => 'string(191) NOT NULL',
            'variant' => 'string(64) NOT NULL',
            // '' means "configuration unknown"; NULL would make the equality
            // check in the store driver-dependent.
            'configuration_id' => "string(191) NOT NULL DEFAULT ''",
            // No default on purpose. The epoch default in the experiments
            // table exists only because those columns were added to a populated
            // table and NOT NULL demanded one; here the table is new, so an
            // INSERT that forgets a timestamp should fail rather than silently
            // record 1970. Stored as a string for the same reason as elsewhere
            // in this package: 'Y-m-d H:i:s.u' sorts chronologically on all
            // three drivers without their differing datetime semantics.
            'created_at' => 'string(32) NOT NULL',
            'updated_at' => 'string(32) NOT NULL',
        ]);

        // A unique index rather than a composite PRIMARY KEY: SQLite cannot add
        // one after CREATE TABLE, and the upsert only needs *some* unique
        // constraint to conflict on — which a unique index is on all three
        // supported drivers.
        $b->createIndex(
            table: $this->table->value,
            name: $this->table->forIndexName() . '_subject_uq',
            columns: ['experiment', 'subject_id'],
            indexType: 'UNIQUE',
        );

        // Erasure of a subject spans experiments, so it needs its own index.
        $b->createIndex(
            table: $this->table->value,
            name: $this->table->forIndexName() . '_subject_idx',
            columns: 'subject_id',
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table->value);
    }
}

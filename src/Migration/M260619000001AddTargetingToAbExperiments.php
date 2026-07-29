<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Migration;

use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds optional `targeting` column to the experiments table.
 *
 * The column stores a JSON-encoded targeting rule tree or NULL (no targeting).
 * Supported rule types: `environment`, `attribute`, `and`, `or`.
 *
 * Example values:
 * ```json
 * {"type":"environment","values":["production","staging"]}
 * {"type":"attribute","attribute":"plan","value":"pro"}
 * {"type":"and","rules":[...]}
 * {"type":"or","rules":[...]}
 * ```
 *
 * NULL means all subjects are eligible (legacy behaviour).
 *
 * Takes the SAME {@see AbExperimentsTableName} as the create migration: in 1.x
 * both hard-coded their own default, so a configured table got CREATEd under
 * the custom name while this ALTER went to `ab_experiments` — or failed.
 *
 * @api
 */
final readonly class M260619000001AddTargetingToAbExperiments implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private AbExperimentsTableName $table = new AbExperimentsTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->addColumn(table: $this->table->value, column: 'targeting', type: 'text NULL DEFAULT NULL');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(table: $this->table->value, column: 'targeting');
    }
}

<?php

declare(strict_types=1);

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
 */
final class M260619000001AddTargetingToAbExperiments implements RevertibleMigrationInterface, TransactionalMigrationInterface
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
        $b->addColumn(table: $this->table, column: 'targeting', type: 'text NULL DEFAULT NULL');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn(table: $this->table, column: 'targeting');
    }
}

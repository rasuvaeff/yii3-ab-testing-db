<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use InvalidArgumentException;

/**
 * The sticky-assignment table's name, as a type.
 *
 * Separate from {@see AbExperimentsTableName} because the two tables are
 * configured independently and hold different things: definitions versus
 * per-subject assignments. Sharing one value object would let a rename of one
 * silently retarget the other.
 *
 * The type exists for the same reason as the experiments one: migrations are
 * built through `Injector::make()`, which cannot resolve a scalar constructor
 * argument, so a `string $table` is unconfigurable in practice.
 *
 * @api
 */
final readonly class AbAssignmentsTableName implements \Stringable
{
    private const string PATTERN = '/^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?\z/';

    public function __construct(
        public string $value = 'ab_assignments',
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid table name "%s"', $value));
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Base for index names: a schema-qualified table cannot appear verbatim in
     * an index name, so `schema.table` becomes `schema_table`.
     */
    public function forIndexName(): string
    {
        return str_replace('.', '_', $this->value);
    }
}

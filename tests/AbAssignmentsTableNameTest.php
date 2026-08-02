<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTestingDb\AbAssignmentsTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AbAssignmentsTableName::class)]
final class AbAssignmentsTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new AbAssignmentsTableName())->value, 'ab_assignments');
        Assert::same((string) new AbAssignmentsTableName(), 'ab_assignments');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new AbAssignmentsTableName('public.ab_assignments'))->value, 'public.ab_assignments');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new AbAssignmentsTableName('public.ab_assignments'))->forIndexName(), 'public_ab_assignments');
        Assert::same((new AbAssignmentsTableName('ab_assignments'))->forIndexName(), 'ab_assignments');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new AbAssignmentsTableName($name);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'space' => ['my table'];
        yield 'semicolon injection' => ['t; DROP TABLE users'];
        yield 'dash' => ['my-table'];
        yield 'two dots' => ['a.b.c'];
        // PCRE's $ also matches before a trailing newline — the pattern is
        // anchored with \z so this is rejected
        yield 'trailing newline' => ["ab_assignments\n"];
    }
}

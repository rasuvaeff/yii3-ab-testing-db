<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3AbTestingDb\AbExperimentsTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(AbExperimentsTableName::class)]
final class AbExperimentsTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new AbExperimentsTableName())->value, 'ab_experiments');
        Assert::same((string) new AbExperimentsTableName(), 'ab_experiments');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new AbExperimentsTableName('public.ab_experiments'))->value, 'public.ab_experiments');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new AbExperimentsTableName('public.ab_experiments'))->forIndexName(), 'public_ab_experiments');
        Assert::same((new AbExperimentsTableName('ab_experiments'))->forIndexName(), 'ab_experiments');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new AbExperimentsTableName($name);
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
        yield 'trailing newline' => ["ab_experiments\n"];
    }
}

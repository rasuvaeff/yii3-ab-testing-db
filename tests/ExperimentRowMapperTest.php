<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\EnvironmentTargetingRule;
use Rasuvaeff\Yii3AbTesting\OrTargetingRule;
use Rasuvaeff\Yii3AbTestingDb\Exception\InvalidExperimentRowException;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRowMapper;

#[CoversClass(ExperimentRowMapper::class)]
final class ExperimentRowMapperTest extends TestCase
{
    private ExperimentRowMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new ExperimentRowMapper();
    }

    #[Test]
    public function mapsRowWithNativeTypes(): void
    {
        $experiment = $this->mapper->map([
            'name' => 'checkout-button',
            'enabled' => true,
            'salt' => 'checkout-v1',
            'fallback_variant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ]);

        $this->assertSame('checkout-button', $experiment->name);
        $this->assertTrue($experiment->enabled);
        $this->assertSame('checkout-v1', $experiment->salt);
        $this->assertSame('control', $experiment->fallbackVariant);
        $this->assertSame(['control' => 50, 'green' => 50], $experiment->variants);
    }

    #[Test]
    public function mapsRowWithStringScalars(): void
    {
        $experiment = $this->mapper->map([
            'name' => 'exp-a',
            'enabled' => '1',
            'salt' => 'exp-a-salt',
            'fallback_variant' => 'control',
            'variants' => '{"control":75,"green":25}',
        ]);

        $this->assertTrue($experiment->enabled);
        $this->assertSame(['control' => 75, 'green' => 25], $experiment->variants);
    }

    /**
     * @return iterable<string, array{0: bool|int|string, 1: bool}>
     */
    public static function boolCastProvider(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string empty' => ['', false];
        yield 'string other' => ['yes', true];
    }

    #[DataProvider('boolCastProvider')]
    #[Test]
    public function castsEnabledColumn(bool|int|string $raw, bool $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($this->row(enabled: $raw))->enabled);
    }

    #[Test]
    public function emptySaltFallsBackToName(): void
    {
        $experiment = $this->mapper->map($this->row(name: 'my-exp', salt: ''));

        $this->assertSame('my-exp', $experiment->salt);
    }

    #[Test]
    public function readsVariantsFromNativeArray(): void
    {
        $experiment = $this->mapper->map($this->row(variants: ['control' => 10, 'green' => 90]));

        $this->assertSame(['control' => 10, 'green' => 90], $experiment->variants);
    }

    #[Test]
    public function readsVariantsFromJsonObject(): void
    {
        $experiment = $this->mapper->map($this->row(variants: '{"control":10,"green":90}'));

        $this->assertSame(['control' => 10, 'green' => 90], $experiment->variants);
    }

    #[Test]
    public function acceptsZeroWeightVariant(): void
    {
        $experiment = $this->mapper->map($this->row(variants: ['control' => 100, 'green' => 0]));

        $this->assertSame(['control' => 100, 'green' => 0], $experiment->variants);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRowProvider(): iterable
    {
        $base = [
            'name' => 'exp',
            'enabled' => true,
            'salt' => 'exp-salt',
            'fallback_variant' => 'control',
            'variants' => '{"control":50,"green":50}',
        ];

        yield 'missing name' => [self::without($base, 'name'), 'name'];
        yield 'non-string name' => [['name' => 5] + $base, 'name'];
        yield 'missing salt' => [self::without($base, 'salt'), 'salt'];
        yield 'non-string salt' => [['salt' => 5] + $base, 'salt'];
        yield 'missing enabled' => [self::without($base, 'enabled'), 'enabled'];
        yield 'null enabled' => [['enabled' => null] + $base, 'enabled'];
        yield 'missing fallback_variant' => [self::without($base, 'fallback_variant'), 'fallback_variant'];
        yield 'non-string fallback_variant' => [['fallback_variant' => 5] + $base, 'fallback_variant'];
        yield 'missing variants' => [self::without($base, 'variants'), 'variants'];
        yield 'malformed variants json' => [['variants' => 'not-json'] + $base, 'Invalid "variants" JSON'];
        yield 'variants json not object' => [['variants' => '5'] + $base, 'expected object'];
        yield 'variants native non-array' => [['variants' => 5] + $base, 'expected object'];
        yield 'variant name not string' => [['variants' => [0 => 50]] + $base, 'expected string'];
        yield 'variant weight not int' => [['variants' => ['control' => 'x']] + $base, 'non-negative integer'];
        yield 'variant weight float' => [['variants' => ['control' => 1.5]] + $base, 'non-negative integer'];
        yield 'variant weight negative' => [['variants' => ['control' => -1]] + $base, 'non-negative integer'];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('invalidRowProvider')]
    #[Test]
    public function throwsOnInvalidRow(array $row, string $needle): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        $this->mapper->map($row);
    }

    #[Test]
    public function wrapsCoreExceptionForInvalidName(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "Bad-Name" in DB row');

        $this->mapper->map($this->row(name: 'Bad-Name'));
    }

    #[Test]
    public function wrapsCoreExceptionForInvalidVariantName(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "exp" in DB row');

        // An invalid variant *name* makes the core Experiment throw
        // InvalidVariantException (not InvalidExperimentException) — both must be wrapped.
        $this->mapper->map($this->row(fallbackVariant: 'Bad-Variant', variants: ['Bad-Variant' => 50]));
    }

    #[Test]
    public function wrapsCoreExceptionForUnknownFallback(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "exp" in DB row');

        $this->mapper->map($this->row(fallbackVariant: 'missing'));
    }

    #[Test]
    public function wrapsCoreExceptionForEmptyVariants(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "exp" in DB row');

        $this->mapper->map($this->row(variants: '{}'));
    }

    #[Test]
    public function wrapsCoreExceptionForZeroTotalWeight(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "exp" in DB row');

        $this->mapper->map($this->row(fallbackVariant: 'control', variants: ['control' => 0]));
    }

    #[Test]
    public function targetingNullWhenColumnAbsent(): void
    {
        $experiment = $this->mapper->map($this->row());

        $this->assertNull($experiment->targeting);
    }

    #[Test]
    public function targetingNullWhenColumnIsNull(): void
    {
        $experiment = $this->mapper->map($this->row() + ['targeting' => null]);

        $this->assertNull($experiment->targeting);
    }

    #[Test]
    public function targetingNullWhenColumnIsEmptyString(): void
    {
        $experiment = $this->mapper->map($this->row() + ['targeting' => '']);

        $this->assertNull($experiment->targeting);
    }

    #[Test]
    public function decodesEnvironmentTargetingRule(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"environment","values":["production","staging"]}'],
        );

        $this->assertInstanceOf(EnvironmentTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function decodesAttributeTargetingRule(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"attribute","attribute":"plan","value":"pro"}'],
        );

        $this->assertInstanceOf(AttributeTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function decodesAndTargetingRuleWithNestedRules(): void
    {
        $json = json_encode([
            'type' => 'and',
            'rules' => [
                ['type' => 'environment', 'values' => ['production']],
                ['type' => 'attribute', 'attribute' => 'plan', 'value' => 'pro'],
            ],
        ]);
        $experiment = $this->mapper->map($this->row() + ['targeting' => $json]);

        $this->assertInstanceOf(AndTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function decodesOrTargetingRule(): void
    {
        $json = json_encode([
            'type' => 'or',
            'rules' => [
                ['type' => 'environment', 'values' => ['production']],
                ['type' => 'attribute', 'attribute' => 'beta', 'value' => true],
            ],
        ]);
        $experiment = $this->mapper->map($this->row() + ['targeting' => $json]);

        $this->assertInstanceOf(OrTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function throwsOnInvalidTargetingJson(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessageMatches('/Invalid "targeting" JSON/');

        $this->mapper->map($this->row() + ['targeting' => 'not-json']);
    }

    #[Test]
    public function throwsOnUnknownTargetingType(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessageMatches('/Unknown targeting rule type/');

        $this->mapper->map($this->row() + ['targeting' => '{"type":"unknown"}']);
    }

    #[Test]
    public function throwsOnInvalidTargetingColumnType(): void
    {
        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessageMatches('/expected JSON string or null/');

        $this->mapper->map($this->row() + ['targeting' => 42]);
    }

    /**
     * @param array<string, int>|string $variants
     *
     * @return array<string, mixed>
     */
    private function row(
        string $name = 'exp',
        bool|int|string $enabled = true,
        string $salt = 'exp-salt',
        string $fallbackVariant = 'control',
        array|string $variants = '{"control":50,"green":50}',
    ): array {
        return [
            'name' => $name,
            'enabled' => $enabled,
            'salt' => $salt,
            'fallback_variant' => $fallbackVariant,
            'variants' => $variants,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function without(array $row, string $key): array
    {
        unset($row[$key]);

        return $row;
    }
}

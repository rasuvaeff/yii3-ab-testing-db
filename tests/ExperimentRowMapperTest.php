<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests;

use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\AssignmentContext;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\EnvironmentTargetingRule;
use Rasuvaeff\Yii3AbTesting\OrTargetingRule;
use Rasuvaeff\Yii3AbTestingDb\Exception\InvalidExperimentRowException;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRowMapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ExperimentRowMapper::class)]
final class ExperimentRowMapperTest
{
    private ExperimentRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new ExperimentRowMapper();
    }

    public function mapsRowWithNativeTypes(): void
    {
        $experiment = $this->mapper->map([
            'name' => 'checkout-button',
            'enabled' => true,
            'salt' => 'checkout-v1',
            'fallback_variant' => 'control',
            'variants' => ['control' => 50, 'green' => 50],
        ]);

        Assert::same($experiment->name, 'checkout-button');
        Assert::true($experiment->enabled);
        Assert::same($experiment->salt, 'checkout-v1');
        Assert::same($experiment->fallbackVariant, 'control');
        Assert::same($experiment->variants, ['control' => 50, 'green' => 50]);
    }

    public function mapsRowWithStringScalars(): void
    {
        $experiment = $this->mapper->map([
            'name' => 'exp-a',
            'enabled' => '1',
            'salt' => 'exp-a-salt',
            'fallback_variant' => 'control',
            'variants' => '{"control":75,"green":25}',
        ]);

        Assert::true($experiment->enabled);
        Assert::same($experiment->variants, ['control' => 75, 'green' => 25]);
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
    public function castsEnabledColumn(bool|int|string $raw, bool $expected): void
    {
        Assert::same($this->mapper->map($this->row(enabled: $raw))->enabled, $expected);
    }

    public function emptySaltFallsBackToName(): void
    {
        $experiment = $this->mapper->map($this->row(name: 'my-exp', salt: ''));

        Assert::same($experiment->salt, 'my-exp');
    }

    public function readsVariantsFromNativeArray(): void
    {
        $experiment = $this->mapper->map($this->row(variants: ['control' => 10, 'green' => 90]));

        Assert::same($experiment->variants, ['control' => 10, 'green' => 90]);
    }

    public function readsVariantsFromJsonObject(): void
    {
        $experiment = $this->mapper->map($this->row(variants: '{"control":10,"green":90}'));

        Assert::same($experiment->variants, ['control' => 10, 'green' => 90]);
    }

    public function acceptsZeroWeightVariant(): void
    {
        $experiment = $this->mapper->map($this->row(variants: ['control' => 100, 'green' => 0]));

        Assert::same($experiment->variants, ['control' => 100, 'green' => 0]);
    }

    public function throwsOnMissingEnabledKey(): void
    {
        try {
            $this->mapper->map(self::without($this->row(), 'enabled'));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::string($e->getMessage())->contains('Missing column "enabled"');
        }

        Assert::true(true);
    }

    public function throwsOnInvalidEnabledType(): void
    {
        try {
            $row = $this->row();
            $row['enabled'] = [];
            $this->mapper->map($row);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::string($e->getMessage())->contains('Missing or invalid column "enabled"');
        }

        Assert::true(true);
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
        yield 'non-bool enabled' => [['enabled' => []] + $base, 'enabled'];
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
    public function throwsOnInvalidRow(array $row, string $needle): void
    {
        try {
            $this->mapper->map($row);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), $needle));
        }

        Assert::true(true);
    }

    public function wrapsCoreExceptionForInvalidName(): void
    {
        try {
            $this->mapper->map($this->row(name: 'Bad-Name'));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "Bad-Name" in DB row'));
        }
    }

    public function wrapsCoreExceptionForInvalidVariantName(): void
    {
        try {
            $this->mapper->map($this->row(fallbackVariant: 'Bad-Variant', variants: ['Bad-Variant' => 50]));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "exp" in DB row'));
        }
    }

    public function wrapsCoreExceptionForUnknownFallback(): void
    {
        try {
            $this->mapper->map($this->row(fallbackVariant: 'missing'));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "exp" in DB row'));
        }
    }

    public function wrapsCoreExceptionForEmptyVariants(): void
    {
        try {
            $this->mapper->map($this->row(variants: '{}'));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "exp" in DB row'));
        }
    }

    public function wrapsCoreExceptionForZeroTotalWeight(): void
    {
        try {
            $this->mapper->map($this->row(fallbackVariant: 'control', variants: ['control' => 0]));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "exp" in DB row'));
        }
    }

    public function targetingNullWhenColumnAbsent(): void
    {
        $experiment = $this->mapper->map($this->row());

        Assert::null($experiment->targeting);
    }

    public function targetingNullWhenColumnIsNull(): void
    {
        $experiment = $this->mapper->map($this->row() + ['targeting' => null]);

        Assert::null($experiment->targeting);
    }

    public function targetingNullWhenColumnIsEmptyString(): void
    {
        $experiment = $this->mapper->map($this->row() + ['targeting' => '']);

        Assert::null($experiment->targeting);
    }

    public function decodesEnvironmentTargetingRule(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"environment","values":["production","staging"]}'],
        );

        Assert::instanceOf($experiment->targeting, EnvironmentTargetingRule::class);
    }

    public function decodesAttributeTargetingRule(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"attribute","attribute":"plan","value":"pro"}'],
        );

        Assert::instanceOf($experiment->targeting, AttributeTargetingRule::class);
    }

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

        Assert::instanceOf($experiment->targeting, AndTargetingRule::class);
    }

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

        Assert::instanceOf($experiment->targeting, OrTargetingRule::class);
    }

    public function throwsOnInvalidTargetingJson(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => 'not-json']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid "targeting" JSON'));
        }
    }

    public function throwsOnUnknownTargetingType(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"unknown"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Unknown targeting rule type'));
        }
    }

    public function throwsOnInvalidTargetingColumnType(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => 42]);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'expected JSON string or null'));
        }
    }

    public function throwsOnNonStringTypeInTargeting(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":42}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(preg_match('/"\(null\)"/', $e->getMessage()) === 1);
        }
    }

    public function throwsOnUnknownTargetingTypeIncludesTypeName(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"unknown"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), '"unknown"'));
        }
    }

    public function throwsOnEnvironmentRuleWithNonArrayValues(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"environment","values":"production"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), '"values" must be an array'));
        }
    }

    public function decodesAttributeTargetingRuleWithIntValue(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"attribute","attribute":"count","value":42}'],
        );

        Assert::instanceOf($experiment->targeting, AttributeTargetingRule::class);
    }

    public function decodesAttributeTargetingRuleWithFloatValue(): void
    {
        $experiment = $this->mapper->map(
            $this->row() + ['targeting' => '{"type":"attribute","attribute":"score","value":3.14}'],
        );

        Assert::instanceOf($experiment->targeting, AttributeTargetingRule::class);
    }

    public function throwsOnAttributeRuleWithInvalidValueType(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"attribute","attribute":"x","value":{"nested":"obj"}}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid targeting attribute value type'));
        }
    }

    public function throwsOnAttributeRuleWithNonStringAttribute(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"attribute","attribute":99,"value":"x"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), '"attribute" must be a string'));
        }
    }

    public function throwsOnAndRuleWithNonArrayRules(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"and","rules":"invalid"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), '"rules" must be an array'));
        }
    }

    public function throwsOnOrRuleWithNonArrayRules(): void
    {
        try {
            $this->mapper->map($this->row() + ['targeting' => '{"type":"or","rules":"invalid"}']);
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), '"rules" must be an array'));
        }
    }

    public function builtCompositeRuleMatchesContext(): void
    {
        $json = json_encode([
            'type' => 'and',
            'rules' => [
                ['type' => 'environment', 'values' => ['production']],
                ['type' => 'attribute', 'attribute' => 'plan', 'value' => 'pro'],
            ],
        ]);
        $experiment = $this->mapper->map($this->row() + ['targeting' => $json]);

        $matching = AssignmentContext::forEnvironment('production')
            ->withAttribute(name: 'plan', value: 'pro');
        $mismatch = AssignmentContext::forEnvironment('staging');

        Assert::notNull($experiment->targeting);
        Assert::true($experiment->targeting->matches($matching));
        Assert::false($experiment->targeting->matches($mismatch));
    }

    public function builtOrRuleMatchesContext(): void
    {
        $json = json_encode([
            'type' => 'or',
            'rules' => [
                ['type' => 'environment', 'values' => ['production']],
                ['type' => 'attribute', 'attribute' => 'beta', 'value' => true],
            ],
        ]);
        $experiment = $this->mapper->map($this->row() + ['targeting' => $json]);

        $matchEnv = AssignmentContext::forEnvironment('production');
        $matchAttr = AssignmentContext::empty()->withAttribute(name: 'beta', value: true);
        $noMatch = AssignmentContext::forEnvironment('staging');

        Assert::notNull($experiment->targeting);
        Assert::true($experiment->targeting->matches($matchEnv));
        Assert::true($experiment->targeting->matches($matchAttr));
        Assert::false($experiment->targeting->matches($noMatch));
    }

    public function throwsOnMissingVariantsKey(): void
    {
        try {
            $this->mapper->map(self::without($this->row(), 'variants'));
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::same($e->getMessage(), 'Missing column "variants" in experiment row');
        }

        Assert::true(true);
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

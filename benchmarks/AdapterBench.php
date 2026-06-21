<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Benchmarks;

use Rasuvaeff\Yii3AbTestingDb\ExperimentRowMapper;
use Testo\Bench;

final class AdapterBench
{
    private static function rowSimple(): array
    {
        return [
            'name' => 'checkout-button',
            'enabled' => 1,
            'salt' => 'checkout-button',
            'fallback_variant' => 'control',
            'variants' => '{"control":50,"treatment":50}',
            'targeting' => null,
        ];
    }

    private static function rowWithTargeting(): array
    {
        return [
            'name' => 'checkout-button',
            'enabled' => 1,
            'salt' => 'checkout-button',
            'fallback_variant' => 'control',
            'variants' => '{"control":50,"treatment":50}',
            'targeting' => '{"type":"and","rules":[{"type":"environment","values":["production"]},{"type":"attribute","attribute":"country","value":"US"}]}',
        ];
    }

    #[Bench(
        callables: [
            'with-targeting' => [self::class, 'mapWithTargeting'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function mapSimple(): mixed
    {
        return (new ExperimentRowMapper())->map(self::rowSimple());
    }

    public static function mapWithTargeting(): mixed
    {
        return (new ExperimentRowMapper())->map(self::rowWithTargeting());
    }
}

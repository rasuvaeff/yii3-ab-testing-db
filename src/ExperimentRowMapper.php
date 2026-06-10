<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\Exception\InvalidExperimentException;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidVariantException;
use Rasuvaeff\Yii3AbTesting\Experiment;

/**
 * Maps a raw database row into a validated {@see Experiment}.
 *
 * @internal
 */
final class ExperimentRowMapper
{
    /**
     * @param array<array-key, mixed> $row
     */
    public function map(array $row): Experiment
    {
        $name = $this->extractString(row: $row, column: 'name');
        $salt = $this->extractString(row: $row, column: 'salt');

        try {
            return new Experiment(
                name: $name,
                enabled: $this->extractBool(row: $row, column: 'enabled'),
                salt: $salt === '' ? $name : $salt,
                fallbackVariant: $this->extractString(row: $row, column: 'fallback_variant'),
                variants: $this->extractVariants(row: $row),
            );
        } catch (InvalidExperimentException|InvalidVariantException $e) {
            throw new Exception\InvalidExperimentRowException(
                message: sprintf('Invalid experiment "%s" in DB row: %s', $name, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractString(array $row, string $column): string
    {
        if (!isset($row[$column]) || !\is_string($row[$column])) {
            throw new Exception\InvalidExperimentRowException(
                message: sprintf('Missing or invalid column "%s" in experiment row', $column),
            );
        }

        return $row[$column];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractBool(array $row, string $column): bool
    {
        if (!\array_key_exists($column, $row)) {
            throw new Exception\InvalidExperimentRowException(
                message: sprintf('Missing or invalid column "%s" in experiment row', $column),
            );
        }

        if (\is_bool($row[$column])) {
            return $row[$column];
        }

        if (\is_int($row[$column])) {
            return $row[$column] !== 0;
        }

        if (\is_string($row[$column])) {
            return $row[$column] !== '' && $row[$column] !== '0';
        }

        throw new Exception\InvalidExperimentRowException(
            message: sprintf('Missing or invalid column "%s" in experiment row', $column),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, int<0, max>>
     */
    private function extractVariants(array $row): array
    {
        if (!\array_key_exists('variants', $row)) {
            throw new Exception\InvalidExperimentRowException(
                message: 'Missing column "variants" in experiment row',
            );
        }

        if (\is_string($row['variants'])) {
            try {
                return $this->validateVariants(
                    variants: json_decode(json: $row['variants'], associative: true, flags: JSON_THROW_ON_ERROR),
                    source: 'JSON',
                );
            } catch (\JsonException) {
                throw new Exception\InvalidExperimentRowException(
                    message: sprintf('Invalid "variants" JSON: %s', $row['variants']),
                );
            }
        }

        return $this->validateVariants(variants: $row['variants'], source: 'column');
    }

    /**
     * @return array<string, int<0, max>>
     */
    private function validateVariants(mixed $variants, string $source): array
    {
        if (!\is_array($variants)) {
            throw new Exception\InvalidExperimentRowException(
                message: sprintf('Invalid "variants" %s: expected object, got %s', $source, get_debug_type($variants)),
            );
        }

        $result = [];

        foreach ($variants as $name => $weight) {
            if (!\is_string($name)) {
                throw new Exception\InvalidExperimentRowException(
                    message: sprintf('Invalid variant name: expected string, got %s', get_debug_type($name)),
                );
            }

            if (!\is_int($weight) || $weight < 0) {
                throw new Exception\InvalidExperimentRowException(
                    message: sprintf('Invalid weight for variant "%s": expected non-negative integer, got %s', $name, get_debug_type($weight)),
                );
            }

            $result[$name] = $weight;
        }

        return $result;
    }
}

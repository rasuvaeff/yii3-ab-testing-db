<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\EnvironmentTargetingRule;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidExperimentException;
use Rasuvaeff\Yii3AbTesting\Exception\InvalidVariantException;
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTesting\OrTargetingRule;
use Rasuvaeff\Yii3AbTesting\TargetingRule;

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
                targeting: $this->extractTargeting(row: $row),
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
                message: sprintf('Missing column "%s" in experiment row', $column),
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

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractTargeting(array $row): ?TargetingRule
    {
        if (!array_key_exists('targeting', $row)
            || $row['targeting'] === null
            || $row['targeting'] === '') {
            return null;
        }

        if (!\is_string($row['targeting'])) {
            throw new Exception\InvalidExperimentRowException(
                message: 'Invalid "targeting" column: expected JSON string or null',
            );
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode(json: $row['targeting'], associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Exception\InvalidExperimentRowException(
                message: sprintf('Invalid "targeting" JSON: %s', $row['targeting']),
            );
        }

        return $this->buildRule(data: $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildRule(array $data): TargetingRule
    {
        $type = isset($data['type']) && \is_string($data['type']) ? $data['type'] : null;

        if ($type === 'environment') {
            if (!isset($data['values']) || !\is_array($data['values'])) {
                throw new Exception\InvalidExperimentRowException(
                    message: 'Invalid "environment" targeting rule: "values" must be an array',
                );
            }

            /** @var list<string> $values */
            $values = $data['values'];

            return new EnvironmentTargetingRule(environments: $values);
        }

        if ($type === 'attribute') {
            $value = $data['value'] ?? null;

            if (!\is_string($value) && !\is_int($value) && !\is_float($value) && !\is_bool($value)) {
                throw new Exception\InvalidExperimentRowException(
                    message: 'Invalid targeting attribute value type',
                );
            }

            if (!isset($data['attribute']) || !\is_string($data['attribute'])) {
                throw new Exception\InvalidExperimentRowException(
                    message: 'Invalid "attribute" targeting rule: "attribute" must be a string',
                );
            }

            return new AttributeTargetingRule(
                attribute: $data['attribute'],
                value: $value,
            );
        }

        if ($type === 'and') {
            if (!isset($data['rules']) || !\is_array($data['rules'])) {
                throw new Exception\InvalidExperimentRowException(
                    message: 'Invalid "and" targeting rule: "rules" must be an array',
                );
            }

            /** @var list<array<string, mixed>> $rules */
            $rules = $data['rules'];

            return new AndTargetingRule(rules: array_map($this->buildRule(...), $rules));
        }

        if ($type === 'or') {
            if (!isset($data['rules']) || !\is_array($data['rules'])) {
                throw new Exception\InvalidExperimentRowException(
                    message: 'Invalid "or" targeting rule: "rules" must be an array',
                );
            }

            /** @var list<array<string, mixed>> $rules */
            $rules = $data['rules'];

            return new OrTargetingRule(rules: array_map($this->buildRule(...), $rules));
        }

        throw new Exception\InvalidExperimentRowException(
            message: sprintf('Unknown targeting rule type: "%s"', $type ?? '(null)'),
        );
    }
}

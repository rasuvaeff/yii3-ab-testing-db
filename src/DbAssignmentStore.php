<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3AbTesting\ConfigurationAwareAssignmentStore;
use Rasuvaeff\Yii3AbTesting\SystemClock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Server-side sticky assignments: one row per (experiment, subject).
 *
 * The cookie store in `yii3-ab-testing-web` is browser-scoped — it loses the
 * assignment when the visitor clears cookies or switches device, and it ignores
 * the subject id entirely because the cookie *is* the subject. This one is keyed
 * by the subject, so an authenticated user keeps their variant everywhere.
 *
 * **`subject_id` is a persistent identifier and therefore personal data** when
 * it is a user id. Use {@see forget()} to satisfy an erasure request, and
 * {@see deleteExperiment()} to drop the rows of an experiment that has ended;
 * neither happens automatically, because deleting analytics-relevant history is
 * the operator's decision, not the library's.
 *
 * @api
 */
final readonly class DbAssignmentStore implements ConfigurationAwareAssignmentStore
{
    private string $table;

    public function __construct(
        private ConnectionInterface $db,
        string $table = 'ab_assignments',
        private ClockInterface $clock = new SystemClock(),
    ) {
        // validation lives in the value object, so the store and the bundled
        // migration cannot disagree about what a valid table name is
        $this->table = (new AbAssignmentsTableName($table))->value;
    }

    #[\Override]
    public function get(string $experiment, string $subjectId): ?string
    {
        return $this->read($experiment, $subjectId)['variant'] ?? null;
    }

    #[\Override]
    public function put(string $experiment, string $subjectId, string $variant): void
    {
        $this->write($experiment, $subjectId, $variant, null);
    }

    /**
     * Returns the stored variant only when it was drawn from the same
     * configuration. A reweight changes the identity, and the old variant then
     * belongs to bucket boundaries that no longer exist.
     */
    #[\Override]
    public function getForConfiguration(
        string $experiment,
        string $subjectId,
        ?string $configurationId,
    ): ?string {
        $row = $this->read($experiment, $subjectId);

        if ($row === null) {
            return null;
        }

        return $row['configuration_id'] === ($configurationId ?? '') ? $row['variant'] : null;
    }

    #[\Override]
    public function putForConfiguration(
        string $experiment,
        string $subjectId,
        string $variant,
        ?string $configurationId,
    ): void {
        $this->write($experiment, $subjectId, $variant, $configurationId);
    }

    /**
     * Erases every assignment of a subject, across all experiments.
     *
     * @return int Rows removed.
     */
    public function forget(string $subjectId): int
    {
        return $this->db->createCommand()
            ->delete($this->table, ['subject_id' => $subjectId])
            ->execute();
    }

    /**
     * Drops the assignments of one experiment, for cleanup after it ends.
     *
     * @return int Rows removed.
     */
    public function deleteExperiment(string $experiment): int
    {
        return $this->db->createCommand()
            ->delete($this->table, ['experiment' => $experiment])
            ->execute();
    }

    /**
     * @return array{variant: string, configuration_id: string}|null
     */
    private function read(string $experiment, string $subjectId): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->select(['variant', 'configuration_id'])
            ->from($this->table)
            ->where(['experiment' => $experiment, 'subject_id' => $subjectId])
            ->one();

        if ($row === null) {
            return null;
        }

        $variant = $row['variant'] ?? null;
        $configurationId = $row['configuration_id'] ?? null;

        if (!\is_string($variant) || !\is_string($configurationId)) {
            return null;
        }

        return ['variant' => $variant, 'configuration_id' => $configurationId];
    }

    /**
     * Upsert rather than insert-and-swallow: two concurrent requests for the
     * same subject must not leave the row in a state neither of them intended.
     * They compute the same variant anyway — assignment is deterministic — so
     * last-writer-wins is only reachable across a configuration change.
     *
     * `configuration_id` is stored as `''` rather than NULL so the equality
     * check in {@see getForConfiguration()} stays a plain comparison on every
     * supported driver.
     */
    private function write(string $experiment, string $subjectId, string $variant, ?string $configurationId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        $this->db->createCommand()
            ->upsert(
                $this->table,
                [
                    'experiment' => $experiment,
                    'subject_id' => $subjectId,
                    'variant' => $variant,
                    'configuration_id' => $configurationId ?? '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'variant' => $variant,
                    'configuration_id' => $configurationId ?? '',
                    'updated_at' => $now,
                ],
            )
            ->execute();
    }
}

<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\EnvironmentTargetingRule;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\Exception\InvalidExperimentRowException;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversClass(DbExperimentProvider::class)]
final class SqliteIntegrationTest extends TestCase
{
    private ConnectionInterface $db;

    #[\Override]
    protected function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->db->createCommand(sql: '
            CREATE TABLE ab_experiments (
                name             VARCHAR(190) PRIMARY KEY,
                enabled          INTEGER      NOT NULL DEFAULT 1,
                salt             VARCHAR(190) NOT NULL DEFAULT \'\',
                fallback_variant VARCHAR(190) NOT NULL DEFAULT \'\',
                variants         TEXT         NOT NULL DEFAULT \'{}\',
                targeting        TEXT         NULL
            )
        ')->execute();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function returnsEmptyArrayFromEmptyTable(): void
    {
        $provider = new DbExperimentProvider(db: $this->db);

        $this->assertSame([], $provider->getExperiments());
    }

    #[Test]
    public function readsSingleExperiment(): void
    {
        $this->insertRow(
            name: 'checkout-button',
            enabled: true,
            salt: 'checkout-v1',
            fallbackVariant: 'control',
            variants: '{"control":50,"green":50}',
        );

        $provider = new DbExperimentProvider(db: $this->db);
        $experiments = $provider->getExperiments();

        $this->assertCount(1, $experiments);
        $this->assertArrayHasKey('checkout-button', $experiments);

        $experiment = $experiments['checkout-button'];
        $this->assertSame('checkout-button', $experiment->name);
        $this->assertTrue($experiment->enabled);
        $this->assertSame('checkout-v1', $experiment->salt);
        $this->assertSame('control', $experiment->fallbackVariant);
        $this->assertSame(['control' => 50, 'green' => 50], $experiment->variants);
    }

    #[Test]
    public function readsMultipleExperiments(): void
    {
        $this->insertRow(name: 'exp-a', enabled: true, salt: 'a-salt', fallbackVariant: 'control', variants: '{"control":100}');
        $this->insertRow(name: 'exp-b', enabled: false, salt: 'b-salt', fallbackVariant: 'off', variants: '{"off":1,"on":1}');

        $provider = new DbExperimentProvider(db: $this->db);
        $experiments = $provider->getExperiments();

        $this->assertCount(2, $experiments);
        $this->assertTrue($experiments['exp-a']->enabled);
        $this->assertFalse($experiments['exp-b']->enabled);
        $this->assertSame(['off' => 1, 'on' => 1], $experiments['exp-b']->variants);
    }

    #[Test]
    public function emptySaltFallsBackToName(): void
    {
        $this->insertRow(name: 'my-exp', enabled: true, salt: '', fallbackVariant: 'control', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        $this->assertSame('my-exp', $provider->getExperiments()['my-exp']->salt);
    }

    #[Test]
    public function readsDisabledExperiment(): void
    {
        $this->insertRow(name: 'off-exp', enabled: false, salt: 's', fallbackVariant: 'control', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        $this->assertFalse($provider->getExperiments()['off-exp']->enabled);
    }

    #[Test]
    public function readsExperimentWithEnvironmentTargeting(): void
    {
        $this->insertRow(
            name: 'targeted-exp',
            enabled: true,
            salt: 's',
            fallbackVariant: 'control',
            variants: '{"control":50,"green":50}',
            targeting: '{"type":"environment","values":["production"]}',
        );

        $provider = new DbExperimentProvider(db: $this->db);
        $experiment = $provider->getExperiments()['targeted-exp'];

        $this->assertInstanceOf(EnvironmentTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function readsExperimentWithAttributeTargeting(): void
    {
        $this->insertRow(
            name: 'attr-exp',
            enabled: true,
            salt: 's',
            fallbackVariant: 'control',
            variants: '{"control":100}',
            targeting: '{"type":"attribute","attribute":"plan","value":"pro"}',
        );

        $provider = new DbExperimentProvider(db: $this->db);
        $experiment = $provider->getExperiments()['attr-exp'];

        $this->assertInstanceOf(AttributeTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function readsExperimentWithCompositeTargeting(): void
    {
        $json = json_encode([
            'type' => 'and',
            'rules' => [
                ['type' => 'environment', 'values' => ['production']],
                ['type' => 'attribute', 'attribute' => 'plan', 'value' => 'pro'],
            ],
        ]);
        $this->insertRow(
            name: 'composite-exp',
            enabled: true,
            salt: 's',
            fallbackVariant: 'control',
            variants: '{"control":100}',
            targeting: (string) $json,
        );

        $provider = new DbExperimentProvider(db: $this->db);
        $experiment = $provider->getExperiments()['composite-exp'];

        $this->assertInstanceOf(AndTargetingRule::class, $experiment->targeting);
    }

    #[Test]
    public function readsExperimentWithNullTargeting(): void
    {
        $this->insertRow(
            name: 'no-targeting',
            enabled: true,
            salt: 's',
            fallbackVariant: 'control',
            variants: '{"control":100}',
        );

        $provider = new DbExperimentProvider(db: $this->db);
        $experiment = $provider->getExperiments()['no-targeting'];

        $this->assertNull($experiment->targeting);
    }

    #[Test]
    public function usesCustomTableName(): void
    {
        $this->db->createCommand(sql: '
            CREATE TABLE custom_experiments (
                name             VARCHAR(190) PRIMARY KEY,
                enabled          INTEGER      NOT NULL DEFAULT 1,
                salt             VARCHAR(190) NOT NULL DEFAULT \'\',
                fallback_variant VARCHAR(190) NOT NULL DEFAULT \'\',
                variants         TEXT         NOT NULL DEFAULT \'{}\',
                targeting        TEXT         NULL
            )
        ')->execute();

        $this->db->createCommand(sql: "
            INSERT INTO custom_experiments (name, enabled, salt, fallback_variant, variants)
            VALUES ('custom-exp', 1, 's', 'control', '{\"control\":100}')
        ")->execute();

        $provider = new DbExperimentProvider(db: $this->db, table: 'custom_experiments');
        $experiments = $provider->getExperiments();

        $this->assertCount(1, $experiments);
        $this->assertArrayHasKey('custom-exp', $experiments);
    }

    #[Test]
    public function throwsOnInvalidVariantsJson(): void
    {
        $this->insertRow(name: 'bad-variants', enabled: true, salt: 's', fallbackVariant: 'control', variants: 'not-json');

        $provider = new DbExperimentProvider(db: $this->db);

        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid "variants" JSON');

        $provider->getExperiments();
    }

    #[Test]
    public function throwsOnUnknownFallbackVariant(): void
    {
        $this->insertRow(name: 'bad-fallback', enabled: true, salt: 's', fallbackVariant: 'missing', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        $this->expectException(InvalidExperimentRowException::class);
        $this->expectExceptionMessage('Invalid experiment "bad-fallback" in DB row');

        $provider->getExperiments();
    }

    private function insertRow(
        string $name,
        bool $enabled,
        string $salt,
        string $fallbackVariant,
        string $variants,
        ?string $targeting = null,
    ): void {
        $this->db->createCommand(sql: '
            INSERT INTO ab_experiments (name, enabled, salt, fallback_variant, variants, targeting)
            VALUES (:name, :enabled, :salt, :fallback_variant, :variants, :targeting)
        ')->bindValues([
            ':name' => $name,
            ':enabled' => $enabled ? 1 : 0,
            ':salt' => $salt,
            ':fallback_variant' => $fallbackVariant,
            ':variants' => $variants,
            ':targeting' => $targeting,
        ])->execute();
    }
}

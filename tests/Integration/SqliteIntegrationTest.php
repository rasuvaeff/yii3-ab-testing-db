<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3AbTestingDb\Tests\Integration;

use Rasuvaeff\Yii3AbTesting\AndTargetingRule;
use Rasuvaeff\Yii3AbTesting\AttributeTargetingRule;
use Rasuvaeff\Yii3AbTesting\EnvironmentTargetingRule;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;
use Rasuvaeff\Yii3AbTestingDb\Exception\InvalidExperimentRowException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbExperimentProvider::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
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

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function returnsEmptyArrayFromEmptyTable(): void
    {
        $provider = new DbExperimentProvider(db: $this->db);

        Assert::same($provider->getExperiments(), []);
    }

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

        Assert::count($experiments, 1);
        Assert::array($experiments)->hasKeys('checkout-button');

        $experiment = $experiments['checkout-button'];
        Assert::same($experiment->name, 'checkout-button');
        Assert::true($experiment->enabled);
        Assert::same($experiment->salt, 'checkout-v1');
        Assert::same($experiment->fallbackVariant, 'control');
        Assert::same($experiment->variants, ['control' => 50, 'green' => 50]);
    }

    public function readsMultipleExperiments(): void
    {
        $this->insertRow(name: 'exp-a', enabled: true, salt: 'a-salt', fallbackVariant: 'control', variants: '{"control":100}');
        $this->insertRow(name: 'exp-b', enabled: false, salt: 'b-salt', fallbackVariant: 'off', variants: '{"off":1,"on":1}');

        $provider = new DbExperimentProvider(db: $this->db);
        $experiments = $provider->getExperiments();

        Assert::count($experiments, 2);
        Assert::true($experiments['exp-a']->enabled);
        Assert::false($experiments['exp-b']->enabled);
        Assert::same($experiments['exp-b']->variants, ['off' => 1, 'on' => 1]);
    }

    public function emptySaltFallsBackToName(): void
    {
        $this->insertRow(name: 'my-exp', enabled: true, salt: '', fallbackVariant: 'control', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        Assert::same($provider->getExperiments()['my-exp']->salt, 'my-exp');
    }

    public function readsDisabledExperiment(): void
    {
        $this->insertRow(name: 'off-exp', enabled: false, salt: 's', fallbackVariant: 'control', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        Assert::false($provider->getExperiments()['off-exp']->enabled);
    }

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

        Assert::instanceOf($experiment->targeting, EnvironmentTargetingRule::class);
    }

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

        Assert::instanceOf($experiment->targeting, AttributeTargetingRule::class);
    }

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

        Assert::instanceOf($experiment->targeting, AndTargetingRule::class);
    }

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

        Assert::null($experiment->targeting);
    }

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

        Assert::count($experiments, 1);
        Assert::array($experiments)->hasKeys('custom-exp');
    }

    public function throwsOnInvalidVariantsJson(): void
    {
        $this->insertRow(name: 'bad-variants', enabled: true, salt: 's', fallbackVariant: 'control', variants: 'not-json');

        $provider = new DbExperimentProvider(db: $this->db);

        try {
            $provider->getExperiments();
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid "variants" JSON'));
        }
    }

    public function throwsOnUnknownFallbackVariant(): void
    {
        $this->insertRow(name: 'bad-fallback', enabled: true, salt: 's', fallbackVariant: 'missing', variants: '{"control":100}');

        $provider = new DbExperimentProvider(db: $this->db);

        try {
            $provider->getExperiments();
            Assert::fail('Expected InvalidExperimentRowException');
        } catch (InvalidExperimentRowException $e) {
            Assert::true(str_contains($e->getMessage(), 'Invalid experiment "bad-fallback" in DB row'));
        }
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

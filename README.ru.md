# rasuvaeff/yii3-ab-testing-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-db/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-db.svg)](LICENSE.md)
[English version](README.md)

Базовый провайдер экспериментов для A/B-тестирования в Yii3. Реализует интерфейс
`ExperimentProvider` из `rasuvaeff/yii3-ab-testing` и читает конфигурацию
экспериментов из таблицы БД одним запросом — благодаря этому эксперименты можно
переключать и перевешивать в рантайме без деплоя.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> которым можно поделиться с моделью.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent skill этого пакета: он автоматически синкается в
> `.agents/skills/` при установке.

> Собираете свою комбинацию? Матрица интеграций семейства живёт в ядре:
> `vendor/rasuvaeff/yii3-ab-testing/docs/integration.ru.md`.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.6
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0 (поставляет миграцию таблицы)
- реализация PSR-16 cache — транзитивно требуется `yiisoft/db` 2.0
  (например `yiisoft/cache`)

## Установка

```bash
composer require rasuvaeff/yii3-ab-testing-db
```

С config-plugin из Yii3 пакет автоматически биндит `ExperimentProvider` — **не**
биндите `ExperimentProvider` в приложении или другом backend'е одновременно,
иначе `yiisoft/config` сообщит об ошибке `Duplicate key`.

## Схема базы данных

Создайте таблицу `ab_experiments` (поправьте типы под вашу СУБД):

```sql
CREATE TABLE ab_experiments (
    name             VARCHAR(190) PRIMARY KEY,
    enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    salt             VARCHAR(190) NOT NULL DEFAULT '',
    fallback_variant VARCHAR(190) NOT NULL DEFAULT '',
    variants         TEXT         NOT NULL DEFAULT '{}',
    targeting        TEXT         NULL,
    state            VARCHAR(20)  NOT NULL DEFAULT 'running',
    revision         INTEGER      NOT NULL DEFAULT 1,
    created_at       VARCHAR(32)  NOT NULL,
    updated_at       VARCHAR(32)  NOT NULL
);
```

| Колонка | Тип | По умолчанию | Описание |
|---|---|---|---|
| `name` | `VARCHAR(190)` PK | — | Имя эксперимента (regex из ядра: `/^[a-z][a-z0-9_-]*\z/`) |
| `enabled` | `BOOLEAN` | `true` | Отключённый эксперимент возвращает fallback-вариант |
| `salt` | `VARCHAR(190)` | `''` | Пустая строка откатывается к имени эксперимента |
| `fallback_variant` | `VARCHAR(190)` | `''` | Должен совпадать с одним из ключей в `variants` |
| `variants` | `JSON`/`TEXT` | `'{}'` | JSON-объект `{"variant": weight}`, веса — неотрицательные целые |
| `targeting` | nullable `JSON`/`TEXT` | `null` | Targeting rule в формате общего core codec registry |
| `state` | `VARCHAR(20)` | `running` | `draft`, `running`, `paused`, `completed` или `archived` |
| `revision` | `INTEGER` | `1` | Версия optimistic locking; растёт после каждой записи. **Обязателен с 3.0** — без него у эксперимента нет идентичности конфигурации |
| `created_at`, `updated_at` | `VARCHAR(32)` | — | Operational timestamps в UTC |
| `starts_at`, `ends_at` | `VARCHAR(32)` nullable | `null` | Планируемое окно запуска; `null` — не запланировано |

Поле `variants` в строке выглядит как `{"control":50,"green":50}`. Сумма весов
должна быть больше нуля, а `fallback_variant` обязан совпадать с одним из ключей
— иначе строка отбрасывается с `InvalidExperimentRowException`.

### Миграция

Регистрируйте поставляемую миграцию **по namespace** — без путей в `vendor/`:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [[
            'App\\Migration',
            'Rasuvaeff\\Yii3AbTestingDb\\Migration',
        ]],
    ],
];
```

```bash
./yii migrate:up
./yii migrate:down --limit=1
```

> **Внимание: сниппет выше пока не находит миграцию.** Это правильная
> конфигурация, и она заработает без единой правки с вашей стороны, как только
> починят описанный ниже баг апстрима — но сегодня `./yii migrate:up` печатает
> «Your system is up-to-date», возвращает 0 и не создаёт таблиц.
>
> `yiisoft/db-migration` (2.0.x) резолвит namespace в каталог так: берёт первую
> запись в `composer/autoload_psr4.php`, с которой namespace начинается,
> сравнивая с ключом без завершающего разделителя, а остаток отрезает по
> *необрезанной* длине. Обрезание разделителя стирает границу сегмента, поэтому
> `Rasuvaeff\Yii3AbTesting\` совпадает с `Rasuvaeff\Yii3AbTestingDb\Migration`
> так, будто является его родителем — а этот пакет от него зависит, то есть
> коллизия есть всегда. Полученного каталога не существует, несуществующие
> каталоги discovery пропускает молча, и ничего не применяется.

Пока это не починено в апстриме, применяйте поставляемую миграцию сами:

```php
// src/Console/MigrateCommand.php (фрагмент)
use Rasuvaeff\Yii3AbTestingDb\Migration\M260610000000CreateAbExperimentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260619000001AddTargetingToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260731000000AddOperationalFieldsToAbExperiments;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000000CreateAbAssignmentsTable;
use Rasuvaeff\Yii3AbTestingDb\Migration\M260801000001AddScheduleToAbExperiments;
use Yiisoft\Db\Migration\Informer\ConsoleMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Injector\Injector;

$builder = new MigrationBuilder($db, new ConsoleMigrationInformer());
$injector = new Injector($container);

foreach ([
    M260610000000CreateAbExperimentsTable::class,
    M260619000001AddTargetingToAbExperiments::class,
    M260731000000AddOperationalFieldsToAbExperiments::class,
    M260801000000CreateAbAssignmentsTable::class,
    M260801000001AddScheduleToAbExperiments::class,
] as $class) {
    $injector->make($class)->up($builder);
}
```

`Injector::make()` обязателен вместо `new`: он резолвит value object имени
таблицы из вашей конфигурации. Держите цикл идемпотентным (пропускать, если
таблица уже есть) — собственной истории миграций у него нет.

Имя таблицы задаётся в params — то же значение получают и миграция, и
`DbExperimentProvider`:

```php
// config/common/params.php
'rasuvaeff/yii3-ab-testing-db' => [
    'table' => 'my_ab_experiments',
    'table_prefix' => '',   // добавляется перед `table`; например 'rsv_' → rsv_my_ab_experiments
],
```

Все поставляемые миграции получают одно и то же имя таблицы, поэтому `CREATE` и
последующий `ALTER` больше не могут разойтись по разным таблицам.

> **Обновление существующей установки.** Operational control plane
> (`ExperimentRepository` и console-команды) требует колонок `state`,
> `revision`, `created_at` и `updated_at`, которые добавляет
> `M260731000000AddOperationalFieldsToAbExperiments`. Выполните
> `./yii migrate:up` перед их использованием; `DbExperimentProvider` продолжает
> читать ещё не мигрированную таблицу. Миграция аддитивная: существующие строки
> получают `revision = 1`, epoch-таймстемпы и `state = paused` при `enabled`
> false, иначе `running`. Данные назначения не меняются.

> **Не настраивайте миграцию через DI-контейнер.**
> `M...::class => ['__construct()' => ['table' => ...]]` не работает: миграцию
> создаёт `Injector::make()`, который резолвит аргументы по типу и никогда не
> читает определение контейнера по имени класса самой миграции. Хуже того,
> добавление такого определения роняет контейнер на этапе сборки в **каждом**
> запросе, потому что класс не автозагружается, пока его не подключит раннер
> миграций. Этот рецепт был описан в 1.x и никогда не работал.

## Использование

### Базовый DB-провайдер

```php
use Rasuvaeff\Yii3AbTesting\AbTesting;
use Rasuvaeff\Yii3AbTesting\WeightedHashAssignmentStrategy;
use Rasuvaeff\Yii3AbTestingDb\DbExperimentProvider;

$provider = new DbExperimentProvider(
    db: $connection,            // yiisoft/db ConnectionInterface
    table: 'ab_experiments',    // optional, default is 'ab_experiments'
);

$ab = new AbTesting(provider: $provider, strategy: new WeightedHashAssignmentStrategy());

if ($ab->is(experiment: 'checkout-button', variant: 'green', subjectId: (string) $userId)) {
    // green variant
}
```

### С PSR-16 кэшированием

`getExperiments()` выполняется при каждой сборке реестра (на каждый запрос).
Без кэширования это DB-запрос на каждый запрос — оберните провайдер в
`CachedExperimentProvider`:

```php
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;

$cached = new CachedExperimentProvider(
    inner: $provider,
    cache: $psr16Cache,         // PSR-16 CacheInterface
    ttl: 60,                    // seconds
    namespace: null,            // optional identity tenant/connection
);

$ab = new AbTesting(provider: $cached, strategy: new WeightedHashAssignmentStrategy());
```

Default cache namespace включает имя таблицы `DbExperimentProvider`, поэтому
провайдеры разных таблиц не читают реестры друг друга. Если tenant-ы или
connection используют одинаковое имя таблицы и общий cache backend, задайте
разный непустой `namespace` (или `cache.namespace` в Yii params). Массив из cache
принимается, только если каждый строковый ключ совпадает с `Experiment::name`, а
каждое значение является `Experiment`; невалидный payload заменяется данными
inner provider.

### Очистка кэша

```php
$cached->clear();               // removes cached experiments, next call reloads from DB
```

### Operational repository

Config-plugin также биндит `ExperimentRepository`. Записи транзакционны,
атомарно увеличивают `revision` и инвалидируют настроенный кэш только после
успешного commit:

```php
use Rasuvaeff\Yii3AbTesting\Experiment;
use Rasuvaeff\Yii3AbTestingDb\ExperimentRepository;
use Rasuvaeff\Yii3AbTestingDb\ExperimentState;

/** @var ExperimentRepository $repository */
$record = $repository->create(
    experiment: new Experiment(
        name: 'checkout-button',
        enabled: false,
        salt: 'checkout-v1',
        fallbackVariant: 'control',
        variants: ['control' => 50, 'green' => 50],
    ),
    state: ExperimentState::Draft,
);

$record = $repository->enable(
    name: 'checkout-button',
    expectedRevision: $record->revision,
);
$record = $repository->reweight(
    name: 'checkout-button',
    variants: ['control' => 10, 'green' => 90],
    expectedRevision: $record->revision,
);
```

Устаревшая revision даёт `RevisionConflictException`, отсутствующее имя —
`ExperimentNotFoundException`. `archive()` выключает эксперимент, но сохраняет
его имя и историю revisions. Runtime-эксперимент получает
`configurationId = "db:<revision>"`, поэтому exposure deduplication и sticky
assignments не смешивают разные определения.

### Консольное управление

```bash
./yii ab-testing:validate
./yii ab-testing:list
./yii ab-testing:create checkout-button '{"control":50,"green":50}' control --salt=checkout-v1
./yii ab-testing:enable checkout-button 1
./yii ab-testing:reweight checkout-button '{"control":10,"green":90}' 2
./yii ab-testing:disable checkout-button 3
```

Revision обязательна для мутирующих команд, чтобы один оператор не мог незаметно
перезаписать изменение другого.

## API reference

| Класс | Описание |
|---|---|
| `DbExperimentProvider` | Читает все эксперименты из БД одним `SELECT *` |
| `CachedExperimentProvider` | PSR-16 декоратор, кэширует весь набор экспериментов с TTL |
| `ExperimentRepository` | Контракт создания, изменения и lifecycle экспериментов |
| `DbExperimentRepository` | Транзакционная DB-реализация с optimistic locking |
| `ExperimentRecord` | Runtime experiment вместе со state, revision и timestamps |
| `ExperimentState` | Lifecycle enum: draft/running/paused/completed/archived |
| `LastKnownGoodExperimentProvider` | Opt-in декоратор: отдаёт последнее удачное чтение во время недоступности источника, логируя каждый fallback |
| `DbAssignmentStore` | Серверное закрепление варианта, ключ — субъект, а не браузер |
| `ExperimentSchedule` | Планируемое окно запуска; только планирование, на назначение не влияет |
| `AbExperimentsTableName`, `AbAssignmentsTableName` | Имена таблиц как типы, чтобы миграции настраивались через `Injector` |
| `InvalidExperimentRowException` | Бросается, когда строка БД имеет невалидную структуру или порождает невалидный эксперимент |

## Безопасность

- Хэширование назначения, обработка fallback'а, логика forced/disabled остаются
  в ядерном пакете — DB-адаптер является только источником конфигурации.
- Невалидные данные строки (отсутствующие колонки, некорректный
  `variants`/`targeting` JSON, неверные типы, нестроковые environment values,
  пустые `and`/`or`, невалидные вложенные rules, отрицательные веса, невалидное
  имя эксперимента, неизвестный fallback, нулевая сумма весов) бросают
  `InvalidExperimentRowException` вместо молчаливого искажения назначения.
  Ошибки валидации ядра оборачиваются, поэтому вызывающему коду нужно ловить
  только один тип исключения.
- SQL-инъекций нет: имя таблицы квотируется через quoter `yiisoft/db`.
- **Перевешивание сдвигает бакеты.** Переключение `enabled` безопасно
  (kill switch). Изменение весов или набора вариантов сдвигает границы
  бакетов и перетасовывает субъектов — используйте sticky-назначение из
  `yii3-ab-testing-web`, чтобы зафиксировать субъектов при таких изменениях.
- Repository-команды используют bound values и проверенное имя таблицы. Никогда
  не собирайте write SQL из аргументов команды.

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

## Разработка

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
vendor/bin/testo --suite=Integration
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).

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

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-ab-testing` ^1.0
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
    variants         TEXT         NOT NULL DEFAULT '{}'
);
```

| Колонка | Тип | По умолчанию | Описание |
|---|---|---|---|
| `name` | `VARCHAR(190)` PK | — | Имя эксперимента (regex из ядра: `/^[a-z][a-z0-9_-]*$/`) |
| `enabled` | `BOOLEAN` | `true` | Отключённый эксперимент возвращает fallback-вариант |
| `salt` | `VARCHAR(190)` | `''` | Пустая строка откатывается к имени эксперимента |
| `fallback_variant` | `VARCHAR(190)` | `''` | Должен совпадать с одним из ключей в `variants` |
| `variants` | `JSON`/`TEXT` | `'{}'` | JSON-объект `{"variant": weight}`, веса — неотрицательные целые |

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

Имя таблицы задаётся в params — то же значение получают и миграция, и
`DbExperimentProvider`:

```php
// config/common/params.php
'rasuvaeff/yii3-ab-testing-db' => [
    'table' => 'my_ab_experiments',
    'table_prefix' => '',   // добавляется перед `table`; например 'rsv_' → rsv_my_ab_experiments
],
```

Обе поставляемые миграции (`M260610000000CreateAbExperimentsTable` и
`M260619000001AddTargetingToAbExperiments`) получают одно и то же имя таблицы,
поэтому `CREATE` и последующий `ALTER` больше не могут разойтись по разным
таблицам.

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

## API reference

| Класс | Описание |
|---|---|
| `DbExperimentProvider` | Читает все эксперименты из БД одним `SELECT *` |
| `CachedExperimentProvider` | PSR-16 декоратор, кэширует весь набор экспериментов с TTL |
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

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

## Разработка

```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).

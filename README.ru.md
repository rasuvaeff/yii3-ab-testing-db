# rasuvaeff/yii3-ab-testing-db
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-ab-testing-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-ab-testing-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-ab-testing-db/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/yii3-ab-testing-db/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-ab-testing-db/php)](https://packagist.org/packages/rasuvaeff/yii3-ab-testing-db)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-ab-testing-db.svg)](LICENSE.md)
Поставщик экспериментов на основе базы данных для A/B-тестирования Yii3. Реализует интерфейс
 `ExperimentProvider` из `rasuvaeff/yii3-ab-testing` и считывает конфигурацию эксперимента
 из таблицы базы данных в одном запросе, поэтому эксперименты
 можно переключать и изменять вес во время выполнения без развертывания.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете использовать в контексте приглашения. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-ab-testing` ^1.0
 - `yiisoft/db` ^2.0
 - `yiisoft/db-migration` ^2.0 (отправляет миграцию таблицы)
 - реализация кэша PSR-16 — требуется транзитивно для `yiisoft/db` 2.0
 (например, `yiisoft/cache`)

## Установка
```bash
composer require rasuvaeff/yii3-ab-testing-db
```
С помощью плагина конфигурации Yii3 этот пакет автоматически привязывает `ExperimentProvider` —
 **не** также привязывайте `ExperimentProvider` в вашем приложении или другом бэкэнде, иначе
 `yiisoft/config` сообщит об ошибке `Дублировать ключ`. @@ЛИНИЯ@@
## Схема базы данных
Создайте таблицу ab_experiments (настройте типы для вашей СУБД):

```sql
CREATE TABLE ab_experiments (
    name             VARCHAR(190) PRIMARY KEY,
    enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    salt             VARCHAR(190) NOT NULL DEFAULT '',
    fallback_variant VARCHAR(190) NOT NULL DEFAULT '',
    variants         TEXT         NOT NULL DEFAULT '{}'
);
```
| Столбец | Тип | По умолчанию | Описание |
 |---|---|---|---|
 | `имя` | `ВАРЧАР(190)` ПК | — | Название эксперимента (основное регулярное выражение: `/^[a-z][a-z0-9_-]*$/`) |
 | `включено` | `БУЛЕВАЯ` | `правда` | Отключенный эксперимент возвращает запасной вариант |
 | `соль` | `ВАРЧАР(190)` | `''` | Пустая строка возвращает имя эксперимента |
 | `резервный_вариант` | `ВАРЧАР(190)` | `''` | Должен быть одним из ключей `вариантов` |
 | `варианты` | `JSON`/`ТЕКСТ` | `'{}'` | Объект JSON `{"вариант": вес}`, неотрицательные целые веса |

 `Варианты` строки выглядят как `{"control":50,"green":50}`. Общий вес
 должен быть больше нуля, а `fallback_variant` должен соответствовать одному из ключей, иначе строка
 будет отклонена с `InvalidExperimentRowException`. @@ЛИНИЯ@@
### Миграция
The package ships a migration (`migrations/`) for [yiisoft/db-migration](https://github.com/yiisoft/db-migration).
Зарегистрируйте исходный путь в файле `config/params.php` вашего приложения:

```php
'yiisoft/db-migration' => [
    'sourcePaths' => [
        dirname(__DIR__) . '/vendor/rasuvaeff/yii3-ab-testing-db/migrations',
    ],
],
```
Затем примените и отмените его с помощью консоли Yii:
.
```bash
./yii migrate:up
./yii migrate:down --limit=1
```
Имя таблицы по умолчанию равно `ab_experiments` и должно соответствовать аргументу `table`
 `DbExperimentProvider`. Чтобы использовать собственное имя, привяжите аргумент конструктора миграции:

```php
M260610000000CreateAbExperimentsTable::class => [
    '__construct()' => ['table' => 'my_ab_experiments'],
],
```
## Использование
### Базовый поставщик БД
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
### С кэшированием PSR-16
`getExperiments()` запускается при каждой сборке реестра (по запросу). Без кэширования
 — это запрос к БД на каждый запрос — оберните поставщика в `CachedExperimentProvider`:

```php
use Rasuvaeff\Yii3AbTestingDb\CachedExperimentProvider;

$cached = new CachedExperimentProvider(
    inner: $provider,
    cache: $psr16Cache,         // PSR-16 CacheInterface
    ttl: 60,                    // seconds
);

$ab = new AbTesting(provider: $cached, strategy: new WeightedHashAssignmentStrategy());
```
### Очистить кеш
```php
$cached->clear();               // removes cached experiments, next call reloads from DB
```
## Справочник по API
| Класс | Описание |
 |---|---|
 | `DbExperimentProvider` | Считывает все эксперименты из БД в одном `SELECT *` |
 | `CachedExperimentProvider` | Декоратор PSR-16 кэширует весь набор экспериментов с TTL |
 | `InvalidExperimentRowException` | Вызывается, когда строка БД имеет недопустимую структуру или дает недопустимый эксперимент | @@ЛИНИЯ@@
## Безопасность
- Хеширование назначений, обработка резервных вариантов, принудительная/отключенная логика остаются в основном пакете
 — адаптер БД является лишь источником конфигурации.
 - Неверные данные строки (отсутствующие столбцы, неверный JSON `вариантов`, неправильные типы, отрицательные веса
, недопустимое имя эксперимента, неизвестный резервный вариант, нулевой общий вес).
 выдает `InvalidExperimentRowException` вместо молчаливого неправильного назначения. Основные ошибки проверки
 упакованы, поэтому вызывающим объектам нужно перехватывать только один тип исключения.
 - Риск SQL-инъекций отсутствует: имя таблицы цитируется через котировщик yiisoft/db.
 - **Изменение веса сегментов сдвигов.** Безопасно включить «включено» (выключатель). Изменение весов
 или набора вариантов смещает границы сегментов и перетасовывает темы — используйте липкое назначение
 `yii3-ab-testing-web`, чтобы закрепить темы среди таких изменений. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев. @@ЛИНИЯ@@
## Разработка
```bash
composer build          # full gate: validate + normalize + cs + psalm + test
composer cs:fix         # auto-fix code style
composer psalm          # static analysis
composer test           # run tests
```
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).

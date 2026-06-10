# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | `DbExperimentProvider` reading experiments from SQLite | No |
| `cached-usage.php` | `CachedExperimentProvider` with a PSR-16 cache | No |
| `yii-config.php` | Yii3 config-plugin wiring (params + di) | No |

All scripts use `bootstrap.php`, which builds an in-memory SQLite connection with
the `ab_experiments` table created and seeded (an enabled `checkout-button` and a
disabled `pricing-page`). `psr16-null-cache.php` returns a throwaway PSR-16 cache
used by the Yii3 wiring example.

## Running

```bash
# From package root, after composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/cached-usage.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/yii-config.php
```

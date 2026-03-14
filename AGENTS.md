# AGENTS.md — PriceBuddy Codebase Reference

This file is a technical reference for AI agents modifying this codebase. Read it before making changes.

---

## Project Purpose

PriceBuddy is a self-hosted price tracking application. Users add product URLs; the app periodically scrapes prices and notifies users when prices drop. It supports multiple stores with configurable scraping strategies, an optional browser-based scraper, product search via SearXng, affiliate link injection, and a full backup/restore system.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.4 |
| Framework | Laravel 11.31 |
| Admin UI | Filament 3.2 (full-stack, no separate API) |
| Database | SQLite (via Docker env `DB_CONNECTION=sqlite`) |
| Queue | Sync by default (configurable) |
| Scraper (browser) | `amerkurev/scrapper` Docker sidecar on port 3000 |
| Scraper (HTTP) | `jez500/web-scraper-for-laravel` |
| Notifications | Mail, Database, Pushover, Gotify, Apprise |
| Testing | PestPHP 3 + PHPUnit 11 |
| Code style | Laravel Pint |

Key Composer packages:
- `filament/filament` — admin panel
- `spatie/laravel-sluggable` — auto-slug on Store name
- `moneyphp/money` — currency formatting
- `yoeriboven/laravel-log-db` — logs stored in DB
- `calebporzio/sushi` — in-memory Eloquent models from arrays
- `filament/spatie-laravel-settings-plugin` — settings UI

---

## Directory Structure

```
app/
  Actions/          # Single-purpose action classes (CreateProductAction, CreateStoreAction)
  Console/Commands/ # Artisan commands (buddy:*)
  Dto/              # Data transfer objects (PriceCacheDto, ProductResearchUrlDto)
  Enums/            # PHP 8 enums (Statuses, Trend, ScraperService, etc.)
  Events/           # Eloquent/domain events
  Filament/
    Pages/          # Custom Filament pages (Dashboard, Settings, Status)
    Resources/      # Filament CRUD resources (Product, Store, Tag, User, LogMessage)
  Http/             # Standard Laravel HTTP layer (minimal usage)
  Jobs/             # Queued jobs (price fetching)
  Listeners/        # Event listeners
  Livewire/         # Livewire components (product research, price chart, etc.)
  Models/           # Eloquent models
  Notifications/    # Laravel notification classes
  Policies/         # Authorization policies
  Rules/            # Custom validation rules (StoreUrl, ImportStore)
  Services/         # Core business logic services
  Settings/         # Spatie settings classes (AppSettings)
config/
  affiliates.php    # Affiliate tag injection config per store domain
database/
  factories/
  migrations/
  seeders/
    StoreSeeder.php
    Stores/         # Per-country PHP arrays of store definitions
      australia.php
      usa.php
tests/
  Feature/
    Filament/
    Integrations/
    Models/
  Unit/
    Notifications/
    Services/
  Traits/
    ScraperTrait.php
  Fixtures/
docker-compose.yml
```

---

## Models

### `Store` — `app/Models/Store.php`
Represents a scraped store (e.g. Amazon.fr, eBay AU).

**Fillable:** `name`, `initials`, `domains`, `scrape_strategy`, `settings`, `notes`

**Casts:** `domains` → array, `scrape_strategy` → array, `settings` → array

**`domains` structure:** JSON array of objects: `[{"domain": "amazon.fr"}, {"domain": "www.amazon.fr"}]`

**`scrape_strategy` structure:**
```json
{
  "title": {"type": "selector", "value": "title"},
  "price": {"type": "selector", "value": ".a-offscreen"},
  "image": {"type": "regex", "value": "~\"hiRes\":\"(.+?)\"~"}
}
```
Strategy types: `selector`, `regex`, `json`, `xpath`

**`settings` structure:** `scraper_service` (`http`|`api`), `scraper_service_settings`, `test_url`, `locale_settings.locale`, `locale_settings.currency`

**Relationships:** `user()` BelongsTo, `urls()` HasMany, `products()` HasManyThrough (via Url)

**Key scope — `scopeDomainFilter` (line 103):**
```php
// Uses whereLike for cross-database compatibility (SQLite + MySQL + PostgreSQL).
// DO NOT replace with whereJsonContains(['domain' => $value]) — it silently
// returns zero rows on SQLite because SQLite's json_each uses IS (identity),
// not structural equality for JSON objects.
$subQuery->whereLike('domains', '%"domain":"'.$first.'"%');
```

**`hasDomain($domain): bool` (line 200):** PHP-level check on already-loaded `$store->domains` array. Safe to use when the model is already in memory.

---

### `Product` — `app/Models/Product.php`
Core entity. Represents a tracked product owned by a User.

**Casts:** `status` → `Statuses` enum, `price_cache` → array, `ignored_urls` → array, `favourite` → boolean

**`price_cache`:** Denormalised array of `PriceCacheDto`-shaped data, one entry per URL/store. Rebuilt by `updatePriceCache()`. Never query this for individual store prices — use it for display only.

**Key methods:**
- `updatePriceCache()` — rebuilds `price_cache` from all associated URL prices
- `buildPriceCache()` — internal builder, returns array of `PriceCacheDto`
- `shouldNotifyOnPrice()` — checks `notify_price` and `notify_percent` thresholds
- `updatePrices()` — triggers price fetch on all URLs

**Scopes:** `scopePublished`, `scopeFavourite`, `scopeCurrentUser`, `scopeLowestPriceInDays`

---

### `Url` — `app/Models/Url.php`
A specific URL for a product at a store.

**Relationships:** `store()` BelongsTo, `product()` BelongsTo, `prices()` HasMany, `latestPrice()` HasOne

**Key static method — `createFromUrl(string $url, Product $product)`:**
Full pipeline: validates URL → finds/creates store → scrapes → creates Url record → creates initial Price.

**`updatePrice()`:** Scrapes and records a new Price only if the price changed from the last recorded value.

---

### `Price` — `app/Models/Price.php`
A single price record for a URL at a point in time.

**Booted events:** On `created` → calls `$price->url->product->updatePriceCache()` and dispatches `PriceCreatedEvent`.

**Note:** When bulk-inserting prices (e.g. backup import), use `Price::withoutEvents()` to prevent redundant cache rebuilds.

---

### `User` — `app/Models/User.php`
Standard Laravel user with Filament panel access. All users can access the panel (`canAccessPanel` returns `true`).

---

## Services

### `ScrapeUrl` — `app/Services/ScrapeUrl.php`
Core scraping service.

**Usage:** `ScrapeUrl::new($url)->scrape()` or `ScrapeUrl::new($url)->getStore()`

**`getStore(): ?Store` (line 202):**
Extracts hostname via `Uri::of($this->url)->host()` then runs `Store::query()->domainFilter($host)->oldest()->first()`.

**`scrape(): array`:**
Returns `['title' => ..., 'price' => ..., 'image' => ...]`. Retries up to `max_attempts_to_scrape` times (from AppSettings). Logs failures to DB.

**Selector format:** `css-selector` or `css-selector|attribute` (e.g. `meta[property=og:title]|content`)

**`parseSelector(string $value): array` (static):** Parses `selector|attribute` notation.

---

### `AutoCreateStore` — `app/Services/AutoCreateStore.php`
Attempts to auto-detect scraping strategies for an unknown URL.

**`canAutoCreateFromUrl(string $url): bool`** — checks if the URL's domain is resolvable and HTML is parseable.

**`createStoreFromUrl(string $url): ?Store`** — checks for existing store first (via `domainFilter`), then tries each auto-detect strategy from `config('price_buddy.auto_create_store_strategies')`, calls `CreateStoreAction`.

---

### `DatabaseBackupService` — `app/Services/DatabaseBackupService.php`
Export/import of the full database state.

**`export(): string`** — JSON of all products + URLs + stores + prices.

**`import(array $payload, ?User $defaultUser): void`** — Transactional. Resolves stores by `slug` first, then `name`. Creates store via `CreateStoreAction` if not found. Applies original timestamps.

**`resolveStore()` (line 158):** On match by slug/name, the existing store is **updated** with data from the backup (fill + save). This means importing a backup will overwrite local store config.

---

### `PriceFetcherService` — `app/Services/PriceFetcherService.php`
Chunks all published products and dispatches `UpdateProductPricesJob` for each.

---

### `SearchService` — `app/Services/SearchService.php`
Product URL research via SearXng integration.

**Pipeline:** `getRawResults` → `filterResults` → `normalizeStructure` → `addStores` → `hydrateWithScrapedData` → `saveUrlResearch`

Results are cached. Progress state is tracked in Cache. Uses `UrlResearch` model for persistence.

---

## Actions

### `CreateStoreAction` — `app/Actions/CreateStoreAction.php`
Invokable. Sets default locale, currency, and `scraper_service` if missing. Calls `Store::create()`. Returns `null` on exception.

### `CreateProductAction` — `app/Actions/CreateProductAction.php`
Invokable. Requires auth user and a `title`. Sets `favourite: true` by default. Truncates title/image to `ScrapeUrl::MAX_STR_LENGTH`.

---

## Enums

| Enum | Values | Notes |
|---|---|---|
| `Statuses` | `Published = 'p'`, `Archived = 'a'` | Cast on Product |
| `ScraperService` | `Http`, `Api` | `Http` = curl, `Api` = browser sidecar |
| `Trend` | `Up`, `Down`, `Lowest`, `None` | Has `calculateTrend()`, `getIcon()`, `getColor()` |
| `IsProductPage` | `NotProcessed`, `YesViaStore`, `YesViaAutoCreate`, `Maybe`, `No` | Used in search/research flow |
| `NotificationMethods` | `Mail`, `Database`, `Pushover`, `Gotify`, `Apprise` | `getChannel()` maps to notification class |
| `Icons` | Heroicon string constants | `getTrendIcon()` helper |
| `LogLevels` | PSR log levels | Implements Filament color/icon contracts |
| `IntegratedServices` | `SearXng = 'searxng'` | For search integration config |

---

## DTOs

### `PriceCacheDto` — `app/Dto/PriceCacheDto.php`
Wraps one store's price cache entry. Methods: `getPriceFormatted()`, `getAggregateFormatted()`, `isLastScrapeSuccessful()`, `matchesNotification()`, `fromArray()`, `toArray()`.

### `ProductResearchUrlDto` — `app/Dto/ProductResearchUrlDto.php`
Represents a candidate URL during product search. Determines `IsProductPage` status. Caches scrape result for 30 min. `getStore()` result is memoised per instance.

---

## Validation Rules

### `StoreUrl` — `app/Rules/StoreUrl.php`
Used on URL input fields. Checks:
1. Domain matches a known store OR `create_store` flag is set in form data
2. If store found: scrape must return a non-empty title AND price
3. If no store and auto-create: `AutoCreateStore::canAutoCreateFromUrl()` must pass

Error message on domain mismatch: `"The domain does not belong to any stores"`

### `ImportStore` — `app/Rules/ImportStore.php`
Validates a JSON string pasted into the store import action. Requires: `name`, `domains`, `scrape_strategy.title`, `scrape_strategy.price`, valid `settings.scraper_service`.

---

## Store Seeding System

Stores are seeded from PHP array files in `database/seeders/Stores/`. The `StoreSeeder` globs all `.php` files in that directory.

**Adding a new country/region:** Create `database/seeders/Stores/<region>.php` returning an array of store definition arrays.

**Store definition shape:**
```php
[
    'name'            => 'Amazon.fr',
    'slug'            => 'amazonfr',       // optional, auto-generated from name
    'initials'        => 'AM',             // optional, auto-generated
    'domains'         => [
        ['domain' => 'amazon.fr'],
        ['domain' => 'www.amazon.fr'],
    ],
    'scrape_strategy' => [
        'title' => ['type' => 'selector', 'value' => 'title'],
        'price' => ['type' => 'selector', 'value' => '.a-offscreen'],
        'image' => ['type' => 'regex',    'value' => '~"hiRes":"(.+?)"~'],
    ],
    'settings' => [
        'scraper_service'          => 'http',   // or 'api'
        'scraper_service_settings' => '',
        'test_url'                 => 'https://amazon.fr/dp/...',
        'locale_settings'          => ['locale' => 'fr', 'currency' => 'EUR'],
    ],
]
```

**Artisan commands:**
- `php artisan buddy:create-stores all` — seeds all countries
- `php artisan buddy:create-stores australia` — seeds one country
- `php artisan buddy:create-stores all --update` — updates existing stores

---

## Artisan Commands (`buddy:*`)

| Command | Class | Purpose |
|---|---|---|
| `buddy:init-db` | `InitDatabase` | First-run setup: migrate + seed stores + create user |
| `buddy:create-stores {country} {--update}` | `CreateStores` | Seed stores from PHP files |
| `buddy:fetch-all {--log}` | `FetchAll` | Trigger price update for all products |
| `buddy:regenerate-price-cache` | `RegeneratePriceCache` | Rebuild `price_cache` on all products |
| `buddy:build-search-research {product_name}` | `BuildSearchResearch` | Run SearXng search for a product name |

---

## Filament Resources

| Resource | Model | Notes |
|---|---|---|
| `ProductResource` | Product | Main resource. Has Actions/, Columns/, Pages/, Widgets/ subdirs |
| `StoreResource` | Store | Has `ImportStoreAction` and `ShareStoreAction` header actions |
| `TagResource` | Tag | Simple CRUD |
| `UserResource` | User | Admin only |
| `LogMessageResource` | LogMessage (yoeriboven) | Read-only log viewer |

**Custom Pages:**
- `HomeDashboard` → `/` — shows `ProductStats` widget
- `AppSettingsPage` → `/settings` — scrape, locale, notifications, integrations, backup
- `StatusPage` → `/status` — shows `artisan about` output for `price_buddy` section
- `Login` → custom layout

---

## Configuration Files

### `config/affiliates.php`
Controls affiliate tag injection. Key env var: `AFFILIATE_ENABLED` (default `false`).
Supported stores: Amazon (all regional domains), Amazon AU, eBay (all regional domains).
Each entry maps a domain to query parameters to inject.

### `config/price_buddy.php`
App-specific config. Contains `auto_create_store_strategies` used by `AutoCreateStore`.

### `config/app.php`
Standard Laravel. App settings (locale, timezone, etc.).

---

## Environment Variables

| Variable | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | Database driver |
| `DB_FOREIGN_KEYS` | `true` | SQLite FK enforcement |
| `APP_USER_EMAIL` | `admin@example.com` | Seed user email (`buddy:init-db`) |
| `APP_USER_PASSWORD` | `admin` | Seed user password |
| `SCRAPER_BASE_URL` | `http://scraper:3000` | Browser scraper sidecar URL |
| `AFFILIATE_ENABLED` | `false` | Enable affiliate link injection |

---

## Testing

**Framework:** PestPHP 3 (wraps PHPUnit 11)

**Run tests:** `php artisan test` or `./vendor/bin/pest`

**Structure:**
```
tests/
  Feature/
    Filament/       # UI/form tests via Filament testing helpers
    Integrations/   # External service integration tests
    Models/         # Model behaviour tests (StoreTest, ProductTest, etc.)
  Unit/
    Notifications/
    Services/       # ScrapeUrlTest, AutoCreateStoreTest
  Traits/
    ScraperTrait.php  # Mocks HTTP scraper responses
  Fixtures/
    AutoCreateStore/  # HTML fixtures for auto-create tests
    SearchXng/        # Fixtures for SearXng search tests
```

Tests use `RefreshDatabase` and SQLite in-memory. The `ScraperTrait` mocks the `jez500/web-scraper-for-laravel` HTTP client.

---

## Docker Setup

Two services in `docker-compose.yml`:
- **`app`** — PHP/Laravel app on port `8080:80`, mounts `./storage` and `./.env`
- **`scraper`** — `amerkurev/scrapper` browser scraper on port `3030:3000` (internal `3000`)

The app container runs `buddy:init-db` on first boot (via entrypoint in `docker/php.dockerfile`).

---

## Known Gotchas

### SQLite + `whereJsonContains` with objects
**Never use** `whereJsonContains('domains', ['domain' => $value])` on the `domains` column.
SQLite compiles `whereJsonContains` to `json_each(...) where value IS ?` which uses identity comparison and silently returns zero rows for JSON objects.

**Current fix** in `Store::scopeDomainFilter` (`app/Models/Store.php:109`):
```php
$subQuery->whereLike('domains', '%"domain":"'.$first.'"%');
```
`whereLike` is database-agnostic (works on SQLite, MySQL, PostgreSQL) and matches the serialised JSON text reliably.

### `Price::withoutEvents()` for bulk inserts
When creating many prices programmatically (e.g. backup restore), always wrap in `Price::withoutEvents()`. Each price creation fires `PriceCreatedEvent` which triggers `product->updatePriceCache()` — doing this for hundreds of prices is very slow.

### `resolveStore()` in backup import overwrites existing stores
`DatabaseBackupService::resolveStore()` (line 178) **fills and saves** an existing store if found by slug or name. Importing a backup will update local store configurations with whatever is in the backup file.

### Store `price_cache` is denormalised
`Product::price_cache` is a JSON column rebuilt on every price change. Do not query individual prices from it — use the `prices()` relationship. Use `price_cache` only for display/aggregation.

### Slug generation
`Store` uses `spatie/laravel-sluggable`. The `slug` field is auto-generated from `name` on create. When importing stores via `DatabaseBackupService`, the slug from the backup is force-applied via `forceFill(['slug' => $slug])` after creation.

### Selector attribute notation
`ScrapeUrl::parseSelector()` supports `css-selector|attribute`. For example `meta[property=og:title]|content` extracts the `content` attribute of the matched element. Standard CSS selectors without `|` return the element's text content.

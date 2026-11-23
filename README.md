# Weather Forecast Collector

A Dockerized PHP 8.4 CLI application and lightweight web runner/viewer that fetches daily and hourly weather forecasts from **Open-Meteo** and **WeatherAPI.com**, normalizes units and timestamps, and persists them idempotently into **PostgreSQL 17**.

## Overview of `public/index.php`

`public/index.php` pairs an interactive **Forecast Collector Runner** panel at the top with a read-only forecast viewer below. Selecting `1`–`7` days and clicking **Run Script** executes `bin/fetch-forecasts.php --force --days=<1-7>` on demand—staying silent on success, showing command output only on failure, and using a redirect/flash flow so browser refreshes or filter changes never re-trigger external API calls. Below the runner, you can filter stored daily and hourly PostgreSQL rows by location, provider (`all`, `open_meteo`, `weatherapi`), and day window (`1`–`7`).

---

## 1. Bringing a Fresh Clone to Life (All you need is to run these bash commands)

### Prerequisites
- Docker and Docker Compose (v2+)
- A free API key from [WeatherAPI.com](https://www.weatherapi.com/) *(Open-Meteo requires no API key)*

> **Windows (Git Bash) note:** Prefix `docker compose exec` commands with `MSYS_NO_PATHCONV=1` so container paths like `/var/www/html/...` are not rewritten by MSYS.

### Step 1: Create Your Working Directory & Clone the Project
Run all setup, Docker, and CLI commands from the repository root directory so relative paths (`database/`, `bin/`, `public/`, `.env`) resolve consistently:

```bash
mkdir -p ~/projects && cd ~/projects
git clone https://github.com/savvaskef/weather-forecast-collector.git weather-forecast-collector
cd weather-forecast-collector
```

**Verify:** Confirm you are inside the project root and see `docker-compose.yml`, `bin/`, `database/`, and `public/`:
```bash
pwd && ls -la
```

### Step 2: Configure `.env`
Copy `.env.example` to `.env` and fill in your PostgreSQL settings, timezone, and WeatherAPI key:

```bash
cp .env.example .env
```

Ensure `.env` defines:
```env
DB_HOST=db
DB_PORT=5432
DB_NAME=weather_db
DB_USER=weather_user
DB_PASSWORD=the_passw

WEATHERAPI_KEY=your_weatherapi_key_here
TZ=Europe/Athens
```

**Verify:** Confirm `.env` is present in the project root with your API key and database variables set:
```bash
cat .env
```

### Step 3: Build and Start the Containers
From the project root, build the PHP image and start both `db` (PostgreSQL 17) and `app` (PHP 8.4 + built-in web server mapped to port `8081`):

```bash
docker compose up -d --build
```
*(On first boot, PostgreSQL automatically executes the `.sql` initialization script in `database/`, creating the schema and seeding the initial default location. If an older database volume already existed and initialization was skipped, reset with `docker compose down -v && docker compose up -d --build`.)*

**Verify:** Confirm both containers are up and `app` exposes `0.0.0.0:8081->80/tcp`:
```bash
docker compose ps
```

### Step 4: Ensure Composer Dependencies Are Installed
Install PHP dependencies inside the running `app` container (if not already baked into the Docker image):

```bash
docker compose exec app composer install
```

**Verify:** Confirm `vendor/autoload.php` is ready:
```bash
docker compose exec app test -f vendor/autoload.php && echo "Composer dependencies ready"
```

### Step 5: Verify the Seeded Location & Add Extra Locations (`bin/add-location.php`)
Check the initial location seeded from `database/*.sql`:

```bash
docker compose exec db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT id, name, latitude, longitude, timezone FROM locations ORDER BY id;"'
```

To register additional locations, use `bin/add-location.php`:
```bash
docker compose exec app php bin/add-location.php --help
```
*(If `bin/add-location.php` is not yet present in your branch, see Section 6 for the equivalent SQL `INSERT` statement.)*

**Verify:** Re-run the `SELECT` query above and confirm all desired locations appear with valid IDs and IANA timezones.

### Step 6: Observe & Append to Database from the Container in `public/index.php`
You are ready to observe the project's database and augment it with weather forecasts from a front-end browser:

- **URL:** `http://localhost:8081`

**Verify:**
1. In the top **Forecast Collector Runner** panel, choose `1`–`7` days and click **Run Script**. On success, no raw output or error box appears and the tables below refresh automatically.
2. In the **Stored Weather Forecasts** viewer below, select a location, provider, and day window (`1`–`7`) and click **Apply** to inspect normalized daily and hourly forecast rows.
3. Optional CLI inspection check:
   ```bash
   docker compose exec app php bin/query-forecasts.php --days=4
   ```

---

## 2. Back-end CLI Commands

All CLI tools live under `bin/` and are executed inside the `app` container via `docker compose exec app php bin/<script>.php`.

### 1. `bin/fetch-forecasts.php` — Fetch & Store Forecasts
Fetches daily and hourly forecasts from Open-Meteo and WeatherAPI.com for every registered location in `locations`, normalizes timestamps to UTC and units to °C / mm, and upserts them into PostgreSQL.

- **Optional parameters:**
  - `--days=<1-7>` *(default: `1`)* — Number of local calendar days (`TZ`) to align and store. Open-Meteo returns the full `1`–`7` day horizon, while WeatherAPI's free tier caps daily forecasts at `3` days.
  - `--force` — Bypasses the once-per-UTC-day skip guard (`forecast_runs`) so providers are re-queried and existing rows are updated immediately.
  - `--quiet-forecast` — Suppresses printing individual daily/hourly rows to stdout (keeps run summary/status output).
  - `--print-hours=<N>` — Caps the number of hourly rows printed per provider in terminal output.
- **Examples:**
  ```bash
  # Default run: fetch today's forecast (skips providers that already succeeded today in UTC)
  docker compose exec app php bin/fetch-forecasts.php

  # Force a fresh 5-day fetch without printing every hourly row
  docker compose exec app php bin/fetch-forecasts.php --force --days=5 --quiet-forecast
  ```

### 2. `bin/query-forecasts.php` — Inspect Stored Forecasts
Queries PostgreSQL directly and prints stored daily summaries and hourly rows to the terminal for quick verification without calling external APIs.

- **Optional parameters:**
  - `--days=<1-7>` *(default: `1`)* — Number of forecast days forward from today to display.
  - `--provider=<all|open_meteo|weatherapi>` *(default: `all`)* — Filter results to a single upstream provider or show both.
  - `--location=<id|name>` — Filter results to a specific location ID or name from the `locations` table.
- **Examples:**
  ```bash
  # View stored forecasts for the next 4 days across all locations and providers
  docker compose exec app php bin/query-forecasts.php --days=4

  # Inspect only Open-Meteo rows for location ID 1 over a 3-day window
  docker compose exec app php bin/query-forecasts.php --location=1 --provider=open_meteo --days=3
  ```

### 3. `bin/add-location.php` — Register a New Location
Inserts a new tracked city/coordinate pair into the `locations` table so future `fetch-forecasts.php` runs collect data for it automatically.

- **Arguments / options:**
  - `<name>` / `--name=<city>` — Human-readable location label (e.g., `"Heraklion"`).
  - `<latitude>` / `--lat=<float>` — Decimal latitude (`-90` to `90`).
  - `<longitude>` / `--lon=<float>` — Decimal longitude (`-180` to `180`).
  - `[timezone]` / `--tz=<IANA_TZ>` *(default: `UTC` or `TZ` from `.env`)* — Valid IANA timezone identifier (e.g., `Europe/Athens`) used for local day-boundary alignment.
  - `--help` — Displays exact usage syntax and supported flags.
- **Examples:**
  ```bash
  # Check exact CLI signature and flags
  docker compose exec app php bin/add-location.php --help

  # Register Heraklion with Europe/Athens local day alignment
  docker compose exec app php bin/add-location.php --name="Heraklion" --lat=35.3387 --lon=25.1442 --tz="Europe/Athens"
  ```

---

## 3. Implementation Choices

- **Framework & Dependencies:**
  - **Vanilla PHP 8.4 (strict types)** without a full-stack web framework to keep the CLI worker fast, transparent, and dependency-light.
  - **`guzzlehttp/guzzle`** for HTTP requests, timeouts, and structured error handling across external APIs.
  - **`vlucas/phpdotenv`** for `.env` loading in both CLI scripts and the web interface.
  - **Native `PDO` (`pdo_pgsql`)** for parameterized queries and explicit transactional upserts against **PostgreSQL 17**.
- **Data Normalization & Timezone Alignment:**
  - All timestamps are standardized to **UTC** (`TIMESTAMPTZ`), temperatures to **Celsius (°C)**, and precipitation to **millimeters (mm)** before persistence.
  - Because Open-Meteo and WeatherAPI define day boundaries differently (UTC day vs. location-local day), the fetcher queries `$days + 1` provider days and filters results to the exact target local calendar dates in `TZ` so both providers cover the same 24-hour local window.
  - **Provider Horizon Differences:** Open-Meteo returns the full `1`–`7` day window, whereas WeatherAPI's free tier caps daily forecasts at `3` days (today + 2 days ahead) even when `4`–`7` days are requested.
- **Idempotency & Quota Protection:**
  - `forecast_runs` logs every execution (`success` / `failed` + error details) and skips providers that already succeeded today (UTC) unless `--force` is passed.
  - `hourly_forecasts` and `daily_forecasts` enforce unique constraints (`(location_id, provider, valid_at)` and `(location_id, provider, forecast_date)`) with `ON CONFLICT ... DO UPDATE` upserts so re-runs update values cleanly without duplicates.
- **Browser Runner & Viewer (`public/index.php`):**
  - Combines an explicit **Forecast Collector Runner** panel at the top with a read-only PostgreSQL forecast viewer below.
  - Uses a Redirect/Flash flow after running `bin/fetch-forecasts.php --force --days=<1-7>` so normal page refreshes or filter changes never re-trigger external API calls, and displays command output only when execution fails (`exitCode !== 0`).

---

## 4. Incomplete Requirements

- **Automated scheduler / cron container:** Fetching is triggered manually via CLI or the web runner button; no background cron daemon or worker loop is bundled in `docker-compose.yml`.
- **Location management UI:** Adding or editing locations after initial `.sql` seeding is handled via CLI (`bin/add-location.php`) or SQL rather than a web form in `public/index.php`.
- **Automated test suite:** Unit and integration tests (PHPUnit with mocked Guzzle handlers) were omitted in favor of end-to-end verification against live provider payloads and PostgreSQL.

---

## 5. Next Steps

- **Automated Testing:** Add PHPUnit tests covering payload normalization, UTC/local boundary alignment, and repository upsert idempotency using mocked HTTP fixtures.
- **Scheduled Execution:** Add a lightweight cron/worker service (or host cron entry) to run `bin/fetch-forecasts.php` automatically on a daily schedule.
- **Location Management & Pagination:** Complete/extend `bin/add-location.php` for managing tracked coordinates and add pagination/date-picker controls in `public/index.php` for browsing multi-week history.
- **One estimate:** Gather feedback from end-users for temperature and precipitation to weigh providers (perhaps after adding a few more), producing a single weighted-average estimate based on each provider's absolute error against reported reality.

---

## 6. Additional Notes

- **Repository Layout:**
  ```text
  ├── bin/
  │   ├── add-location.php      # CLI helper for registering new locations
  │   ├── fetch-forecasts.php   # Main CLI ingestion & normalization runner
  │   └── query-forecasts.php   # CLI inspection helper for stored data
  ├── database/                 # PostgreSQL schema & initial location seed SQL
  ├── public/
  │   └── index.php             # Browser collector runner & forecast viewer (port 8081 -> 80)
  ├── src/
  │   ├── Config/               # PDO database connection factory
  │   ├── Forecast/             # Immutable value objects (NormalizedForecast, etc.)
  │   ├── Provider/             # WeatherProviderInterface, OpenMeteoClient, WeatherApiClient
  │   └── Storage/              # ForecastRepository (run tracking & transactional upserts)
  ├── docker-compose.yml
  ├── Dockerfile
  └── composer.json
  ```

- **Adding New Locations via SQL (Fallback):**
  ```sql
  INSERT INTO locations (name, latitude, longitude, timezone)
  VALUES ('Heraklion', 35.33870, 25.14420, 'Europe/Athens')
  ON CONFLICT DO NOTHING;
  ```
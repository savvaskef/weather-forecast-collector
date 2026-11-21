# Weather Forecast Collector

A Dockerized PHP 8.4 CLI application and lightweight read-only web viewer that fetches daily and hourly weather forecasts from **Open-Meteo** and **WeatherAPI.com**, normalizes units and timestamps, and persists them idempotently into **PostgreSQL 17**.

---

## 1. Installation and Setup

### Prerequisites
- Docker and Docker Compose (v2+)
- A free API key from [WeatherAPI.com](https://www.weatherapi.com/) *(Open-Meteo requires no API key)*

### Installation Steps
1. **Clone the repository and configure environment variables:**
   ```bash
   git clone <your-repo-url>
   cd weather-collector
   cp .env.example .env
   ```
2. **Database settings, timezone, and WeatherAPI key:**
   ```env
  DB_HOST=db
  DB_PORT=5432
  DB_NAME=weather_db
  DB_USER=weather_user
  DB_PASSWORD=the_passw

   WEATHERAPI_KEY=your_weatherapi_key_here
   TZ=Europe/Athens
   ```
   > **Optional location seeding:** You may optionally define `LOCATION_NAME`, `LOCATION_LAT`, `LOCATION_LON`, and `LOCATION_TIMEZONE` in `.env` to auto-seed a location on startup. Otherwise, locations are read directly from the `locations` table in PostgreSQL.

3. **Build and start the containers:**
   ```bash
   docker compose up -d --build
   ```
   *(The PostgreSQL schema initializes automatically on first boot via the mounted SQL migration/init scripts.)*

4. **Install PHP dependencies (if not already baked into the image):**
   ```bash
   docker compose exec app composer install
   ```

### Running the Project Locally

- **Fetch and store forecasts (defaults to 1 day aligned to local machine date):**
  ```bash
  docker compose exec app php bin/fetch-forecasts.php
  ```
- **CLI flags:**
  - `--force` — Bypass the once-per-UTC-day skip guard and re-fetch/upsert immediately.
  - `--days=N` — Fetch and align `N` local calendar days (`1` to `7`, default `1`).
  - `--quiet-forecast` — Suppress printing hourly/daily forecast rows to stdout.
  - `--print-hours=N` — Limit how many hourly rows are printed per provider.
- **Query stored forecasts via CLI:**
  ```bash
  docker compose exec app php bin/query-forecasts.php
  ```
- **Inspect stored forecasts in the browser:**
  ```bash
  docker compose exec app php -S 0.0.0.0:80 -t public
  ```
  Open `http://localhost:8081` (mapped via `8081:80` in `docker-compose.yml`).

---

## 2. Implementation Choices

- **Framework & Dependencies:**
  - **Vanilla PHP 8.4 (strict types)** without a full-stack web framework to keep the CLI worker fast, transparent, and dependency-light.
  - **`guzzlehttp/guzzle`** for HTTP requests, timeouts, and structured error handling across external APIs.
  - **`vlucas/phpdotenv`** for `.env` loading in both CLI scripts and the web viewer.
  - **Native `PDO` (`pdo_pgsql`)** for parameterized queries and explicit transactional upserts against **PostgreSQL 17**.
- **Data Normalization & Timezone Alignment:**
  - All timestamps are standardized to **UTC** (`TIMESTAMPTZ`), temperatures to **Celsius (°C)**, and precipitation to **millimeters (mm)** before persistence.
  - Because Open-Meteo and WeatherAPI define day boundaries differently (UTC day vs. location-local day), the fetcher queries `$days + 1` provider days and filters results to the exact target local calendar dates in `TZ` so both providers cover the same 24-hour local window.
- **Idempotency & Quota Protection:**
  - `forecast_runs` logs every execution (`success` / `failed` + error details) and skips providers that already succeeded today (UTC) unless `--force` is passed.
  - `hourly_forecasts` and `daily_forecasts` enforce unique constraints (`(location_id, provider, valid_at)` and `(location_id, provider, forecast_date)`) with `ON CONFLICT ... DO UPDATE` upserts so re-runs update values cleanly without duplicates.
- **Read-Only Browser Consumer (`public/index.php`):**
  - Kept strictly read-only against PostgreSQL so page refreshes never trigger slow external HTTP calls or exhaust free-tier API quotas.

---

## 3. Incomplete Requirements

- **Automated scheduler / cron container:** Fetching is triggered manually via CLI (`bin/fetch-forecasts.php`); no background cron daemon or worker loop is bundled in `docker-compose.yml`.
- **Location management UI/CLI:** Adding or editing locations after initial `.env` seeding is done directly via SQL in PostgreSQL rather than through a dedicated `add-location` command or web form.
- **Automated test suite:** Unit and integration tests (PHPUnit with mocked Guzzle handlers) were omitted in favor of end-to-end verification against live provider payloads and PostgreSQL.

---

## 4. Next Steps

- **Automated Testing:** Add PHPUnit tests covering payload normalization, UTC/local boundary alignment, and repository upsert idempotency using mocked HTTP fixtures.
- **Scheduled Execution:** Add a lightweight cron/worker service (or host cron entry) to run `bin/fetch-forecasts.php` automatically on a daily schedule.
- **Location Management & Pagination:** Add a CLI helper (`bin/add-location.php`) for managing tracked coordinates and pagination/date-picker controls in `public/index.php` for browsing multi-week history.
- **One estimate:** We could gather feed-back from the end-users for temperature and Precipitation to weigh providers(perhaps after adding a few more).We thus can get a single answer as a weighted average of estimated based on absolute error of the providers' estimate and reported 'reality'.

---

## 5. Additional Notes

- **Repository Layout:**
  ```text
  ├── bin/
  │   ├── fetch-forecasts.php   # Main CLI ingestion & normalization runner
  │   └── query-forecasts.php   # CLI inspection helper for stored data
  ├── database/                 # PostgreSQL schema initialization SQL
  ├── public/
  │   └── index.php             # Read-only browser viewer (port 8081 -> 80)
  ├── src/
  │   ├── Config/               # PDO database connection factory
  │   ├── Forecast/             # Immutable value objects (NormalizedForecast, etc.)
  │   ├── Provider/             # WeatherProviderInterface, OpenMeteoClient, WeatherApiClient
  │   └── Storage/              # ForecastRepository (run tracking & transactional upserts)
  ├── docker-compose.yml
  ├── Dockerfile
  └── composer.json
  ```
- **Adding New Locations via SQL:**
  ```sql
  INSERT INTO locations (name, latitude, longitude, timezone)
  VALUES  ('Heraklion', 35.33870, 25.14420, 'Europe/Athens')
  ON CONFLICT DO NOTHING;
  ```
CREATE TABLE IF NOT EXISTS locations (
    id SERIAL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    latitude NUMERIC(8, 5) NOT NULL,
    longitude NUMERIC(8, 5) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_locations_coords UNIQUE (latitude, longitude)
);

CREATE TABLE IF NOT EXISTS forecast_runs (
    id SERIAL PRIMARY KEY,
    location_id INT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    provider VARCHAR(32) NOT NULL CHECK (provider IN ('open_meteo', 'weatherapi')),
    status VARCHAR(16) NOT NULL CHECK (status IN ('success', 'partial', 'failed')),
    error_message TEXT NULL,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS hourly_forecasts (
    id BIGSERIAL PRIMARY KEY,
    location_id INT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    provider VARCHAR(32) NOT NULL CHECK (provider IN ('open_meteo', 'weatherapi')),
    run_id INT NULL REFERENCES forecast_runs(id) ON DELETE SET NULL,
    valid_at TIMESTAMPTZ NOT NULL,
    temperature_c NUMERIC(5, 2) NOT NULL,
    precipitation_mm NUMERIC(6, 2) NOT NULL DEFAULT 0.00,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_hourly_forecast UNIQUE (location_id, provider, valid_at)
);

CREATE TABLE IF NOT EXISTS daily_forecasts (
    id BIGSERIAL PRIMARY KEY,
    location_id INT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    provider VARCHAR(32) NOT NULL CHECK (provider IN ('open_meteo', 'weatherapi')),
    run_id INT NULL REFERENCES forecast_runs(id) ON DELETE SET NULL,
    forecast_date DATE NOT NULL,
    temp_min_c NUMERIC(5, 2) NULL,
    temp_max_c NUMERIC(5, 2) NULL,
    precipitation_mm NUMERIC(6, 2) NOT NULL DEFAULT 0.00,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_daily_forecast UNIQUE (location_id, provider, forecast_date)
);

CREATE INDEX IF NOT EXISTS idx_forecast_runs_loc_provider_time
    ON forecast_runs (location_id, provider, fetched_at DESC);

CREATE INDEX IF NOT EXISTS idx_hourly_forecasts_lookup
    ON hourly_forecasts (location_id, valid_at);

CREATE INDEX IF NOT EXISTS idx_daily_forecasts_lookup
    ON daily_forecasts (location_id, forecast_date);
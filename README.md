# air_quality

Home Assignment from Evolution — a small PHP web service and UI that ranks Bulgarian cities by average PM2.5.

1. The user picks a historical time period (from – to, using a calendar).
2. On a button press, the app shows the city with the cleanest air over that period,
measured by average PM2.5 (µg/m³). Below it, show a ranking of all the cities.
3. Use real, publicly available data. Finding the source is up to you; public sensor
networks are a good place to start. It must be reachable through a public API, open
downloads or free registration, and its terms must allow your use.
4. Your app collects the data, stores it locally and keeps it up to date. It must not
call the third party on each user request.
5. Store at least the last 6 months of history. Any period within it can be queried.
6. Invalid or impossible requests get a clear, useful error.

## Run with Docker

```bash
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080). The first start downloads the last 6 months of EEA data (about 20–30 seconds).

## API

User requests never call EEA. They read SQLite.

- `GET /api/status` — local data window and last download
- `GET /api/ranking?from=YYYY-MM-DD&to=YYYY-MM-DD` — cleanest city and ranking

Example:

```bash
curl 'http://localhost:8080/api/ranking?from=2026-08-01&to=2026-08-31'
```

## Data source

Hourly PM2.5 from the [EEA air quality download service](https://www.eea.europa.eu/en/datahub/datahubitem-view/778ef9f5-6293-4846-badd-56a29c70880d) (Up-To-Date / E2a), for Sofia, Plovdiv, Varna, Burgas and Ruse.

City averages use every valid hourly sample from stations that EEA maps to that city. Values with EEA validity flags 1–3 are kept; negatives and missing values are dropped.

A city is listed as not ranked when EEA currently has no valid PM2.5 for that city in the selected period. As of late 2026, EEA UTD PM2.5 is available for Sofia, Varna and Ruse; Plovdiv and Burgas have other pollutants (or only older verified files), but no current hourly PM2.5 in this service.

Downloads repeat every 6 hours inside the container. History older than 6 months is pruned.

### Completeness check and fallback sources

Sources are ranked: **EEA → OpenAQ → sensor.community**.

After each EEA download, the app measures **coverage** per city: the share of hours in the 6-month window with at least one valid sample, plus the longest gap. A fallback source is consulted for a city only when the sources ranked above it cover less than 75% of the hours. It downloads PM2.5 sensors near the city centre and stores their hourly means. Only hours after the last stored one are requested on later runs.

- For each city-hour only the highest-priority source with data is used, so a station published by several sources is never counted twice and lower-quality sources only fill gaps.
- **OpenAQ** (sensors within 20 km): hourly means via `/v3/sensors/{id}/hours`; hours built from less than 50% of expected readings, or flagged, are dropped. Needs a free API key (https://explore.openaq.org → account settings) in `.env` as `OPENAQ_API_KEY=...`; without it OpenAQ is skipped. Rate-limited to 60 requests/minute.
- **sensor.community** (up to 10 closest outdoor sensors within 10 km): no key needed. Sensors are found via the live API and history is read from the daily per-sensor CSV archive (UTC, readings every ~2.5 min), averaged per hour; hours with fewer than 6 readings are dropped. Data is under the [ODbL](https://opendatacommons.org/licenses/odbl/). Disable with `SENSOR_COMMUNITY_ENABLED=false`.
- `/api/ranking` reports `coverage` (hours covered/expected, percent, hours per source) and `sources` for each city. The UI warns when a city is below 75%.

As of late 2026, Plovdiv is filled from an OpenAQ senstate sensor and Burgas from sensor.community. Both are low-cost sensors, which are less accurate than the EEA reference stations.

The first download runs in the background, so the web server starts right away. The sensor.community history takes a minute or two.

## Layout

```
bin/ingest.php      download EEA parquet (+ OpenAQ / sensor.community fallbacks) → SQLite
bin/scheduler.php   refresh on an interval
public/index.php    web UI + JSON API
src/                PHP application code
```

# f1.markuska.cz

Plaintext F1 stats — Formula 1 results, schedule and standings, in the spirit of [plaintextsports.com](https://plaintextsports.com/).

🌐 **Live:** https://f1.markuska.cz

## Stack

- **Nette 3.2** (PHP 8.5+) — application framework, routing, DI, Latte templates
- **Tailwind CSS v4** + Vite — utility-first styling, single CSS bundle
- **MariaDB / SQLite** — Nette\Database. MariaDB on production, SQLite locally
- **OpenF1 API** — race data source (https://openf1.org/)

## Project layout

```
f1/
├── app/
│   ├── Bootstrap.php
│   ├── Core/RouterFactory.php
│   ├── Presentation/
│   │   ├── @layout.latte          # shell with Tailwind classes
│   │   ├── BasePresenter.php       # injects F1Repository + last-sync time
│   │   ├── Home/                   # season overview + last winner
│   │   ├── Race/                   # one meeting detail (all sessions)
│   │   └── Standings/              # driver + constructor championship
│   ├── Repositories/F1Repository.php   # all DB queries
│   └── Services/OpenF1Client.php       # HTTP client, used only by sync
├── assets/
│   ├── main.css                    # Tailwind entry + theme tokens
│   └── main.js                     # imports CSS
├── bin/
│   ├── sync-f1.php                 # cron job (every 15 min on prod)
│   └── warm-winners.php            # legacy cache-warmer (kept for ref)
├── config/
│   ├── common.neon                 # DB, assets, services
│   ├── services.neon
│   └── local.neon                  # NOT in git — prod-specific overrides
├── db/schema.sql                   # tables for meetings/sessions/drivers/results
├── www/                            # webroot — index.php, favicon.svg, /assets/
└── package.json / vite.config.ts
```

## Data flow

```
                    cron (every 15 min)
   OpenF1 API ─────► bin/sync-f1.php ─────► MariaDB
                                              │
                                              ▼
                                      F1Repository
                                              │
                                              ▼
              HomePresenter / RacePresenter / StandingsPresenter
                                              │
                                              ▼
                                       Latte templates
```

**Page requests never call OpenF1 directly.** They read from DB → fast load (~150 ms).

## Local dev

```bash
# 1. Dependencies
composer install
npm ci

# 2. Build assets
npm run build       # or: npm run dev for HMR

# 3. Pull data into local SQLite (db.sqlite)
php bin/sync-f1.php

# 4. Open in browser
```

Project is linked in Herd from `www/` subdirectory → http://f1.test.

## Routes

| URL | Presenter |
|---|---|
| `/` | Home — season overview, next event, last winner |
| `/race/<meetingKey>` | Race — all sessions of one meeting with results |
| `/standings` | Standings — driver + constructor championship |

## OpenF1 caveats

- **Rate limit:** 3 requests/second. OpenF1Client throttles to ~350 ms between calls.
- **Partial responses:** OpenF1 occasionally returns empty arrays for valid queries. Sync script retries up to 3× per endpoint; the cron will fill remaining gaps on subsequent runs.
- **Endpoints used:**
  - `/v1/meetings` — race weekends
  - `/v1/sessions` — Practice/Qualifying/Sprint/Race within a meeting
  - `/v1/drivers` — driver lineup per session
  - `/v1/session_result` — final classification (position, points, laps, DNF/DNS/DSQ)

## Roadmap / ideas

- [ ] Driver detail page (`/driver/<number>`) — season stats, race-by-race results
- [ ] Constructor detail page
- [ ] Live timing widget during race weekends (auto-refresh while session is active)
- [ ] Sprint points separated from main race points in standings
- [ ] Fastest lap / pole position bonus tracking
- [ ] Historical seasons selector
- [ ] RSS feed of race results

## License

Public domain. Data © Formula 1, fetched via openf1.org (free, unaffiliated).

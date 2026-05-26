# f1.markuska.cz

Plaintext F1 stats — Formula 1 results, schedule and standings, in the spirit of [plaintextsports.com](https://plaintextsports.com/).

🌐 **Live:** https://f1.markuska.cz

## Stack

- **Nette 3.2** (PHP 8.5+) — application framework, routing, DI, Latte templates
- **Tailwind CSS v4** + Vite — utility-first styling, single CSS bundle
- **MariaDB / SQLite** — Nette\Database. MariaDB on production, SQLite locally
- **Jolpica-F1 API** — race data source (https://api.jolpi.ca/ergast/), the community successor to Ergast

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
│   └── Services/JolpicaClient.php      # HTTP client, used only by sync
├── assets/
│   ├── main.css                    # Tailwind entry + theme tokens
│   └── main.js                     # imports CSS
├── bin/
│   ├── sync-f1.php                 # cron job (every 15 min on prod)
│   └── test-telegram.php           # manual Telegram notification test
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
 Jolpica-F1 API ───► bin/sync-f1.php ─────► MariaDB
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

**Page requests never call the API directly.** They read from DB → fast load (~150 ms).

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
| `/` · `/season/<year>` | Home — season overview, next event + countdown, last winner |
| `/race/<meetingKey>` | Race — all sessions of one meeting with results |
| `/standings` · `/standings/<year>` | Standings — driver + constructor championship |
| `/driver/<number>[/<year>]` | Driver — season stats + race-by-race results |
| `/constructor/<slug>[/<year>]` | Constructor — drivers + per-round results |

## Jolpica-F1 caveats

- **Rate limit:** community API (~4 req/s burst, 500/h sustained for anon). JolpicaClient throttles to ~300 ms between calls; a full-season sync is well under the cap.
- **Result lag:** classifications usually appear within ~1h of a session. The cron fills gaps on subsequent runs.
- **No telemetry:** Ergast-style data only (results/standings) — no live timing, weather or race-control feeds.
- **Synthetic keys:** Ergast has no numeric meeting/session keys, so the sync derives them: `meeting_key = year*100 + round`, `session_key = meeting_key*10 + offset` (Race 0, Qualifying 1, Sprint 2, Sprint Q 3, FP1–3 4–6). Sprint is stored as `session_type='Race'` so its points feed standings.
- **Endpoints used:**
  - `/{year}/races` — schedule + per-weekend session times
  - `/{year}/{round}/results` — race classification
  - `/{year}/{round}/sprint` — sprint classification
  - `/{year}/{round}/qualifying` — qualifying classification

## Roadmap / ideas

- [x] Driver detail page (`/driver/<number>`) — season stats, race-by-race results
- [x] Constructor detail page
- [x] Historical seasons selector
- [ ] Live timing widget during race weekends (auto-refresh while session is active)
- [ ] Sprint points separated from main race points in standings
- [ ] Fastest lap / pole position bonus tracking
- [ ] RSS feed of race results

## License

Public domain. Data © Formula 1, fetched via the Jolpica-F1 API (free, unaffiliated).

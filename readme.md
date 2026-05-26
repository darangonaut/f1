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
| `/` | Home — season overview, next event, last winner |
| `/race/<meetingKey>` | Race — all sessions of one meeting with results |
| `/standings` | Standings — driver + constructor championship |

## Deployment to f1.markuska.cz

Server: Oracle Cloud Always Free VM (Ubuntu 24.04, shared with markuska.cz + weather.markuska.cz).

| | |
|---|---|
| **Public IP** | `129.159.241.184` |
| **SSH** | `ssh -i ~/.ssh/oracle-renovo.key ubuntu@129.159.241.184` |
| **App root** | `/var/www/f1/app` |
| **Web user** | `f1` (own UID, own PHP-FPM pool `f1`) |
| **DB** | MariaDB 10.11, database `f1`, user `f1`, host `127.0.0.1` |
| **PHP-FPM pool** | `/etc/php/8.5/fpm/pool.d/f1.conf` (socket `/run/php/php8.5-fpm-f1.sock`) |
| **Nginx vhost** | `/etc/nginx/sites-available/f1.markuska.cz` |
| **SSL** | Let's Encrypt, auto-renew via certbot |
| **Cron sync** | `/etc/cron.d/f1-sync` runs `bin/sync-f1.php` every 15 min |
| **Sync log** | `/var/log/f1-sync.log` |

### Production .env equivalent

Production-only overrides live in `config/local.neon` (gitignored). The file is loaded by `Bootstrap::setupContainer` if present:

```neon
database:
    dsn: 'mysql:host=127.0.0.1;dbname=f1;charset=utf8mb4'
    user: f1
    password: ...
```

### Deploy a code change

```bash
# Local
git add . && git commit -m "..." && git push

# On server
ssh -i ~/.ssh/oracle-renovo.key ubuntu@129.159.241.184
cd /var/www/f1/app
sudo -u f1 git -c credential.helper= pull
sudo -u f1 composer install --no-dev --optimize-autoloader     # if composer.json changed
sudo -u f1 npm ci && sudo -u f1 npm run build                  # if assets changed
sudo -u f1 find temp -type f -delete                           # clear cache
```

### Database operations

```bash
# Inspect data
sudo mariadb f1 -e "SELECT meeting_name, date_start FROM meetings WHERE year=2026 ORDER BY date_start"

# Trigger sync manually
sudo -u f1 php /var/www/f1/app/bin/sync-f1.php

# View sync log
sudo tail -f /var/log/f1-sync.log

# Verify cron is running
sudo systemctl is-active cron
sudo grep CRON /var/log/syslog | tail
```

### Reset / re-sync

Drop + recreate database, then run sync:

```bash
sudo mariadb -e "DROP DATABASE f1; CREATE DATABASE f1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb -e "GRANT ALL PRIVILEGES ON f1.* TO 'f1'@'localhost'; FLUSH PRIVILEGES;"
sudo -u f1 php /var/www/f1/app/bin/sync-f1.php
```

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

- [ ] Driver detail page (`/driver/<number>`) — season stats, race-by-race results
- [ ] Constructor detail page
- [ ] Live timing widget during race weekends (auto-refresh while session is active)
- [ ] Sprint points separated from main race points in standings
- [ ] Fastest lap / pole position bonus tracking
- [ ] Historical seasons selector
- [ ] RSS feed of race results

## License

Public domain. Data © Formula 1, fetched via the Jolpica-F1 API (free, unaffiliated).

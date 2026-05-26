<?php
// Sync F1 data from Jolpica-F1 (Ergast successor) → local DB. Run from cron in production.
//   php bin/sync-f1.php                # current year
//   php bin/sync-f1.php 2025           # specific year

declare(strict_types=1);

date_default_timezone_set('Europe/Bratislava');

require __DIR__ . '/../vendor/autoload.php';

$configurator = (new \Nette\Bootstrap\Configurator())
    ->setDebugMode(false)
    ->setTempDirectory(__DIR__ . '/../temp');
$configurator->addConfig(__DIR__ . '/../config/common.neon');
if (is_file(__DIR__ . '/../config/local.neon')) {
    $configurator->addConfig(__DIR__ . '/../config/local.neon');
}
$container = $configurator->createContainer();

/** @var \Nette\Database\Explorer $db */
$db = $container->getByType(\Nette\Database\Explorer::class);

/** @var \App\Services\JolpicaClient $api */
$api = $container->getByType(\App\Services\JolpicaClient::class);

/** @var \App\Services\TelegramNotifier $telegram */
$telegram = $container->getByType(\App\Services\TelegramNotifier::class);

$newRaceWins = [];   // collected so we can fire one notif per Race

$schemaSql = file_get_contents(__DIR__ . '/../db/schema.sql');
foreach (preg_split('/;\s*$/m', $schemaSql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        $db->query($stmt);
    }
}

$year = isset($argv[1]) ? (int) $argv[1] : (int) date('Y');
echo "[sync] year={$year}\n";
$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
$nowDt = new \DateTimeImmutable();

// 1) Schedule
$races = $api->getSchedule($year);
if (empty($races)) {
    echo "[sync] no races for $year, trying previous year\n";
    $year--;
    $races = $api->getSchedule($year);
}
echo "[sync] fetched " . count($races) . " races\n";

// session sub-object => [session_key offset, session_type, session_name, duration minutes]
// Sprint is stored as session_type 'Race' so its points feed driver/constructor standings.
$sessionSpecs = [
    'Race'             => [0, 'Race', 'Race', 180],
    'Qualifying'       => [1, 'Qualifying', 'Qualifying', 60],
    'Sprint'           => [2, 'Race', 'Sprint', 60],
    'SprintQualifying' => [3, 'Qualifying', 'Sprint Qualifying', 45],
    'SprintShootout'   => [3, 'Qualifying', 'Sprint Shootout', 45],
    'FirstPractice'    => [4, 'Practice', 'Practice 1', 60],
    'SecondPractice'   => [5, 'Practice', 'Practice 2', 60],
    'ThirdPractice'    => [6, 'Practice', 'Practice 3', 60],
];

foreach ($races as $race) {
    $round = (int) $race['round'];
    $meetingKey = $year * 100 + $round;

    // Collect this round's sessions with absolute start/end datetimes.
    $sessions = [];   // ergastKey => ['start' => DateTimeImmutable, 'end' => DateTimeImmutable]
    foreach ($sessionSpecs as $ergastKey => [$offset, $type, $sname, $durMin]) {
        $node = $ergastKey === 'Race'
            ? ['date' => $race['date'] ?? null, 'time' => $race['time'] ?? null]
            : ($race[$ergastKey] ?? null);
        if ($node === null || empty($node['date'])) {
            continue;
        }
        $start = new \DateTimeImmutable(($node['date']) . 'T' . ($node['time'] ?? '00:00:00Z'));
        $sessions[$ergastKey] = [
            'session_key' => $meetingKey * 10 + $offset,
            'type' => $type,
            'name' => $sname,
            'start' => $start,
            'end' => $start->modify("+{$durMin} minutes"),
        ];
    }

    if (empty($sessions)) {
        continue;
    }

    // Meeting start = earliest session of the weekend (matches the "weekend start" label).
    $weekendStart = null;
    foreach ($sessions as $s) {
        if ($weekendStart === null || $s['start'] < $weekendStart) {
            $weekendStart = $s['start'];
        }
    }

    $loc = $race['Circuit']['Location'] ?? [];
    $country = $loc['country'] ?? null;
    upsert($db, 'meetings', 'meeting_key', [
        'meeting_key' => $meetingKey,
        'year' => $year,
        'meeting_name' => $race['raceName'] ?? null,
        'meeting_official_name' => $race['raceName'] ?? null,
        'country_code' => countryCode($country),
        'country_name' => $country,
        'location' => $loc['locality'] ?? null,
        'circuit_short_name' => $loc['locality'] ?? null,
        'date_start' => $weekendStart->format(DATE_ATOM),
        'fetched_at' => $now,
    ]);

    foreach ($sessions as $s) {
        upsert($db, 'sessions', 'session_key', [
            'session_key' => $s['session_key'],
            'meeting_key' => $meetingKey,
            'session_name' => $s['name'],
            'session_type' => $s['type'],
            'date_start' => $s['start']->format(DATE_ATOM),
            'date_end' => $s['end']->format(DATE_ATOM),
            'fetched_at' => $now,
        ]);
    }

    // Results: only for ended classified sessions (Race, Sprint, Qualifying).
    foreach (['Race', 'Sprint', 'Qualifying'] as $ergastKey) {
        if (!isset($sessions[$ergastKey]) || $sessions[$ergastKey]['end'] > $nowDt) {
            continue;
        }
        $sk = $sessions[$ergastKey]['session_key'];
        if ((int) $db->fetchField('SELECT COUNT(*) FROM session_results WHERE session_key = ?', $sk) > 0) {
            continue;
        }

        $rows = match ($ergastKey) {
            'Race' => $api->getRaceResults($year, $round),
            'Sprint' => $api->getSprintResults($year, $round),
            'Qualifying' => $api->getQualifyingResults($year, $round),
        };
        if (empty($rows)) {
            echo "  round $round {$sessions[$ergastKey]['name']}: no results yet\n";
            continue;
        }

        $mapped = [];
        foreach ($rows as $r) {
            $driverNumber = (int) ($r['number'] ?? $r['Driver']['permanentNumber'] ?? 0);
            $row = mapResultRow($r, $driverNumber, $now);
            $mapped[] = $row;
            upsertComposite($db, 'session_results', ['session_key', 'driver_number'], [
                'session_key' => $sk,
                'driver_number' => $driverNumber,
                'position' => $row['position'],
                'points' => $row['points'],
                'number_of_laps' => $row['number_of_laps'],
                'duration' => $row['duration'],
                'gap_to_leader' => null,
                'dnf' => $row['dnf'],
                'dns' => $row['dns'],
                'dsq' => $row['dsq'],
                'fetched_at' => $now,
            ]);
            upsertComposite($db, 'drivers', ['driver_number', 'year'], [
                'driver_number' => $driverNumber,
                'year' => $year,
                'full_name' => trim(($r['Driver']['givenName'] ?? '') . ' ' . ($r['Driver']['familyName'] ?? '')),
                'broadcast_name' => null,
                'name_acronym' => $r['Driver']['code'] ?? null,
                'team_name' => $r['Constructor']['name'] ?? null,
                'team_colour' => null,
                'country_code' => null,
                'headshot_url' => null,
                'fetched_at' => $now,
            ]);
        }
        echo "  round $round {$sessions[$ergastKey]['name']}: " . count($mapped) . " results\n";

        if ($ergastKey === 'Race') {
            $newRaceWins[] = ['meeting' => [
                'meeting_key' => $meetingKey,
                'year' => $year,
                'meeting_name' => $race['raceName'] ?? 'Race',
            ], 'results' => $mapped];
        }
    }
}

$counts = [
    'meetings' => $db->fetchField('SELECT COUNT(*) FROM meetings WHERE year = ?', $year),
    'sessions' => $db->fetchField('SELECT COUNT(*) FROM sessions s JOIN meetings m ON s.meeting_key = m.meeting_key WHERE m.year = ?', $year),
    'session_results' => $db->fetchField('SELECT COUNT(*) FROM session_results sr JOIN sessions s ON sr.session_key = s.session_key JOIN meetings m ON s.meeting_key = m.meeting_key WHERE m.year = ?', $year),
    'drivers' => $db->fetchField('SELECT COUNT(*) FROM drivers WHERE year = ?', $year),
];
echo "[sync] done — " . json_encode($counts) . "\n";


// Telegram notifications for newly imported Race results
if (!empty($newRaceWins) && $telegram->isConfigured()) {
    foreach ($newRaceWins as $event) {
        $m = $event['meeting'];
        $rows = array_filter($event['results'], fn($r) => $r['position'] !== null);
        usort($rows, fn($a, $b) => $a['position'] <=> $b['position']);
        $top3 = array_slice($rows, 0, 3);

        $drivers = [];
        foreach ($db->fetchAll('SELECT driver_number, full_name, team_name FROM drivers WHERE year = ?', (int) $m['year']) as $d) {
            $drivers[(int) $d->driver_number] = (array) $d;
        }

        $title = $m['meeting_name'];
        $esc = fn($s) => \App\Services\TelegramNotifier::escape((string) $s);
        $podiumLines = [];
        $medals = ['🥇', '🥈', '🥉'];
        foreach ($top3 as $i => $r) {
            $dn = (int) $r['driver_number'];
            $name = $drivers[$dn]['full_name'] ?? "#$dn";
            $team = $drivers[$dn]['team_name'] ?? '';
            $pts = $r['points'] !== null ? ' \\(' . (int) $r['points'] . ' pts\\)' : '';
            $podiumLines[] = ($medals[$i] ?? ($i + 1) . '.') . ' ' . $esc($name) . ($team ? ' — ' . $esc($team) : '') . $pts;
        }
        $url = 'https://f1\\.markuska\\.cz/race/' . (int) $m['meeting_key'];

        $text = "🏁 *" . $esc($title) . "*\n\n"
            . implode("\n", $podiumLines)
            . "\n\n[Full results]($url)";

        $ok = $telegram->send($text);
        echo "[sync] telegram: $title — " . ($ok ? 'sent' : 'FAILED') . "\n";
    }
} elseif (!empty($newRaceWins)) {
    echo "[sync] telegram: skipped (not configured)\n";
}


/** Map one Ergast result row to our session_results columns. positionText carries the classification code. */
function mapResultRow(array $r, int $driverNumber, string $now): array
{
    $posText = $r['positionText'] ?? (string) ($r['position'] ?? '');
    $status = $r['status'] ?? '';

    $dsq = in_array($posText, ['D', 'E'], true) || $status === 'Disqualified' ? 1 : 0;
    $dns = (in_array($posText, ['W', 'F'], true) || $status === 'Did not start') ? 1 : 0;
    $dnf = (!$dsq && !$dns && in_array($posText, ['R', 'N'], true)) ? 1 : 0;

    return [
        'position' => isset($r['position']) ? (int) $r['position'] : null,
        'points' => isset($r['points']) ? (float) $r['points'] : null,
        'number_of_laps' => isset($r['laps']) ? (int) $r['laps'] : null,
        'duration' => isset($r['Time']['millis']) ? ((float) $r['Time']['millis']) / 1000 : null,
        'driver_number' => $driverNumber,
        'dnf' => $dnf,
        'dns' => $dns,
        'dsq' => $dsq,
    ];
}

/** 3-letter code for the country badge. Ergast gives only the country name. */
function countryCode(?string $country): ?string
{
    if ($country === null) {
        return null;
    }
    static $map = [
        'Australia' => 'AUS', 'China' => 'CHN', 'Japan' => 'JPN', 'Bahrain' => 'BHR',
        'Saudi Arabia' => 'SAU', 'USA' => 'USA', 'United States' => 'USA', 'UAE' => 'UAE',
        'United Arab Emirates' => 'UAE', 'Italy' => 'ITA', 'Monaco' => 'MON', 'Canada' => 'CAN',
        'Spain' => 'ESP', 'Austria' => 'AUT', 'UK' => 'GBR', 'United Kingdom' => 'GBR',
        'Hungary' => 'HUN', 'Belgium' => 'BEL', 'Netherlands' => 'NED', 'Azerbaijan' => 'AZE',
        'Singapore' => 'SGP', 'Mexico' => 'MEX', 'Brazil' => 'BRA', 'Qatar' => 'QAT',
        'France' => 'FRA', 'Portugal' => 'POR', 'Turkey' => 'TUR', 'Germany' => 'GER',
        'Russia' => 'RUS', 'Vietnam' => 'VIE',
    ];
    return $map[$country] ?? strtoupper(substr($country, 0, 3));
}

function upsert(\Nette\Database\Explorer $db, string $table, string $pkColumn, array $row): void
{
    $existing = $db->fetchField("SELECT $pkColumn FROM $table WHERE $pkColumn = ?", $row[$pkColumn]);
    if ($existing) {
        $db->table($table)->where($pkColumn, $row[$pkColumn])->update($row);
    } else {
        $db->table($table)->insert($row);
    }
}

function upsertComposite(\Nette\Database\Explorer $db, string $table, array $pkColumns, array $row): void
{
    $where = [];
    foreach ($pkColumns as $col) {
        $where[$col] = $row[$col];
    }
    $existing = $db->table($table)->where($where)->fetch();
    if ($existing) {
        $db->table($table)->where($where)->update($row);
    } else {
        $db->table($table)->insert($row);
    }
}

<?php
// Sync F1 data from OpenF1 → local DB. Run from cron in production.
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

/** @var \App\Services\OpenF1Client $api */
$api = $container->getByType(\App\Services\OpenF1Client::class);

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

// 1) Meetings
$meetings = $api->getMeetings($year);
if (empty($meetings)) {
    echo "[sync] no meetings for $year, trying previous year\n";
    $year--;
    $meetings = $api->getMeetings($year);
}
echo "[sync] fetched " . count($meetings) . " meetings\n";

foreach ($meetings as $m) {
    upsert($db, 'meetings', 'meeting_key', [
        'meeting_key' => (int) $m['meeting_key'],
        'year' => (int) ($m['year'] ?? $year),
        'meeting_name' => $m['meeting_name'] ?? null,
        'meeting_official_name' => $m['meeting_official_name'] ?? null,
        'country_code' => $m['country_code'] ?? null,
        'country_name' => $m['country_name'] ?? null,
        'location' => $m['location'] ?? null,
        'circuit_short_name' => $m['circuit_short_name'] ?? null,
        'date_start' => $m['date_start'],
        'fetched_at' => $now,
    ]);
}

// 2) Sessions + results for past meetings only
$nowDt = new \DateTimeImmutable();
foreach ($meetings as $m) {
    $startDt = new \DateTimeImmutable($m['date_start']);
    if ($startDt->modify('+5 days') > $nowDt) {
        // weekend not finished yet; skip results — sessions still synced for future visibility
    }

    $mk = (int) $m['meeting_key'];
    $sessions = $api->getSessions($mk);
    foreach ($sessions as $s) {
        upsert($db, 'sessions', 'session_key', [
            'session_key' => (int) $s['session_key'],
            'meeting_key' => $mk,
            'session_name' => $s['session_name'] ?? null,
            'session_type' => $s['session_type'] ?? null,
            'date_start' => $s['date_start'] ?? null,
            'date_end' => $s['date_end'] ?? null,
            'fetched_at' => $now,
        ]);
    }

    // Results: only for sessions that have ended
    foreach ($sessions as $s) {
        if (empty($s['date_end']) || (new \DateTimeImmutable($s['date_end'])) > $nowDt) {
            continue;
        }
        $sk = (int) $s['session_key'];
        $existing = $db->fetchField('SELECT COUNT(*) FROM session_results WHERE session_key = ?', $sk);
        if ($existing > 0) {
            continue;
        }
        $results = $api->getSessionResult($sk);
        if (empty($results)) {
            echo "  session $sk ({$s['session_name']}): no results yet\n";
            continue;
        }
        foreach ($results as $r) {
            upsertComposite($db, 'session_results', ['session_key', 'driver_number'], [
                'session_key' => $sk,
                'driver_number' => (int) $r['driver_number'],
                'position' => isset($r['position']) ? (int) $r['position'] : null,
                'points' => isset($r['points']) ? (float) $r['points'] : null,
                'number_of_laps' => isset($r['number_of_laps']) ? (int) $r['number_of_laps'] : null,
                'duration' => isset($r['duration']) ? (float) $r['duration'] : null,
                'gap_to_leader' => isset($r['gap_to_leader']) ? (float) $r['gap_to_leader'] : null,
                'dnf' => !empty($r['dnf']) ? 1 : 0,
                'dns' => !empty($r['dns']) ? 1 : 0,
                'dsq' => !empty($r['dsq']) ? 1 : 0,
                'fetched_at' => $now,
            ]);
        }
        echo "  session $sk ({$s['session_name']}): " . count($results) . " results\n";
    }
}

// 3) Drivers for the year (try sessions until we have a complete list)
$haveNumbers = array_column(
    $db->fetchAll('SELECT driver_number FROM drivers WHERE year = ?', $year),
    'driver_number',
);
$haveSet = array_flip($haveNumbers);
$pastSessions = $db->fetchAll(
    'SELECT s.session_key FROM sessions s JOIN meetings m ON m.meeting_key = s.meeting_key
     WHERE m.year = ? AND s.date_end IS NOT NULL AND s.date_end < ?
     ORDER BY s.date_end DESC LIMIT 30',
    $year,
    $nowDt->format(DATE_ATOM),
);
foreach ($pastSessions as $row) {
    if (count($haveSet) >= 22) {
        break;
    }
    $drivers = $api->getDrivers((int) $row->session_key);
    foreach ($drivers as $d) {
        if (isset($haveSet[$d['driver_number']])) {
            continue;
        }
        upsertComposite($db, 'drivers', ['driver_number', 'year'], [
            'driver_number' => (int) $d['driver_number'],
            'year' => $year,
            'full_name' => $d['full_name'] ?? null,
            'broadcast_name' => $d['broadcast_name'] ?? null,
            'name_acronym' => $d['name_acronym'] ?? null,
            'team_name' => $d['team_name'] ?? null,
            'team_colour' => $d['team_colour'] ?? null,
            'country_code' => $d['country_code'] ?? null,
            'headshot_url' => $d['headshot_url'] ?? null,
            'fetched_at' => $now,
        ]);
        $haveSet[$d['driver_number']] = true;
    }
}
echo "[sync] drivers cached: " . count($haveSet) . "\n";

$counts = [
    'meetings' => $db->fetchField('SELECT COUNT(*) FROM meetings WHERE year = ?', $year),
    'sessions' => $db->fetchField('SELECT COUNT(*) FROM sessions s JOIN meetings m ON s.meeting_key = m.meeting_key WHERE m.year = ?', $year),
    'session_results' => $db->fetchField('SELECT COUNT(*) FROM session_results sr JOIN sessions s ON sr.session_key = s.session_key JOIN meetings m ON s.meeting_key = m.meeting_key WHERE m.year = ?', $year),
    'drivers' => $db->fetchField('SELECT COUNT(*) FROM drivers WHERE year = ?', $year),
];
echo "[sync] done — " . json_encode($counts) . "\n";


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

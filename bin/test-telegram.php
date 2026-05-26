<?php
// Send a Telegram test message based on the most recent completed Race.
//   php bin/test-telegram.php

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

$db = $container->getByType(\Nette\Database\Explorer::class);
$tg = $container->getByType(\App\Services\TelegramNotifier::class);

if (!$tg->isConfigured()) {
    echo "Telegram not configured (botToken/chatId missing).\n";
    exit(1);
}

$row = $db->fetch(
    "SELECT m.meeting_name, m.meeting_key, m.year, s.session_key
     FROM meetings m JOIN sessions s ON s.meeting_key = m.meeting_key
     WHERE s.session_type='Race' AND s.session_name='Race'
       AND EXISTS (SELECT 1 FROM session_results sr WHERE sr.session_key = s.session_key)
     ORDER BY s.date_end DESC LIMIT 1",
);

if (!$row) {
    echo "No completed Race found in DB.\n";
    exit(1);
}

$results = $db->fetchAll(
    "SELECT sr.*, d.full_name, d.team_name
     FROM session_results sr
     LEFT JOIN drivers d ON d.driver_id = sr.driver_id AND d.year = ?
     WHERE sr.session_key = ? AND sr.position IS NOT NULL ORDER BY sr.position LIMIT 3",
    $row->year, $row->session_key,
);

$esc = fn($s) => \App\Services\TelegramNotifier::escape((string) $s);
$medals = ['🥇', '🥈', '🥉'];
$lines = [];
foreach ($results as $i => $r) {
    $pts = isset($r->points) ? ' \\(' . (int) $r->points . ' pts\\)' : '';
    $lines[] = ($medals[$i] ?? ($i + 1) . '.') . ' ' . $esc($r->full_name ?? $r->driver_id) . ' — ' . $esc($r->team_name ?? '') . $pts;
}
$url = 'https://f1\\.markuska\\.cz/race/' . $row->meeting_key;
$text = "🧪 TEST notifikácie\n\n🏁 *" . $esc($row->meeting_name) . "*\n\n"
    . implode("\n", $lines) . "\n\n[Full results]($url)";

echo "Sending test for: $row->meeting_name\n";
$ok = $tg->send($text);
echo $ok ? "OK — message sent.\n" : "FAILED.\n";

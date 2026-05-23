<?php
// Pre-fetch winners for all past meetings into cache.
// Run hourly via cron in production, or manually during local dev:
//   php bin/warm-winners.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$container = (new \Nette\Bootstrap\Configurator())
    ->setDebugMode(false)
    ->setTempDirectory(__DIR__ . '/../temp')
    ->addConfig(__DIR__ . '/../config/common.neon')
    ->createContainer();

/** @var \App\Services\OpenF1Client $cli */
$cli = $container->getByType(\App\Services\OpenF1Client::class);

$year = (int) date('Y');
$meetings = $cli->getMeetings($year);
if (empty($meetings)) {
    $year--;
    $meetings = $cli->getMeetings($year);
}

$now = new \DateTimeImmutable();
$past = array_filter(
    $meetings,
    fn($m) => (new \DateTimeImmutable($m['date_start'])) < $now,
);

echo "Warming winners for {$year} season — " . count($past) . " past meetings\n";

$ok = 0;
$miss = 0;
foreach ($past as $m) {
    echo str_pad((string) $m['meeting_key'], 6) . ' '
        . str_pad(($m['meeting_name'] ?? '—') . ' (' . ($m['country_code'] ?? '') . ')', 50);
    $w = $cli->getMeetingWinner((int) $m['meeting_key']);
    if ($w !== null && !empty($w['driver']['full_name'])) {
        echo '✓ ' . $w['driver']['full_name'] . "\n";
        $ok++;
    } else {
        echo "—\n";
        $miss++;
    }
}

echo "\nDone: $ok winners cached, $miss without race data.\n";

<?php declare(strict_types=1);

namespace App\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storage;

final class OpenF1Client
{
    private const BASE_URL = 'https://api.openf1.org/v1';
    private const MIN_INTERVAL_MS = 350;

    private Cache $cache;
    private float $lastRequestAt = 0;

    public function __construct(Storage $storage)
    {
        $this->cache = new Cache($storage, 'openf1');
    }

    /** Latest meeting (GP weekend) for given year, sorted by start date. */
    public function getMeetings(int $year): array
    {
        return $this->get('meetings', ['year' => $year]);
    }

    /** Sessions of one meeting (Practice 1/2/3, Qualifying, Race, …). */
    public function getSessions(int $meetingKey): array
    {
        return $this->get('sessions', ['meeting_key' => $meetingKey]);
    }

    /** Single meeting by key. */
    public function getMeeting(int $meetingKey): ?array
    {
        $rows = $this->get('meetings', ['meeting_key' => $meetingKey]);
        return $rows[0] ?? null;
    }

    /** Drivers participating in a session. */
    public function getDrivers(int $sessionKey): array
    {
        return $this->get('drivers', ['session_key' => $sessionKey]);
    }

    /** Drivers across all sessions of a meeting, keyed by driver_number; merges partial responses. */
    public function getDriversForMeeting(int $meetingKey): array
    {
        $sessions = $this->getSessions($meetingKey);
        $byNumber = [];
        foreach ($sessions as $s) {
            $drivers = $this->getDrivers((int) $s['session_key']);
            foreach ($drivers as $d) {
                $dn = $d['driver_number'];
                if (!isset($byNumber[$dn]) || empty($byNumber[$dn]['full_name'])) {
                    $byNumber[$dn] = $d;
                }
            }
        }
        return $byNumber;
    }

    /** Read cached winner only (non-blocking). Returns null if not yet warmed up. */
    public function getCachedMeetingWinner(int $meetingKey): ?array
    {
        $cached = $this->cache->load("winner:$meetingKey");
        return $cached ?: null;
    }

    /** Winner of a meeting's Race session, or null if no Race finished yet. Will hit API if cache cold. */
    public function getMeetingWinner(int $meetingKey): ?array
    {
        $cacheKey = "winner:$meetingKey";
        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $sessions = $this->getSessions($meetingKey);
        $raceSession = null;
        foreach ($sessions as $s) {
            if (($s['session_type'] ?? '') === 'Race' && ($s['session_name'] ?? '') === 'Race') {
                $raceSession = $s;
                break;
            }
        }
        if ($raceSession === null) {
            $this->cache->save($cacheKey, false, [Cache::Expire => '60 seconds']);
            return null;
        }

        $endDate = new \DateTimeImmutable($raceSession['date_end']);
        if ($endDate > new \DateTimeImmutable()) {
            return null;
        }

        $results = $this->getSessionResult((int) $raceSession['session_key']);
        $first = null;
        foreach ($results as $r) {
            if (($r['position'] ?? null) === 1) {
                $first = $r;
                break;
            }
        }
        if ($first === null) {
            $this->cache->save($cacheKey, false, [Cache::Expire => '60 seconds']);
            return null;
        }

        $drivers = $this->getDrivers((int) $raceSession['session_key']);
        foreach ($drivers as $d) {
            if ($d['driver_number'] === $first['driver_number']) {
                $first['driver'] = $d;
                break;
            }
        }

        if (empty($first['driver'])) {
            $this->cache->save($cacheKey, false, [Cache::Expire => '60 seconds']);
            return null;
        }

        $this->cache->save($cacheKey, $first, [Cache::Expire => '30 days']);
        return $first;
    }

    /** Final classification (preferred): position, points, laps, DNF/DNS/DSQ, gap. */
    public function getSessionResult(int $sessionKey): array
    {
        $rows = $this->get('session_result', ['session_key' => $sessionKey]);
        if (!empty($rows)) {
            usort($rows, fn($a, $b) => ($a['position'] ?? 99) <=> ($b['position'] ?? 99));
            return $rows;
        }
        return $this->getFinalPositions($sessionKey);
    }

    /** Fallback: last recorded position event per driver (used when session_result has no data yet). */
    public function getFinalPositions(int $sessionKey): array
    {
        $raw = $this->get('position', ['session_key' => $sessionKey]);
        $latest = [];
        foreach ($raw as $row) {
            $dn = $row['driver_number'];
            if (!isset($latest[$dn]) || $row['date'] > $latest[$dn]['date']) {
                $latest[$dn] = $row;
            }
        }
        usort($latest, fn($a, $b) => $a['position'] <=> $b['position']);
        return array_values($latest);
    }

    /** Weather samples for a session (we'll show first + min/max). */
    public function getWeather(int $sessionKey): array
    {
        return $this->get('weather', ['session_key' => $sessionKey]);
    }

    /** Race control events: flags, safety car, incidents. */
    public function getRaceControl(int $sessionKey): array
    {
        return $this->get('race_control', ['session_key' => $sessionKey]);
    }

    /** Fetch + parse JSON with cache. Retries empty responses; short TTL for empties so reloads can recover. */
    private function get(string $endpoint, array $params, int $ttlSeconds = 60): array
    {
        $url = self::BASE_URL . '/' . $endpoint . '?' . http_build_query($params);
        $cacheKey = $url;

        $cached = $this->cache->load($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: f1.markuska.cz\r\n",
                'timeout' => 10,
            ],
        ]);

        $data = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            if ($elapsedMs < self::MIN_INTERVAL_MS) {
                usleep((int) ((self::MIN_INTERVAL_MS - $elapsedMs) * 1000));
            }
            $this->lastRequestAt = microtime(true);

            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                usleep(500_000);
                continue;
            }
            $data = json_decode($body, true) ?? [];
            if (!empty($data) && !isset($data['error'])) {
                break;
            }
            $data = [];
            usleep(500_000);
        }

        $ttl = empty($data) ? 5 : $ttlSeconds;

        $this->cache->save($cacheKey, $data, [
            Cache::Expire => "$ttl seconds",
        ]);

        return $data;
    }
}

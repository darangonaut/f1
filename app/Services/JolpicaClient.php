<?php declare(strict_types=1);

namespace App\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storage;

/**
 * Client for the Jolpica-F1 API (https://api.jolpi.ca/ergast/), the community
 * successor to Ergast. Used as the race-data source: schedule, results,
 * qualifying and sprint classifications are published within ~1h of a session,
 * unlike OpenF1's telemetry-first endpoints which lagged for hours.
 */
final class JolpicaClient
{
    private const BASE_URL = 'https://api.jolpi.ca/ergast/f1';
    private const MIN_INTERVAL_MS = 300;

    private Cache $cache;
    private float $lastRequestAt = 0;

    public function __construct(Storage $storage)
    {
        $this->cache = new Cache($storage, 'jolpica');
    }

    /** Full season schedule. Each race carries its session sub-objects (practices, qualifying, sprint). */
    public function getSchedule(int $year): array
    {
        $data = $this->get("$year/races");
        return $data['MRData']['RaceTable']['Races'] ?? [];
    }

    /** Final race classification rows for one round. */
    public function getRaceResults(int $year, int $round): array
    {
        $data = $this->get("$year/$round/results");
        $races = $data['MRData']['RaceTable']['Races'] ?? [];
        return $races[0]['Results'] ?? [];
    }

    /** Sprint race classification rows for one round. */
    public function getSprintResults(int $year, int $round): array
    {
        $data = $this->get("$year/$round/sprint");
        $races = $data['MRData']['RaceTable']['Races'] ?? [];
        return $races[0]['SprintResults'] ?? [];
    }

    /** Qualifying classification rows for one round. */
    public function getQualifyingResults(int $year, int $round): array
    {
        $data = $this->get("$year/$round/qualifying");
        $races = $data['MRData']['RaceTable']['Races'] ?? [];
        return $races[0]['QualifyingResults'] ?? [];
    }

    /** Official end-of-season (or current) driver standings — handles dropped-scores eras correctly. */
    public function getDriverStandings(int $year): array
    {
        $data = $this->get("$year/driverStandings");
        $lists = $data['MRData']['StandingsTable']['StandingsLists'] ?? [];
        return $lists[0]['DriverStandings'] ?? [];
    }

    /** Official constructor standings (constructors' championship exists from 1958). */
    public function getConstructorStandings(int $year): array
    {
        $data = $this->get("$year/constructorStandings");
        $lists = $data['MRData']['StandingsTable']['StandingsLists'] ?? [];
        return $lists[0]['ConstructorStandings'] ?? [];
    }

    /** Fetch + parse JSON with cache. Retries transient failures; short TTL for empties so reloads can recover. */
    private function get(string $path, int $ttlSeconds = 300): array
    {
        $url = self::BASE_URL . '/' . $path . '/?format=json&limit=100';
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
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['MRData'])) {
                $data = $decoded;
                break;
            }
            usleep(500_000);
        }

        $ttl = empty($data) ? 30 : $ttlSeconds;
        $this->cache->save($cacheKey, $data, [Cache::Expire => "$ttl seconds"]);

        return $data;
    }
}

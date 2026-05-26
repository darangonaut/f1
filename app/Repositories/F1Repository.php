<?php declare(strict_types=1);

namespace App\Repositories;

use Nette\Database\Explorer;


final class F1Repository
{
    public function __construct(private readonly Explorer $db) {}

    /** All meetings for a year, ordered by date_start ASC. */
    public function getMeetings(int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM meetings WHERE year = ? ORDER BY date_start ASC',
            $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Latest year present in DB. */
    public function getLatestYear(): ?int
    {
        $y = $this->db->fetchField('SELECT MAX(year) FROM meetings');
        return $y !== false && $y !== null ? (int) $y : null;
    }

    /** All seasons present in DB, newest first. */
    public function getAvailableYears(): array
    {
        return array_map(
            fn($r) => (int) $r->year,
            $this->db->fetchAll('SELECT DISTINCT year FROM meetings ORDER BY year DESC'),
        );
    }

    /** Sessions of a meeting, ordered by date_start DESC (Race first). */
    public function getSessions(int $meetingKey): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM sessions WHERE meeting_key = ? ORDER BY date_start DESC',
            $meetingKey,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Single meeting record by key. */
    public function getMeeting(int $meetingKey): ?array
    {
        $row = $this->db->fetch('SELECT * FROM meetings WHERE meeting_key = ?', $meetingKey);
        return $row ? (array) $row : null;
    }

    /** Drivers for a year keyed by driver_number. */
    public function getDriversForYear(int $year): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM drivers WHERE year = ?', $year);
        $byNumber = [];
        foreach ($rows as $r) {
            $byNumber[(int) $r->driver_number] = (array) $r;
        }
        return $byNumber;
    }

    /** Final results for a session, ordered by position, joined with driver info. */
    public function getSessionResults(int $sessionKey, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT sr.*, d.full_name, d.team_name, d.team_colour, d.country_code
             FROM session_results sr
             LEFT JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE sr.session_key = ?
             ORDER BY CASE WHEN sr.position IS NULL THEN 999 ELSE sr.position END',
            $year,
            $sessionKey,
        );
        $out = [];
        foreach ($rows as $r) {
            $arr = (array) $r;
            $arr['driver'] = $arr['full_name'] ? [
                'full_name' => $arr['full_name'],
                'team_name' => $arr['team_name'],
                'team_colour' => $arr['team_colour'],
                'country_code' => $arr['country_code'],
            ] : null;
            $out[] = $arr;
        }
        return $out;
    }

    /** Winner row (position=1) for a meeting's Race session. */
    public function getMeetingWinner(int $meetingKey, int $year): ?array
    {
        $row = $this->db->fetch(
            'SELECT sr.*, d.full_name, d.team_name
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             LEFT JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE s.meeting_key = ? AND s.session_type = ? AND s.session_name = ? AND sr.position = 1
             LIMIT 1',
            $year,
            $meetingKey,
            'Race',
            'Race',
        );
        if (!$row) {
            return null;
        }
        $arr = (array) $row;
        $arr['driver'] = $arr['full_name'] ? [
            'full_name' => $arr['full_name'],
            'team_name' => $arr['team_name'],
        ] : null;
        return $arr;
    }

    /** Driver standings — aggregated points across all Race + Sprint sessions of a year. */
    public function getDriverStandings(int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT
                sr.driver_number,
                d.full_name,
                d.team_name,
                d.team_colour,
                d.country_code,
                SUM(sr.points) AS total_points,
                SUM(CASE WHEN sr.position = 1 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN sr.position <= 3 AND sr.position IS NOT NULL THEN 1 ELSE 0 END) AS podiums
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             LEFT JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE m.year = ? AND s.session_type = ?
             GROUP BY sr.driver_number, d.full_name, d.team_name, d.team_colour, d.country_code
             ORDER BY total_points DESC, wins DESC',
            $year,
            $year,
            'Race',
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Constructor standings — sum of all drivers' points per team. */
    public function getConstructorStandings(int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT
                d.team_name,
                d.team_colour,
                SUM(sr.points) AS total_points,
                SUM(CASE WHEN sr.position = 1 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN sr.position <= 3 AND sr.position IS NOT NULL THEN 1 ELSE 0 END) AS podiums
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE m.year = ? AND s.session_type = ?
             GROUP BY d.team_name, d.team_colour
             ORDER BY total_points DESC, wins DESC',
            $year,
            $year,
            'Race',
        );
        return array_map(function ($r) {
            $a = (array) $r;
            $a['slug'] = self::slug((string) $a['team_name']);
            return $a;
        }, $rows);
    }

    /** URL-safe slug for a constructor name, e.g. "RB F1 Team" → "rb-f1-team". */
    public static function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
    }

    /** Constructor standings row matched by slug, with championship rank, or null. */
    public function getConstructor(string $slug, int $year): ?array
    {
        foreach ($this->getConstructorStandings($year) as $i => $c) {
            if ($c['slug'] === $slug) {
                $c['rank'] = $i + 1;
                return $c;
            }
        }
        return null;
    }

    /** Drivers of a constructor in a season with their aggregates. */
    public function getConstructorDrivers(string $teamName, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT sr.driver_number, d.full_name, d.name_acronym,
                    SUM(sr.points) AS total_points,
                    SUM(CASE WHEN sr.position = 1 THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN sr.position <= 3 AND sr.position IS NOT NULL THEN 1 ELSE 0 END) AS podiums
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE m.year = ? AND s.session_type = ? AND d.team_name = ?
             GROUP BY sr.driver_number, d.full_name, d.name_acronym
             ORDER BY total_points DESC',
            $year, $year, 'Race', $teamName,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Per-meeting Grand Prix results for a constructor's drivers, ordered by date then position. */
    public function getConstructorSeason(string $teamName, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.meeting_key, m.meeting_name, m.date_start,
                    sr.driver_number, d.full_name, d.name_acronym,
                    sr.position, sr.points, sr.dnf, sr.dns, sr.dsq
             FROM meetings m
             JOIN sessions s ON s.meeting_key = m.meeting_key AND s.session_type = ? AND s.session_name = ?
             JOIN session_results sr ON sr.session_key = s.session_key
             JOIN drivers d ON d.driver_number = sr.driver_number AND d.year = ?
             WHERE m.year = ? AND d.team_name = ?
             ORDER BY m.date_start ASC, CASE WHEN sr.position IS NULL THEN 999 ELSE sr.position END ASC',
            'Race', 'Race', $year, $year, $teamName,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Single driver record for a season. */
    public function getDriver(int $number, int $year): ?array
    {
        $row = $this->db->fetch('SELECT * FROM drivers WHERE driver_number = ? AND year = ?', $number, $year);
        return $row ? (array) $row : null;
    }

    /** Per-meeting qualifying + race result for one driver in a season, ordered by date. */
    public function getDriverSeason(int $number, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.meeting_key, m.meeting_name, m.country_code, m.date_start,
                    q.position AS quali_pos,
                    race.position AS race_pos, race.points AS race_points,
                    race.dnf, race.dns, race.dsq
             FROM meetings m
             JOIN sessions rs ON rs.meeting_key = m.meeting_key AND rs.session_type = ? AND rs.session_name = ?
             JOIN session_results race ON race.session_key = rs.session_key AND race.driver_number = ?
             LEFT JOIN sessions qs ON qs.meeting_key = m.meeting_key AND qs.session_name = ?
             LEFT JOIN session_results q ON q.session_key = qs.session_key AND q.driver_number = ?
             WHERE m.year = ?
             ORDER BY m.date_start ASC',
            'Race', 'Race', $number, 'Qualifying', $number, $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Season totals for one driver (points/wins/podiums include Sprint, matching standings). */
    public function getDriverSeasonStats(int $number, int $year): array
    {
        $agg = $this->db->fetch(
            'SELECT SUM(sr.points) AS total_points,
                    SUM(CASE WHEN sr.position = 1 THEN 1 ELSE 0 END) AS wins,
                    SUM(CASE WHEN sr.position <= 3 AND sr.position IS NOT NULL THEN 1 ELSE 0 END) AS podiums,
                    SUM(CASE WHEN sr.dnf = 1 THEN 1 ELSE 0 END) AS dnfs
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_type = ? AND sr.driver_number = ?',
            $year, 'Race', $number,
        );
        $poles = $this->db->fetchField(
            'SELECT COUNT(*) FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_name = ? AND sr.driver_number = ? AND sr.position = 1',
            $year, 'Qualifying', $number,
        );
        $best = $this->db->fetchField(
            'SELECT MIN(sr.position) FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_type = ? AND s.session_name = ? AND sr.driver_number = ? AND sr.position IS NOT NULL',
            $year, 'Race', 'Race', $number,
        );
        return [
            'total_points' => (float) ($agg->total_points ?? 0),
            'wins' => (int) ($agg->wins ?? 0),
            'podiums' => (int) ($agg->podiums ?? 0),
            'dnfs' => (int) ($agg->dnfs ?? 0),
            'poles' => (int) $poles,
            'best_finish' => $best !== null && $best !== false ? (int) $best : null,
        ];
    }

    /** Timestamp of the most recent fetch (across all tables). */
    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        $max = $this->db->fetchField(
            'SELECT MAX(fetched_at) FROM (
                SELECT MAX(fetched_at) AS fetched_at FROM meetings
                UNION SELECT MAX(fetched_at) FROM sessions
                UNION SELECT MAX(fetched_at) FROM session_results
                UNION SELECT MAX(fetched_at) FROM drivers
            ) AS t',
        );
        return $max ? new \DateTimeImmutable($max) : null;
    }
}

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

    /** Final results for a session, ordered by position, joined with driver info. */
    public function getSessionResults(int $sessionKey, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT sr.*, d.full_name, d.team_name, d.team_colour, d.country_code
             FROM session_results sr
             LEFT JOIN drivers d ON d.driver_id = sr.driver_id AND d.year = ?
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
             LEFT JOIN drivers d ON d.driver_id = sr.driver_id AND d.year = ?
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

    /** Official driver standings for a year (position/points/wins) + GP podiums computed from results. */
    public function getDriverStandings(int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ds.driver_id, ds.position, ds.points AS total_points, ds.wins,
                    d.full_name, d.team_name, d.driver_number,
                    (SELECT COUNT(*) FROM session_results sr
                       JOIN sessions s ON s.session_key = sr.session_key
                       JOIN meetings m ON m.meeting_key = s.meeting_key
                      WHERE m.year = ? AND s.session_name = \'Race\'
                        AND sr.driver_id = ds.driver_id AND sr.position IS NOT NULL AND sr.position <= 3) AS podiums
             FROM driver_standings ds
             LEFT JOIN drivers d ON d.driver_id = ds.driver_id AND d.year = ds.year
             WHERE ds.year = ?
             ORDER BY CASE WHEN ds.position IS NULL THEN 999999 ELSE ds.position END ASC',
            $year,
            $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Official constructor standings for a year + GP podiums computed from results. */
    public function getConstructorStandings(int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT cs.constructor_id, cs.constructor_name AS team_name, cs.position, cs.points AS total_points, cs.wins,
                    (SELECT COUNT(*) FROM session_results sr
                       JOIN sessions s ON s.session_key = sr.session_key
                       JOIN meetings m ON m.meeting_key = s.meeting_key
                      WHERE m.year = ? AND s.session_name = \'Race\'
                        AND sr.constructor_id = cs.constructor_id AND sr.position IS NOT NULL AND sr.position <= 3) AS podiums
             FROM constructor_standings cs
             WHERE cs.year = ?
             ORDER BY CASE WHEN cs.position IS NULL THEN 999999 ELSE cs.position END ASC',
            $year,
            $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Single constructor standings row (with championship rank), or null. */
    public function getConstructor(string $constructorId, int $year): ?array
    {
        $row = $this->db->fetch(
            'SELECT cs.constructor_id, cs.constructor_name AS team_name,
                    cs.position AS rank, cs.points AS total_points, cs.wins,
                    (SELECT COUNT(*) FROM session_results sr
                       JOIN sessions s ON s.session_key = sr.session_key
                       JOIN meetings m ON m.meeting_key = s.meeting_key
                      WHERE m.year = ? AND s.session_name = \'Race\'
                        AND sr.constructor_id = cs.constructor_id AND sr.position IS NOT NULL AND sr.position <= 3) AS podiums
             FROM constructor_standings cs
             WHERE cs.year = ? AND cs.constructor_id = ?',
            $year,
            $year,
            $constructorId,
        );
        return $row ? (array) $row : null;
    }

    /** Drivers who raced for a constructor in a season, with their season points/wins. */
    public function getConstructorDrivers(string $constructorId, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT sr.driver_id, d.full_name, d.name_acronym, d.driver_number,
                    ds.points AS total_points, ds.wins,
                    (SELECT COUNT(*) FROM session_results sr2
                       JOIN sessions s2 ON s2.session_key = sr2.session_key
                       JOIN meetings m2 ON m2.meeting_key = s2.meeting_key
                      WHERE m2.year = ? AND s2.session_name = \'Race\'
                        AND sr2.driver_id = sr.driver_id AND sr2.position IS NOT NULL AND sr2.position <= 3) AS podiums
             FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             LEFT JOIN drivers d ON d.driver_id = sr.driver_id AND d.year = m.year
             LEFT JOIN driver_standings ds ON ds.driver_id = sr.driver_id AND ds.year = m.year
             WHERE m.year = ? AND sr.constructor_id = ?
             GROUP BY sr.driver_id, d.full_name, d.name_acronym, d.driver_number, ds.points, ds.wins
             ORDER BY ds.points DESC',
            $year,
            $year,
            $constructorId,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Per-meeting Grand Prix results for a constructor's cars, ordered by date then position. */
    public function getConstructorSeason(string $constructorId, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.meeting_key, m.meeting_name, m.date_start,
                    sr.driver_id, d.full_name, d.name_acronym, d.driver_number,
                    sr.position, sr.points, sr.dnf, sr.dns, sr.dsq
             FROM meetings m
             JOIN sessions s ON s.meeting_key = m.meeting_key AND s.session_type = ? AND s.session_name = ?
             JOIN session_results sr ON sr.session_key = s.session_key AND sr.constructor_id = ?
             LEFT JOIN drivers d ON d.driver_id = sr.driver_id AND d.year = m.year
             WHERE m.year = ?
             ORDER BY m.date_start ASC, CASE WHEN sr.position IS NULL THEN 999 ELSE sr.position END ASC',
            'Race', 'Race', $constructorId, $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Single driver record for a season. */
    public function getDriver(string $driverId, int $year): ?array
    {
        $row = $this->db->fetch('SELECT * FROM drivers WHERE driver_id = ? AND year = ?', $driverId, $year);
        return $row ? (array) $row : null;
    }

    /** Per-meeting qualifying + race result for one driver in a season, ordered by date. */
    public function getDriverSeason(string $driverId, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.meeting_key, m.meeting_name, m.country_code, m.date_start,
                    q.position AS quali_pos,
                    race.position AS race_pos, race.points AS race_points,
                    race.dnf, race.dns, race.dsq
             FROM meetings m
             JOIN sessions rs ON rs.meeting_key = m.meeting_key AND rs.session_type = ? AND rs.session_name = ?
             JOIN session_results race ON race.session_key = rs.session_key AND race.driver_id = ?
             LEFT JOIN sessions qs ON qs.meeting_key = m.meeting_key AND qs.session_name = ?
             LEFT JOIN session_results q ON q.session_key = qs.session_key AND q.driver_id = ?
             WHERE m.year = ?
             ORDER BY m.date_start ASC',
            'Race', 'Race', $driverId, 'Qualifying', $driverId, $year,
        );
        return array_map(fn($r) => (array) $r, $rows);
    }

    /** Season totals for one driver: rank/points/wins from official standings, podiums/poles/best from results. */
    public function getDriverSeasonStats(string $driverId, int $year): array
    {
        $st = $this->db->fetch(
            'SELECT position, points, wins FROM driver_standings WHERE year = ? AND driver_id = ?',
            $year, $driverId,
        );
        $podiums = $this->db->fetchField(
            'SELECT COUNT(*) FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_type = ? AND s.session_name = ? AND sr.driver_id = ? AND sr.position <= 3 AND sr.position IS NOT NULL',
            $year, 'Race', 'Race', $driverId,
        );
        $poles = $this->db->fetchField(
            'SELECT COUNT(*) FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_name = ? AND sr.driver_id = ? AND sr.position = 1',
            $year, 'Qualifying', $driverId,
        );
        $best = $this->db->fetchField(
            'SELECT MIN(sr.position) FROM session_results sr
             JOIN sessions s ON s.session_key = sr.session_key
             JOIN meetings m ON m.meeting_key = s.meeting_key
             WHERE m.year = ? AND s.session_type = ? AND s.session_name = ? AND sr.driver_id = ? AND sr.position IS NOT NULL',
            $year, 'Race', 'Race', $driverId,
        );
        return [
            'position' => $st && $st->position !== null ? (int) $st->position : null,
            'total_points' => $st ? (float) $st->points : 0.0,
            'wins' => $st ? (int) $st->wins : 0,
            'podiums' => (int) $podiums,
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

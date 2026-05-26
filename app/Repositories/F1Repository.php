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
        return array_map(fn($r) => (array) $r, $rows);
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

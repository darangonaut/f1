<?php declare(strict_types=1);

namespace App\Presentation\Home;

use App\Repositories\F1Repository;
use Nette;


final class HomePresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(?int $year = null): void
    {
        $year = $year ?? $this->repo->getLatestYear() ?? (int) date('Y');
        $meetings = $this->repo->getMeetings($year);

        $now = new \DateTimeImmutable();
        $headerNext = null;          // for "Najbližšie podujatie": live OR next upcoming
        $nextUpcoming = null;        // for season list arrow: only strictly upcoming
        foreach ($meetings as $m) {
            $start = new \DateTimeImmutable($m['date_start']);
            $assumedEnd = $start->modify('+3 days');
            if ($assumedEnd > $now && $headerNext === null) {
                $headerNext = $m;
            }
            if ($start > $now && $nextUpcoming === null) {
                $nextUpcoming = $m;
            }
            if ($headerNext !== null && $nextUpcoming !== null) {
                break;
            }
        }
        $next = $headerNext;

        $anyLive = false;
        foreach ($meetings as $m) {
            $s = new \DateTimeImmutable($m['date_start']);
            if ($s <= $now && $s->modify('+3 days') >= $now) {
                $anyLive = true;
                break;
            }
        }

        foreach ($meetings as &$m) {
            $start = new \DateTimeImmutable($m['date_start']);
            $assumedEnd = $start->modify('+3 days');
            $m['is_past'] = $assumedEnd < $now;
            $m['is_live'] = $start <= $now && $assumedEnd >= $now;
            $m['is_next'] = !$anyLive && !$m['is_past'] && !$m['is_live']
                && $nextUpcoming !== null && $m['meeting_key'] === $nextUpcoming['meeting_key'];
            $m['winner'] = null;
            if ($m['is_past']) {
                $w = $this->repo->getMeetingWinner((int) $m['meeting_key'], $year);
                if ($w !== null && !empty($w['driver']['full_name'])) {
                    $m['winner'] = $w['driver']['full_name'];
                }
            }
        }
        unset($m);

        // Season over (no live/upcoming event) → highlight the World Champion.
        // Season in progress → highlight the most recent race winner.
        $champion = null;
        $constructorChampion = null;
        $lastResults = null;
        if ($next === null) {
            $champion = $this->repo->getDriverStandings($year)[0] ?? null;
            $constructorChampion = $this->repo->getConstructorStandings($year)[0] ?? null;
        } else {
            foreach (array_reverse($meetings) as $m) {
                if (!$m['is_past']) {
                    continue;
                }
                $w = $this->repo->getMeetingWinner((int) $m['meeting_key'], $year);
                if ($w !== null && !empty($w['driver']['full_name'])) {
                    $lastResults = ['meeting' => $m, 'winner' => $w];
                    break;
                }
            }
        }

        $nextSchedule = null;
        if ($next !== null) {
            $sessions = array_reverse($this->repo->getSessions((int) $next['meeting_key'])); // ASC by date_start
            $raceStart = null;
            foreach ($sessions as &$s) {
                $s['is_done'] = !empty($s['date_end']) && new \DateTimeImmutable($s['date_end']) < $now;
                if (($s['session_name'] ?? '') === 'Race' && $raceStart === null) {
                    $raceStart = $s['date_start'];
                }
            }
            unset($s);
            $nextSchedule = [
                'sessions' => $sessions,
                'countdown' => $raceStart ? self::relativeTo(new \DateTimeImmutable($raceStart), $now) : null,
            ];
        }

        $this->template->year = $year;
        $this->template->years = $this->repo->getAvailableYears();
        $this->template->next = $next;
        $this->template->nextSchedule = $nextSchedule;
        $this->template->lastResults = $lastResults;
        $this->template->champion = $champion;
        $this->template->constructorChampion = $constructorChampion;
        $this->template->meetings = $meetings;
    }

    /** Compact Slovak relative time to a future target, e.g. "o 2 dni 4 h". */
    private static function relativeTo(\DateTimeImmutable $target, \DateTimeImmutable $now): array
    {
        if ($target <= $now) {
            return ['live' => true, 'text' => null];
        }
        $diff = $now->diff($target);
        $d = (int) $diff->days;
        if ($d >= 1) {
            $text = "o {$d} " . self::plural($d, 'deň', 'dni', 'dní') . ($diff->h ? " {$diff->h} h" : '');
        } elseif ($diff->h >= 1) {
            $text = "o {$diff->h} h" . ($diff->i ? " {$diff->i} min" : '');
        } else {
            $text = "o {$diff->i} min";
        }
        return ['live' => false, 'text' => $text];
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : ($n >= 2 && $n <= 4 ? $few : $many);
    }
}

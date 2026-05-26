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

        $lastResults = null;
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

        $this->template->year = $year;
        $this->template->years = $this->repo->getAvailableYears();
        $this->template->next = $next;
        $this->template->lastResults = $lastResults;
        $this->template->meetings = $meetings;
    }
}

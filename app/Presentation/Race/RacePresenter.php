<?php declare(strict_types=1);

namespace App\Presentation\Race;

use App\Repositories\F1Repository;
use Nette;
use Nette\Application\BadRequestException;


final class RacePresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(int $meetingKey): void
    {
        $meeting = $this->repo->getMeeting($meetingKey);
        if ($meeting === null) {
            throw new BadRequestException("Meeting #$meetingKey not found.", 404);
        }

        $sessions = $this->repo->getSessions($meetingKey);
        $now = new \DateTimeImmutable();
        $shortDays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

        $meeting['display_date'] = (new \DateTimeImmutable($meeting['date_start']))->format('j. n. Y');

        $rendered = [];
        foreach ($sessions as $s) {
            $endDate = $s['date_end'] ? new \DateTimeImmutable($s['date_end']) : null;
            $startDate = $s['date_start'] ? new \DateTimeImmutable($s['date_start']) : null;
            $isFuture = $endDate === null || $endDate > $now;
            $positions = !$isFuture
                ? $this->repo->getSessionResults((int) $s['session_key'], (int) $meeting['year'])
                : [];
            $s['display_datetime'] = $startDate
                ? $shortDays[(int) $startDate->format('N') - 1] . ' ' . $startDate->format('j. n. · H:i')
                : '';
            $rendered[] = [
                'session' => $s,
                'positions' => $positions,
                'is_future' => $isFuture,
            ];
        }

        $this->template->meeting = $meeting;
        $this->template->sessions = $rendered;
    }
}

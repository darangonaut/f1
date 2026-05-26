<?php declare(strict_types=1);

namespace App\Presentation\Standings;

use App\Repositories\F1Repository;


final class StandingsPresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(?int $year = null): void
    {
        $year = $year ?? $this->repo->getLatestYear() ?? (int) date('Y');
        $years = $this->repo->getAvailableYears();
        $idx = array_search($year, $years, true);
        $this->template->year = $year;
        $this->template->years = $years;
        $this->template->nextYear = $idx !== false && $idx > 0 ? $years[$idx - 1] : null;
        $this->template->prevYear = $idx !== false && $idx < count($years) - 1 ? $years[$idx + 1] : null;
        $this->template->drivers = $this->repo->getDriverStandings($year);
        $this->template->constructors = $this->repo->getConstructorStandings($year);
    }
}

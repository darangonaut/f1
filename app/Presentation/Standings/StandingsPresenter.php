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
        $this->template->year = $year;
        $this->template->years = $this->repo->getAvailableYears();
        $this->template->drivers = $this->repo->getDriverStandings($year);
        $this->template->constructors = $this->repo->getConstructorStandings($year);
    }
}

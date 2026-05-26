<?php declare(strict_types=1);

namespace App\Presentation\Driver;

use App\Repositories\F1Repository;
use Nette\Application\BadRequestException;


final class DriverPresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(int $number, ?int $year = null): void
    {
        $year = $year ?? $this->repo->getLatestYear() ?? (int) date('Y');
        $driver = $this->repo->getDriver($number, $year);
        if ($driver === null) {
            throw new BadRequestException("Driver #$number not found for $year.", 404);
        }

        $standings = $this->repo->getDriverStandings($year);
        $rank = null;
        foreach ($standings as $i => $d) {
            if ((int) $d['driver_number'] === $number) {
                $rank = $i + 1;
                break;
            }
        }

        $this->template->year = $year;
        $this->template->driver = $driver;
        $this->template->stats = $this->repo->getDriverSeasonStats($number, $year);
        $this->template->season = $this->repo->getDriverSeason($number, $year);
        $this->template->rank = $rank;
    }
}

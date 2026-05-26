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

    public function renderDefault(string $id, ?int $year = null): void
    {
        $year = $year ?? $this->repo->getLatestYear() ?? (int) date('Y');
        $driver = $this->repo->getDriver($id, $year);
        if ($driver === null) {
            throw new BadRequestException("Driver '$id' not found for $year.", 404);
        }

        $stats = $this->repo->getDriverSeasonStats($id, $year);
        $this->template->year = $year;
        $this->template->driver = $driver;
        $this->template->stats = $stats;
        $this->template->season = $this->repo->getDriverSeason($id, $year);
        $this->template->rank = $stats['position'];
    }
}

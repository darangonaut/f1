<?php declare(strict_types=1);

namespace App\Presentation\Seasons;

use App\Repositories\F1Repository;


final class SeasonsPresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(): void
    {
        $this->template->years = $this->repo->getAvailableYears();
        $this->template->latest = $this->repo->getLatestYear();
    }
}

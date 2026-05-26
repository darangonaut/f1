<?php declare(strict_types=1);

namespace App\Presentation\Constructor;

use App\Repositories\F1Repository;
use Nette\Application\BadRequestException;


final class ConstructorPresenter extends \App\Presentation\BasePresenter
{
    public function __construct(
        private readonly F1Repository $repo,
    ) {
        parent::__construct();
    }

    public function renderDefault(string $id, ?int $year = null): void
    {
        $year = $year ?? $this->repo->getLatestYear() ?? (int) date('Y');
        $constructor = $this->repo->getConstructor($id, $year);
        if ($constructor === null) {
            throw new BadRequestException("Constructor '$id' not found for $year.", 404);
        }

        $season = [];
        foreach ($this->repo->getConstructorSeason($id, $year) as $r) {
            $mk = (int) $r['meeting_key'];
            if (!isset($season[$mk])) {
                $season[$mk] = [
                    'meeting_key' => $mk,
                    'meeting_name' => $r['meeting_name'],
                    'points' => 0.0,
                    'drivers' => [],
                ];
            }
            $season[$mk]['drivers'][] = $r;
            $season[$mk]['points'] += (float) ($r['points'] ?? 0);
        }

        $this->template->year = $year;
        $this->template->constructor = $constructor;
        $this->template->drivers = $this->repo->getConstructorDrivers($id, $year);
        $this->template->season = array_values($season);
    }
}

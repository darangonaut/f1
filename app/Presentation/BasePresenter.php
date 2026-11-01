<?php declare(strict_types=1);

namespace App\Presentation;

use App\Repositories\F1Repository;
use Nette;


abstract class BasePresenter extends Nette\Application\UI\Presenter
{
    /** @inject */
    public F1Repository $f1Repo;

    public function beforeRender(): void
    {
        parent::beforeRender();
        $this->template->lastSyncAt = $this->f1Repo->getLastSyncAt();

        // Časy relácií sú v DB v UTC (DATE_ATOM s +00:00) a vstavaný |date by ich vypísal v UTC.
        // Prevedieme ich do lokálneho pásma z Bootstrapu (Europe/Bratislava), vrátane letného/zimného času.
        $this->template->addFilter('date', static function (string|int|\DateTimeInterface|null $time, string $format = 'j. n. Y'): ?string {
            if ($time === null || $time === '') {
                return null;
            }
            $dt = match (true) {
                $time instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($time),
                is_int($time) => (new \DateTimeImmutable())->setTimestamp($time),
                default => new \DateTimeImmutable($time),
            };
            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
        });
    }
}

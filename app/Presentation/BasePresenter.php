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
    }
}

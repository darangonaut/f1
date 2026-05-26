<?php declare(strict_types=1);

namespace App\Core;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	use Nette\StaticClass;

	public static function createRouter(): RouteList
	{
		$router = new RouteList;
		$router->addRoute('race/<meetingKey [0-9]+>', 'Race:default');
		$router->addRoute('driver/<number [0-9]+>[/<year [0-9]+>]', 'Driver:default');
		$router->addRoute('constructor/<id>[/<year [0-9]+>]', 'Constructor:default');
		$router->addRoute('standings[/<year [0-9]+>]', 'Standings:default');
		$router->addRoute('season/<year [0-9]+>', 'Home:default');
		$router->addRoute('<presenter>/<action>[/<id>]', 'Home:default');
		return $router;
	}
}

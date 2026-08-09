<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\Hooks;

use OCA\Music\Db\Maintenance;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserHooks implements IEventListener {

	public function __construct(
		private Maintenance $maintenance,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->maintenance->resetAllData($event->getUser()->getUID());
		}
	}

	public static function register(IEventDispatcher $dispatcher) : void {
		$dispatcher->addServiceListener(UserDeletedEvent::class, self::class);
	}
}

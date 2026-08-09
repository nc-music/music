<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Event;

use OCA\Music\Service\Scanner;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use OCP\Share\IShare;

/** @template-implements IEventListener<ShareCreatedEvent|ShareDeletedEvent|ShareDeletedFromSelfEvent> */
class ShareEventListener implements IEventListener {

	public function __construct(
		private Scanner $scanner,
		private IGroupManager $groupManager,
		private ?string $userId,
	) {
	}

	private function removeSharedItem(Node $node, array $removeFromUsers) : void {
		if ($node instanceof Folder) {
			$this->scanner->deleteFolder($node, $removeFromUsers);
		} else {
			$this->scanner->delete($node->getId(), $removeFromUsers);
		}
	}

	/**
	 * Invoke auto update of music database after item gets unshared
	 */
	public function itemUnshared(IShare $share) : void {
		// react only on user and group shares
		if ($share->getShareType() == IShare::TYPE_USER) {
			$receivingUserIds = [$share->getSharedWith()];
		} elseif ($share->getShareType() == IShare::TYPE_GROUP) {
			$groupMembers = $this->groupManager->displayNamesInGroup($share->getSharedWith());
			$receivingUserIds = \array_keys($groupMembers);
			// remove the item owner and the share creator from the list of targeted users if present
			$receivingUserIds = \array_diff($receivingUserIds, [$share->getShareOwner(), $share->getSharedBy()]);
		}

		if (!empty($receivingUserIds)) {
			$this->removeSharedItem($share->getNode(), $receivingUserIds);
		}
	}

	/**
	 * Invoke auto update of music database after item gets unshared by the share recipient
	 */
	public function itemUnsharedFromSelf(IShare $share) : void {
		// The share recipient may be an individual user or a group, but the item is always removed from
		// the current user alone.
		self::removeSharedItem($share->getNode(), [$this->userId]);
	}

	/**
	 * Invoke auto update of music database after item gets shared
	 */
	public function itemShared(IShare $share) : void {
		// Do not auto-update database when a folder is shared. The folder might contain
		// thousands of audio files, and indexing them could take minutes or hours. The sharee
		// user will be prompted to update database the next time she opens the Music app.
		// Similarly, do not auto-update on group shares.
		if ($share->getNodeType() === 'file' && $share->getShareType() == IShare::TYPE_USER) {
			$file = $share->getNode();

			$receivingUserId = $share->getSharedWith();
			$receivingUserFolder = $this->scanner->resolveUserFolder($receivingUserId);
			$receivingUserFilePath = $receivingUserFolder->getPath() . $share->getTarget();
			$this->scanner->update($file, $receivingUserId, $receivingUserFilePath);
		}
	}

	public function handle(Event $event): void {
		if ($event instanceof ShareCreatedEvent) {
			$this->itemShared($event->getShare());
		} elseif ($event instanceof ShareDeletedEvent) {
			$this->itemUnshared($event->getShare());
		} elseif ($event instanceof ShareDeletedFromSelfEvent) {
			$this->itemUnsharedFromSelf($event->getShare());
		}
	}

	public static function register(IEventDispatcher $dispatcher) : void {
		$dispatcher->addServiceListener(ShareCreatedEvent::class, self::class);
		$dispatcher->addServiceListener(ShareDeletedEvent::class, self::class);
		$dispatcher->addServiceListener(ShareDeletedFromSelfEvent::class, self::class);
	}
}

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

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Service\Scanner;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

/** @template-implements IEventListener<NodeWrittenEvent|NodeRenamedEvent|BeforeNodeDeletedEvent> */
class FileEventListener implements IEventListener {

	public function __construct(
		private Scanner $scanner,
		private TrackBusinessLayer $trackBusinessLayer,
		private Logger $logger,
		private ?string $userId,
	) {
	}

	/**
	 * Invoke auto update of music database after file or folder deletion
	 * @param Node $node pointing to the file or folder
	 */
	private function deleted(Node $node) : void {
		if ($node instanceof Folder) {
			$this->scanner->deleteFolder($node);
		} else {
			$this->scanner->delete($node->getId());
		}
	}

	/**
	 * Invoke auto update of music database after file update or file creation
	 * @param Node $node pointing to the file
	 */
	private function updated(Node $node) : void {
		// At least on Nextcloud 13, it sometimes happens that this hook is triggered
		// when the core creates some temporary file and trying to access the provided
		// node throws an exception, probably because the temp file is already removed
		// by the time the execution gets here. See #636.
		// Furthermore, when the core opens a file in stream mode for writing using
		// File::fopen, this hook gets triggered immediately after the opening succeeds,
		// before anything is actually written and while the file is *exclusively locked
		// because of the write mode*. See #638.
		try {
			self::handleUpdated($node);
		} catch (\OCP\Files\NotFoundException $e) {
			$this->logger->warning('FileHooks::updated triggered for a non-existing file');
		} catch (\OCP\Lock\LockedException $e) {
			$this->logger->warning('FileHooks::updated triggered for a locked file ' . $node->getName());
		}
	}

	private function handleUpdated(Node $node) : void {
		// we are interested only about updates on files, not on folders
		if ($node instanceof File) {
			$userId = $this->getUser($node);

			// Ignore event if we got no user or folder or the user has not yet scanned the music
			// collection. The last condition is especially to prevent problems when creating new user
			// and the default file set contains one or more audio files (see the discussion in #638).
			if (!empty($userId) && self::userHasMusicLib($userId)) {
				$this->scanner->update($node, $userId, $node->getPath());
			}
		}
	}

	private function moved(Node $node) : void {
		try {
			self::handleMoved($node);
		} catch (\OCP\Files\NotFoundException $e) {
			$this->logger->warning('FileHooks::moved triggered for a non-existing file');
		} catch (\OCP\Lock\LockedException $e) {
			$this->logger->warning('FileHooks::moved triggered for a locked file ' . $node->getName());
		}
	}

	private function handleMoved(Node $node) : void {
		$userId = $this->getUser($node);

		if (!empty($userId) && self::userHasMusicLib($userId)) {
			if ($node instanceof File) {
				$this->scanner->fileMoved($node, $userId);
			} elseif ($node instanceof Folder) {
				$this->scanner->folderMoved($node, $userId);
			}
		}
	}

	private function getUser(Node $node) : ?string {
		$userId = $this->userId;

		// When a file is uploaded to a folder shared by link, we end up here without current user.
		// In that case, fall back to using file owner
		if (empty($userId)) {
			$owner = $node->getOwner();
			$userId = $owner ? $owner->getUID() : null;
		}

		return $userId;
	}

	/**
	 * Check if user has any scanned tracks in his/her music library
	 */
	private function userHasMusicLib(string $userId) : bool {
		return $this->trackBusinessLayer->count($userId) > 0;
	}

	private function postRenamed(Node $source, Node $target) : void {
		// Beware: the $source describes the past state of the file and some of its functions will throw upon calling

		if ($source->getParent()->getId() != $target->getParent()->getId()) {
			$this->moved($target);
		} else {
			$this->updated($target);
		}
	}

	private function safeExecute(callable $func) : void {
		// Don't let any exceptions or errors leak out of this method, no matter what unforeseen oddities happen.
		// We never want to prevent the actual file operation since our reactions to them are anyway non-crucial.
		// Especially during a server version update involving also Music app version update, the system may be
		// running a partially updated application version and that may lead to unexpected fatal errors, see
		// https://github.com/nc-music/oc-music/issues/1231.
		try {
			try {
				$func();
			} catch (\Throwable $error) {
				$this->logger->error("Error occurred while executing Music app file hook: {$error->getMessage()}. Stack trace: {$error->getTraceAsString()}");
			}
		} catch (\Throwable $error) {
			// even logging the error failed so just ignore
		}
	}

	public function handle(Event $event): void {
		$this->safeExecute(function () use ($event) {
			if ($event instanceof NodeWrittenEvent) {
				$this->updated($event->getNode());
			} elseif ($event instanceof NodeRenamedEvent) {
				$this->postRenamed($event->getSource(), $event->getTarget());
			} elseif ($event instanceof BeforeNodeDeletedEvent) {
				$this->deleted($event->getNode());
			}
		});
	}

	public static function register(IEventDispatcher $dispatcher) : void {
		$dispatcher->addServiceListener(NodeWrittenEvent::class, self::class);
		$dispatcher->addServiceListener(NodeRenamedEvent::class, self::class);
		$dispatcher->addServiceListener(BeforeNodeDeletedEvent::class, self::class);
	}
}

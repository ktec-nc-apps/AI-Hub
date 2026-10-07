<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Listener;

use OCA\AIHub\Service\HubService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/**
 * A conversation with the AI lasts as long as the login it was held in: when the
 * person logs out, the conversations of that login are dropped. Before the logout,
 * while the session still says which login it is.
 *
 * @template-implements IEventListener<BeforeUserLoggedOutEvent>
 */
class LogoutListener implements IEventListener {
	public function __construct(
		private HubService $hub,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserLoggedOutEvent) {
			return;
		}
		$user = $event->getUser();
		if ($user !== null) {
			$this->hub->forgetLogin($user->getUID());
		}
	}
}

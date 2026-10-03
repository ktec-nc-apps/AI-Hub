<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\AppInfo;

use OCA\AIHub\Settings\AdminForm;
use OCA\AIHub\Settings\AdminFormAccess;
use OCA\AIHub\TaskProcessing\TextToTextChatProvider;
use OCA\AIHub\TaskProcessing\TextToTextProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ai_hub';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDeclarativeSettings(AdminForm::class);
		$context->registerDeclarativeSettings(AdminFormAccess::class);
		// Nextcloud's own Task Processing API: the apps written against it -- the
		// Assistant among them -- get the connected model without knowing the hub.
		if (method_exists($context, 'registerTaskProcessingProvider')) {
			$context->registerTaskProcessingProvider(TextToTextProvider::class);
			$context->registerTaskProcessingProvider(TextToTextChatProvider::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}

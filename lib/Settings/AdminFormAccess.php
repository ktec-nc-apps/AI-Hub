<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Settings;

use OCA\AIHub\Service\ConfigService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Keys, endpoints and access rules.
 *
 * The second half of the section (priority 30). It sits after the engine choice
 * (AdminForm, priority 10) and the model picker (AdminTools, priority 20), so
 * the model is chosen right under the engine rather than after all of this.
 */
class AdminFormAccess implements IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IL10N $l,
		private ConfigService $config,
	) {
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return $this->config->getFormValue($fieldId);
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->config->setFormValue($fieldId, $value);
	}

	public function getSchema(): array {
		return [
			'id' => 'ai_hub-access',
			'priority' => 30,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'ai_hub',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Keys, access and limits'),
			'description' => $this->l->t('The API key for the service you chose above, who and which apps may use the hub, and how much.'),

			'fields' => [
				[
					'id' => 'claude_api_key',
					'title' => $this->l->t('Claude API key'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'gemini_api_key',
					'title' => $this->l->t('Gemini API key'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'openai_api_key',
					'title' => $this->l->t('API key for the OpenAI-compatible endpoint'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'openai_base_url',
					'title' => $this->l->t('Base URL of the OpenAI-compatible endpoint'),
					'description' => $this->l->t('For example https://openrouter.ai/api/v1, https://api.openai.com/v1 or http://localhost:11434/v1'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'https://openrouter.ai/api/v1',
					'default' => 'https://openrouter.ai/api/v1',
				],
				[
					'id' => 'allowlist_enabled',
					'title' => $this->l->t('Restrict the hub to selected users'),
					'description' => $this->l->t('When off, every user of this server may ask through the apps that use AI-Hub.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'allowed_users',
					'title' => $this->l->t('Allowed users'),
					'description' => $this->l->t('Comma-separated user IDs, used only while the restriction above is on.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'alice, bob',
					'default' => '',
				],
				[
					'id' => 'allowed_apps',
					'title' => $this->l->t('Apps that may ask'),
					'description' => $this->l->t('Comma-separated app IDs, for example editbase, ktec_talkbot. Empty = every app on this server that registers a scenario.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = every app'),
					'default' => '',
				],
				[
					'id' => 'cli_enabled',
					'title' => $this->l->t('Allow the command line option'),
					'description' => $this->l->t('Only turn this on if a Claude or Gemini command line tool is installed on this server and you want to use your subscription instead of an API key. The tool runs as the web server user.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'claude_cli_path',
					'title' => $this->l->t('Path to the Claude command line tool'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/usr/local/bin/claude',
					'default' => 'claude',
				],
				[
					'id' => 'gemini_cli_path',
					'title' => $this->l->t('Path to the Gemini command line tool'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/usr/local/bin/gemini',
					'default' => 'gemini',
				],
				[
					'id' => 'cli_home',
					'title' => $this->l->t('Home directory for the command line tool'),
					'description' => $this->l->t('Where the tool keeps its login. Must be readable and writable by the web server user.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/var/lib/ai_hub',
					'default' => '',
				],
				[
					'id' => 'cli_user_tools',
					'title' => $this->l->t('Tools for ordinary users'),
					'description' => $this->l->t('Empty means no tools at all: the model can only answer. Otherwise a comma-separated list, for example WebSearch. Applies to everyone who is not a Nextcloud administrator. A scenario that asks for web search gets WebSearch regardless.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = no tools (recommended)'),
					'default' => '',
				],
				[
					'id' => 'cli_admin_tools',
					'title' => $this->l->t('Tools for Nextcloud administrators'),
					'description' => $this->l->t('⚠ Leave empty unless you mean it. Anything you put here — "default" for all tools, or a list such as Bash,Read,Edit — lets every member of the admin group run it on this server through an app that asks as an administrator, with the rights of the web server user. Empty means administrators get the same as everyone else.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = administrators get no tools either'),
					'default' => '',
				],
				[
					'id' => 'rate_per_minute',
					'title' => $this->l->t('Questions per user per minute'),
					'description' => $this->l->t('0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 10,
				],
				[
					'id' => 'max_parallel_user',
					'title' => $this->l->t('Answers in progress at once, per user'),
					'description' => $this->l->t('0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 2,
				],
				[
					'id' => 'max_parallel_total',
					'title' => $this->l->t('Answers in progress at once, for everyone together'),
					'description' => $this->l->t('Each answer keeps one PHP worker busy until it is done. 0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 4,
				],
			],
		];
	}
}

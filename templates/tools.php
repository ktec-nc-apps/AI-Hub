<?php
/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('ai_hub', 'tools');
\OCP\Util::addStyle('ai_hub', 'tools');
?>
<div id="aihub-tools" class="section"
	data-i18n-serverwide="<?php p($l->t('Server-wide')); ?>"
	data-i18n-api="API"
	data-i18n-cli="<?php p($l->t('Command line tool')); ?>"
	data-i18n-openai="<?php p($l->t('OpenAI-compatible')); ?>"
	data-i18n-models="<?php p($l->t('Models')); ?>"
	data-i18n-save="<?php p($l->t('Save')); ?>"
	data-i18n-test="<?php p($l->t('Test')); ?>"
	data-i18n-saved="<?php p($l->t('Saved.')); ?>"
	data-i18n-prompt="<?php p($l->t('Additional prompt')); ?>"
	data-i18n-promptset="<?php p($l->t('set')); ?>"
	data-i18n-prompthint="<?php p($l->t('Added to every question this app sends to the AI, after the app\'s own instructions, as instructions from you, the administrator: for example the tone to use, your organisation\'s terms, or what to leave out. Text from documents and records still never counts as an instruction. Saved with the row\'s Save button.')); ?>"
	data-i18n-promptph="<?php p($l->t('Nothing added')); ?>"
	data-i18n-promptlen="<?php p($l->t('{n} of {max} characters')); ?>"
	data-i18n-ready="<?php p($l->t('ready')); ?>"
	data-i18n-noapps="<?php p($l->t('No app has connected yet.')); ?>"
	data-i18n-notinstalled="<?php p($l->t('Not installed')); ?>"
	data-i18n-notconnected="<?php p($l->t('Has not connected yet')); ?>"
	data-i18n-no-key="<?php p($l->t('No API key.')); ?>"
	data-i18n-no-cli="<?php p($l->t('No path configured.')); ?>"
	data-i18n-no-model="<?php p($l->t('Pick a model first.')); ?>"
	data-i18n-no-store="<?php p($l->t('No memory cache to keep answers in.')); ?>"
	data-i18n-remove="<?php p($l->t('Remove')); ?>"
	data-i18n-removetitle="<?php p($l->t('Remove this app from the list')); ?>"
	data-i18n-mayreappear="<?php p($l->t('This app is installed, so it may appear again while it is enabled.')); ?>">
	<h2><?php p($l->t('Model and connection test')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Save the settings above, then load the models your key can use, choose one, and test that it answers. The model is set here and nowhere else.')); ?>
	</p>

	<div class="tb-actions">
		<button id="tb-fetch" class="button primary" type="button"><?php p($l->t('Load models')); ?></button>
		<button id="tb-test" class="button" type="button"><?php p($l->t('Test connection')); ?></button>
		<span id="tb-spinner" class="tb-hidden" aria-hidden="true">⏳</span>
	</div>

	<div id="tb-result" role="status" aria-live="polite"></div>

	<div id="tb-models-wrap" class="tb-hidden">
		<h3><?php p($l->t('Model')); ?> <small id="tb-current"></small></h3>
		<div class="tb-model-row">
			<label class="tb-visually-hidden" for="tb-model-select"><?php p($l->t('Model')); ?></label>
			<select id="tb-model-select"></select>
			<button id="tb-model-save" class="button primary" type="button"><?php p($l->t('Use this model')); ?></button>
		</div>
	</div>

	<div id="tb-engines-wrap" class="tb-hidden">
		<h3><?php p($l->t('What is ready to use')); ?></h3>
		<ul id="tb-engines"></ul>
	</div>

	<h3><?php p($l->t('Apps that connect')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Each app that asks AI-Hub can have its own AI and model. What is left as "Server-wide" follows the choice above. Nextcloud\'s own Task Processing (the Assistant and every app written against the standard API) is listed as one app. The Base series and Talk-Bot are listed from the start and greyed out until installed.')); ?>
	</p>
	<div class="tb-add-app">
		<label for="tb-add-app-select"><?php p($l->t('Add an app')); ?></label>
		<select id="tb-add-app-select" disabled
			title="<?php p($l->t('Apps that declare AI-Hub support and are not listed yet')); ?>">
			<option value=""><?php p($l->t('— choose an app —')); ?></option>
		</select>
		<label for="tb-add-app-id" class="tb-add-app-or"><?php p($l->t('or type an app ID')); ?></label>
		<input type="text" id="tb-add-app-id" autocomplete="off" spellcheck="false"
			placeholder="<?php p($l->t('App ID')); ?>">
		<button id="tb-add-app" class="button" type="button"><?php p($l->t('Add')); ?></button>
	</div>
	<div id="tb-apps-result" role="status" aria-live="polite"></div>
	<table id="tb-apps" class="tb-hidden">
		<thead>
			<tr>
				<th><?php p($l->t('App')); ?></th>
				<th><?php p($l->t('AI')); ?></th>
				<th><?php p($l->t('How')); ?></th>
				<th><?php p($l->t('Model')); ?></th>
				<th><?php p($l->t('What it gets')); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>
	<p id="tb-apps-empty" class="tb-hidden settings-hint"></p>
</div>

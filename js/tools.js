/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Model picker, connection test and the list of connecting apps (each with its
 * own AI and model) for the AI-Hub admin settings.
 */
(function () {
	'use strict';

	var t = function (text) {
		return (typeof OC !== 'undefined' && OC.L10N) ? OC.L10N.translate('ai_hub', text) : text;
	};

	var el = function (id) {
		return document.getElementById(id);
	};

	/** Words the template translated for us, by their data-i18n-* name. */
	function word(name) {
		var root = el('aihub-tools');
		return (root && root.getAttribute('data-i18n-' + name)) || name;
	}

	/** The longest additional prompt the server keeps (ConfigService::APP_PROMPT_MAX). */
	var PROMPT_MAX = 4000;

	function url(path) {
		return OC.generateUrl('/apps/ai_hub' + path);
	}

	function request(method, path, body) {
		return fetch(url(path), {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': OC.requestToken,
				'Accept': 'application/json'
			},
			body: body ? JSON.stringify(body) : undefined
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error((data && data.detail) || ('HTTP ' + response.status));
				}
				return data;
			});
		});
	}

	function busy(on) {
		var spinner = el('tb-spinner');
		if (spinner) {
			spinner.classList.toggle('tb-hidden', !on);
		}
		['tb-fetch', 'tb-test', 'tb-model-save'].forEach(function (id) {
			var button = el(id);
			if (button) {
				button.disabled = on;
			}
		});
	}

	function sayIn(id, message, ok) {
		var box = el(id);
		if (!box) {
			return;
		}
		box.textContent = message;
		box.classList.remove('tb-ok', 'tb-err');
		if (message) {
			box.classList.add(ok ? 'tb-ok' : 'tb-err');
		}
	}

	function say(message, ok) {
		sayIn('tb-result', message, ok);
	}

	function renderModels(data) {
		var wrap = el('tb-models-wrap');
		var select = el('tb-model-select');
		var current = el('tb-current');
		if (!wrap || !select) {
			return;
		}

		select.innerHTML = '';
		(data.models || []).forEach(function (model) {
			var option = document.createElement('option');
			option.value = model;
			option.textContent = model;
			if (model === data.current.model) {
				option.selected = true;
			}
			select.appendChild(option);
		});

		if (current) {
			current.textContent = data.current.provider + ' / ' + data.current.mode
				+ (data.current.model ? ' — ' + data.current.model : '');
		}
		wrap.classList.toggle('tb-hidden', (data.models || []).length === 0);
	}

	function renderEngines(engines) {
		var wrap = el('tb-engines-wrap');
		var list = el('tb-engines');
		if (!wrap || !list) {
			return;
		}

		list.innerHTML = '';
		(engines || []).forEach(function (engine) {
			var item = document.createElement('li');
			item.className = engine.ready ? 'tb-ready' : 'tb-not-ready';
			item.textContent = (engine.ready ? '✓ ' : '· ') + engine.label
				+ (engine.detail ? ' — ' + engine.detail : '');
			list.appendChild(item);
		});
		wrap.classList.toggle('tb-hidden', (engines || []).length === 0);
	}

	function loadModels() {
		busy(true);
		say('', true);
		request('GET', '/tools/models').then(function (data) {
			renderModels(data);
			renderEngines(data.engines);
			if (data.note) {
				say(data.note, true);
			} else {
				say(t('Loaded {count} models.').replace('{count}', (data.models || []).length), true);
			}
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	function saveModel() {
		var select = el('tb-model-select');
		if (!select || !select.value) {
			return;
		}
		busy(true);
		request('POST', '/tools/model', { model: select.value }).then(function (data) {
			say(t('Model set to {model}.').replace('{model}', data.model), true);
			var current = el('tb-current');
			if (current) {
				current.textContent = current.textContent.replace(/—.*$/, '— ' + data.model);
			}
			loadApps();
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	function testConnection() {
		busy(true);
		say(t('Contacting the AI service…'), true);
		request('POST', '/tools/test', {}).then(function (data) {
			if (data.ok) {
				say(t('{engine} answered using {model}: {reply}')
					.replace('{engine}', data.engine)
					.replace('{model}', data.model)
					.replace('{reply}', data.reply), true);
			} else {
				say(t('No answer: {detail}').replace('{detail}', data.detail || ''), false);
			}
		}).catch(function (error) {
			say(error.message, false);
		}).then(function () {
			busy(false);
		});
	}

	// ---- the apps that connect ------------------------------------------------

	function option(value, label, selected) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		o.selected = !!selected;
		return o;
	}

	/**
	 * Add the fetched models to a row's model select without duplicates, keeping
	 * whatever is selected now ("Server-wide" or the app's own model).
	 */
	function mergeModels(select, models) {
		var have = {};
		var current = select.value;
		Array.prototype.forEach.call(select.options, function (o) {
			have[o.value] = true;
		});
		(models || []).forEach(function (m) {
			if (!have[m]) {
				have[m] = true;
				select.appendChild(option(m, m, false));
			}
		});
		select.value = current;
	}

	function gets(app) {
		var g = app.gets || {};
		var text = g.provider + ' / ' + g.mode + (g.model ? ' — ' + g.model : '');
		if (app.ready) {
			return '✓ ' + text;
		}
		var why = word('no-' + String(app.reason || '').replace(/^no-/, ''));
		return '· ' + text + (app.reason ? ' — ' + why : '');
	}

	function appRow(app) {
		var tr = document.createElement('tr');
		tr.setAttribute('data-app', app.id);

		var name = document.createElement('td');
		name.className = 'tb-app-name';
		var strong = document.createElement('strong');
		strong.textContent = app.label;
		name.appendChild(strong);
		var sub = app.scenarios && app.scenarios.length ? app.scenarios.join(', ')
			: (app.installed && !app.connected ? word('notconnected') : '');
		if (sub) {
			var small = document.createElement('small');
			small.textContent = sub;
			name.appendChild(document.createElement('br'));
			name.appendChild(small);
		}
		tr.appendChild(name);

		var own = app.own || {};
		var provider = document.createElement('select');
		provider.className = 'tb-app-provider';
		provider.appendChild(option('', word('serverwide'), own.provider === ''));
		provider.appendChild(option('claude', 'Claude', own.provider === 'claude'));
		provider.appendChild(option('gemini', 'Gemini', own.provider === 'gemini'));
		provider.appendChild(option('openai', word('openai'), own.provider === 'openai'));
		var tdP = document.createElement('td');
		tdP.appendChild(provider);
		tr.appendChild(tdP);

		var mode = document.createElement('select');
		mode.className = 'tb-app-mode';
		mode.appendChild(option('', word('serverwide'), own.mode === ''));
		mode.appendChild(option('api', word('api'), own.mode === 'api'));
		mode.appendChild(option('cli', word('cli'), own.mode === 'cli'));
		var tdM = document.createElement('td');
		tdM.appendChild(mode);
		tr.appendChild(tdM);

		// The model is a real select: "Server-wide" first, then the app's own model
		// if one is set, and -- once the Models button has fetched them -- every model
		// the chosen engine offers.
		var model = document.createElement('select');
		model.className = 'tb-app-model';
		model.appendChild(option('', word('serverwide'), !own.model));
		if (own.model) {
			model.appendChild(option(own.model, own.model, true));
		}
		var modelsButton = document.createElement('button');
		modelsButton.type = 'button';
		modelsButton.className = 'button tb-app-models';
		modelsButton.textContent = word('models');
		var tdModel = document.createElement('td');
		tdModel.appendChild(model);
		tdModel.appendChild(modelsButton);
		tr.appendChild(tdModel);

		var tdGets = document.createElement('td');
		tdGets.className = 'tb-app-gets';
		tdGets.textContent = app.installed ? gets(app) : word('notinstalled');
		tr.appendChild(tdGets);

		var tdAct = document.createElement('td');
		tdAct.className = 'tb-app-actions';
		var save = document.createElement('button');
		save.type = 'button';
		save.className = 'button primary tb-app-save';
		save.textContent = word('save');
		var test = document.createElement('button');
		test.type = 'button';
		test.className = 'button tb-app-test';
		test.textContent = word('test');
		tdAct.appendChild(save);
		tdAct.appendChild(test);

		// Only admin-added and auto-discovered rows can be removed; the fixed rows
		// (the Base series and Task Processing) have no trash. The trash stays usable
		// even for an app that is not installed, so a stale entry can be cleared.
		var remove = null;
		if (!app.fixed) {
			remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button tb-app-remove';
			remove.textContent = '🗑 ' + word('remove');
			remove.title = word('removetitle');
			remove.setAttribute('aria-label', word('removetitle'));
			tdAct.appendChild(remove);
			remove.addEventListener('click', function () {
				remove.disabled = true;
				request('POST', '/tools/app/delete', { app: app.id }).then(function (data) {
					if (tr.parentNode) {
						tr.parentNode.removeChild(tr);
					}
					if (tr.promptRow && tr.promptRow.parentNode) {
						tr.promptRow.parentNode.removeChild(tr.promptRow);
					}
					sayIn('tb-apps-result', (data && data.mayReappear) ? word('mayreappear') : '', true);
					loadCompatible();
				}).catch(function (error) {
					remove.disabled = false;
					sayIn('tb-apps-result', error.message, false);
				});
			});
		}
		tr.appendChild(tdAct);

		// A fixed row (the Base series, Task Processing) that is not installed is
		// greyed out with nothing to set. An admin-added or auto-discovered row stays
		// configurable even when not installed -- the admin listed it on purpose, and
		// "What it gets" already shows it is not installed -- and keeps its trash.
		// The administrator's additional prompt for this app: a row of its own under the
		// app, folded until opened, saved with the row's Save button.
		var promptRow = document.createElement('tr');
		promptRow.className = 'tb-app-prompt-row';
		promptRow.setAttribute('data-app-prompt', app.id);
		var tdPrompt = document.createElement('td');
		tdPrompt.colSpan = 6;
		var details = document.createElement('details');
		details.className = 'tb-app-prompt';
		var summary = document.createElement('summary');
		var promptArea = document.createElement('textarea');
		promptArea.className = 'tb-app-prompt-text';
		promptArea.rows = 5;
		promptArea.maxLength = PROMPT_MAX;
		promptArea.placeholder = word('promptph');
		promptArea.value = app.prompt || '';
		promptArea.setAttribute('aria-label', word('prompt') + ' — ' + app.label);
		var count = document.createElement('small');
		count.className = 'tb-app-prompt-count';
		var hint = document.createElement('p');
		hint.className = 'settings-hint';
		hint.textContent = word('prompthint');
		var label = function () {
			summary.textContent = word('prompt') + (promptArea.value.trim() ? ' (' + word('promptset') + ')' : '');
			count.textContent = word('promptlen').replace('{n}', promptArea.value.length).replace('{max}', PROMPT_MAX);
		};
		label();
		promptArea.addEventListener('input', label);
		details.appendChild(summary);
		details.appendChild(promptArea);
		details.appendChild(count);
		details.appendChild(hint);
		tdPrompt.appendChild(details);
		promptRow.appendChild(tdPrompt);
		tr.promptRow = promptRow;

		if (!app.installed && app.fixed) {
			tr.classList.add('tb-off');
			promptRow.classList.add('tb-off');
			[provider, mode, model, modelsButton, save, test, promptArea].forEach(function (c) { c.disabled = true; });
			return tr;
		}

		modelsButton.addEventListener('click', function () {
			modelsButton.disabled = true;
			var q = '?app=' + encodeURIComponent(app.id)
				+ '&provider=' + encodeURIComponent(provider.value)
				+ '&mode=' + encodeURIComponent(mode.value);
			request('GET', '/tools/models' + q).then(function (data) {
				mergeModels(model, data.models);
				sayIn('tb-apps-result', data.note || t('Loaded {count} models.').replace('{count}', (data.models || []).length), true);
			}).catch(function (error) {
				sayIn('tb-apps-result', error.message, false);
			}).then(function () {
				modelsButton.disabled = false;
			});
		});

		save.addEventListener('click', function () {
			save.disabled = true;
			request('POST', '/tools/app', {
				app: app.id, provider: provider.value, mode: mode.value, model: model.value, prompt: promptArea.value
			}).then(function (data) {
				if (data.app) {
					tdGets.textContent = gets(data.app);
					promptArea.value = data.app.prompt || '';
					label();
				}
				sayIn('tb-apps-result', word('saved'), true);
			}).catch(function (error) {
				sayIn('tb-apps-result', error.message, false);
			}).then(function () {
				save.disabled = false;
			});
		});

		test.addEventListener('click', function () {
			test.disabled = true;
			sayIn('tb-apps-result', t('Contacting the AI service…'), true);
			request('POST', '/tools/test', { app: app.id }).then(function (data) {
				if (data.ok) {
					sayIn('tb-apps-result', app.label + ': ' + t('{engine} answered using {model}: {reply}')
						.replace('{engine}', data.engine)
						.replace('{model}', data.model)
						.replace('{reply}', data.reply), true);
				} else {
					sayIn('tb-apps-result', app.label + ': ' + t('No answer: {detail}').replace('{detail}', data.detail || ''), false);
				}
			}).catch(function (error) {
				sayIn('tb-apps-result', error.message, false);
			}).then(function () {
				test.disabled = false;
			});
		});

		return tr;
	}

	/**
	 * The apps that declare AI-Hub support (they ship appinfo/ai-hub.json) and are
	 * not listed yet, offered in the dropdown next to the typed id. Greyed out when
	 * there is none to pick.
	 */
	function loadCompatible() {
		var select = el('tb-add-app-select');
		if (!select) {
			return;
		}
		request('GET', '/tools/compatible').then(function (data) {
			var apps = data.apps || [];
			while (select.options.length > 1) {
				select.remove(1);
			}
			apps.forEach(function (app) {
				select.appendChild(option(app.id, app.name + (app.enabled ? '' : ' (' + word('notinstalled') + ')'), false));
			});
			select.value = '';
			select.disabled = apps.length === 0;
		}).catch(function (error) {
			sayIn('tb-apps-result', error.message, false);
		});
	}

	// The picked app wins; the typed id is the way in for an app without the marker.
	function addApp() {
		var select = el('tb-add-app-select');
		var input = el('tb-add-app-id');
		var button = el('tb-add-app');
		if (!input) {
			return;
		}
		var id = (select && !select.disabled && select.value) ? select.value : (input.value || '').trim().toLowerCase();
		if (!id) {
			(select && !select.disabled ? select : input).focus();
			return;
		}
		if (button) {
			button.disabled = true;
		}
		request('POST', '/tools/app/add', { app: id }).then(function () {
			input.value = '';
			if (select) {
				select.value = '';
			}
			sayIn('tb-apps-result', '', true);
			loadApps();
			loadCompatible();
		}).catch(function (error) {
			sayIn('tb-apps-result', error.message, false);
		}).then(function () {
			if (button) {
				button.disabled = false;
			}
		});
	}

	function loadApps() {
		var table = el('tb-apps');
		var empty = el('tb-apps-empty');
		if (!table) {
			return;
		}
		request('GET', '/tools/apps').then(function (data) {
			var body = table.querySelector('tbody');
			body.innerHTML = '';
			(data.apps || []).forEach(function (app) {
				var row = appRow(app);
				body.appendChild(row);
				if (row.promptRow) {
					body.appendChild(row.promptRow);
				}
			});
			var any = (data.apps || []).length > 0;
			table.classList.toggle('tb-hidden', !any);
			if (empty) {
				empty.textContent = any ? '' : word('noapps');
				empty.classList.toggle('tb-hidden', any);
			}
		}).catch(function (error) {
			sayIn('tb-apps-result', error.message, false);
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var fetchButton = el('tb-fetch');
		var testButton = el('tb-test');
		var saveButton = el('tb-model-save');
		if (!fetchButton) {
			return;
		}
		fetchButton.addEventListener('click', loadModels);
		if (testButton) {
			testButton.addEventListener('click', testConnection);
		}
		if (saveButton) {
			saveButton.addEventListener('click', saveModel);
		}
		var addButton = el('tb-add-app');
		var addInput = el('tb-add-app-id');
		if (addButton) {
			addButton.addEventListener('click', addApp);
		}
		if (addInput) {
			addInput.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					addApp();
				}
			});
		}
		loadApps();
		loadCompatible();
	});
})();

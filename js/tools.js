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

		var model = document.createElement('input');
		model.type = 'text';
		model.className = 'tb-app-model';
		model.value = own.model || '';
		model.placeholder = (app.gets && app.gets.model) || word('serverwide');
		model.setAttribute('list', 'tb-models-' + app.id);
		var list = document.createElement('datalist');
		list.id = 'tb-models-' + app.id;
		var modelsButton = document.createElement('button');
		modelsButton.type = 'button';
		modelsButton.className = 'button tb-app-models';
		modelsButton.textContent = word('models');
		var tdModel = document.createElement('td');
		tdModel.appendChild(model);
		tdModel.appendChild(list);
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
		tr.appendChild(tdAct);

		// An app that is not installed is shown greyed out, with nothing to set.
		if (!app.installed) {
			tr.classList.add('tb-off');
			[provider, mode, model, modelsButton, save, test].forEach(function (c) { c.disabled = true; });
			return tr;
		}

		modelsButton.addEventListener('click', function () {
			modelsButton.disabled = true;
			var q = '?app=' + encodeURIComponent(app.id)
				+ '&provider=' + encodeURIComponent(provider.value)
				+ '&mode=' + encodeURIComponent(mode.value);
			request('GET', '/tools/models' + q).then(function (data) {
				list.innerHTML = '';
				(data.models || []).forEach(function (m) {
					list.appendChild(option(m, m, false));
				});
				model.placeholder = data.current && data.current.model ? data.current.model : word('serverwide');
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
				app: app.id, provider: provider.value, mode: mode.value, model: model.value.trim()
			}).then(function (data) {
				if (data.app) {
					tdGets.textContent = gets(data.app);
					model.placeholder = (data.app.gets && data.app.gets.model) || word('serverwide');
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
				body.appendChild(appRow(app));
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
		loadApps();
	});
})();

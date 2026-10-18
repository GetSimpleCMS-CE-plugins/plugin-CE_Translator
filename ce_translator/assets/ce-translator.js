/* CE-Translator */
(function () {
'use strict';

	/* ------------------------------------------------------------------
	 * Placeholder check. Tokens that must survive translation: printf-style
	 * (%s, %1$d), {name} / {$name}, and the names of HTML tags. Attribute
	 * values (such as a link target) are deliberately not compared.
	 * ------------------------------------------------------------------ */
var TOKEN_PATTERNS = [
	{ re: /%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/g, lower: false },
	{ re: /\{\$?[A-Za-z0-9_]+\}/g, lower: false },
	{ re: /<\/?[A-Za-z][A-Za-z0-9]*/g, lower: true }
];

function extractTokens(text) {
	var tokens = [];
	TOKEN_PATTERNS.forEach(function (p) {
	var found = String(text).match(p.re) || [];
	found.forEach(function (t) { tokens.push(p.lower ? t.toLowerCase() : t); });
	});
	return tokens;
}

function countTokens(tokens) {
	var map = {};
	tokens.forEach(function (t) { map[t] = (map[t] || 0) + 1; });
	return map;
}

/** Tokens the source has but the translation lacks, and vice versa (multiset difference). */
function diffTokens(source, translation) {
	var a = countTokens(extractTokens(source));
	var b = countTokens(extractTokens(translation));
	var missing = [];
	var extra = [];
	Object.keys(a).forEach(function (t) {
	for (var i = (b[t] || 0); i < a[t]; i++) { missing.push(t); }
	});
	Object.keys(b).forEach(function (t) {
	for (var i = (a[t] || 0); i < b[t]; i++) { extra.push(t); }
	});
	return { missing: missing, extra: extra };
}

/* A text is worth comparing with its source only if it contains a real word (four
	 letters in a row once tags are removed). Same rule as ce_translator_is_comparable() in PHP. */
function isComparable(text) {
	return /\p{L}{4,}/u.test(String(text).replace(/<[^>]*>/g, ''));
}

function normalize(text) {
	return String(text).replace(/\r\n?/g, '\n');
}

function format(template, values) {
	return String(template).replace(/\{(\w+)\}/g, function (m, name) {
	return Object.prototype.hasOwnProperty.call(values, name) ? values[name] : m;
	});
}

if (typeof module !== 'undefined' && module.exports) {
	module.exports = { extractTokens: extractTokens, diffTokens: diffTokens, normalize: normalize, format: format, isComparable: isComparable };
}
if (typeof document === 'undefined') { return; }

function ready(fn) {
	if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', fn);
	} else {
	fn();
	}
}

/* ------------------------------------------------------------------
 * Select screen (plugin picker, new-language field, confirm buttons)
 * ------------------------------------------------------------------ */
function initSelectScreen(root) {
	var picker = document.getElementById('ce-picker');
	var select = document.getElementById('ce-plugin');
	if (picker && select) {
	select.addEventListener('change', function () { picker.submit(); });
	}

	var newLang = document.getElementById('ce-newlang');
	var newTarget = document.getElementById('ce-target-new');
	if (newLang && newTarget) {
	newLang.addEventListener('input', function () { newTarget.checked = true; });
	newTarget.addEventListener('change', function () { if (newTarget.checked) { newLang.focus(); } });
	}

	root.addEventListener('click', function (event) {
	var el = event.target;
	while (el && el !== root && !(el.getAttribute && el.getAttribute('data-confirm'))) {
		el = el.parentNode;
	}
	if (el && el !== root && el.getAttribute && !window.confirm(el.getAttribute('data-confirm'))) {
		event.preventDefault();
	}
	});
}

/* ------------------------------------------------------------------
 * Editor
 * ------------------------------------------------------------------ */
function initEditor(editor) {
	var strings = {};
	try {
	strings = JSON.parse(document.getElementById('ce-i18n').textContent) || {};
	} catch (e) { /* fall back to empty strings */ }
	function str(key) { return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key; }

	var state = {
	nonce: editor.getAttribute('data-nonce') || '',
	version: editor.getAttribute('data-version') || '',
	endpoint: editor.getAttribute('data-endpoint') || '',
	source: editor.getAttribute('data-source') || '',
	target: editor.getAttribute('data-target') || '',
	compare: editor.getAttribute('data-compare') === '1',
	filter: 'all',
	search: '',
	saving: false
	};

	var searchInput = document.getElementById('ce-search');
	var saveButton = document.getElementById('ce-save');
	var statusEl = document.getElementById('ce-status');
	var progress = document.getElementById('ce-progress');
	var progressText = document.getElementById('ce-progress-text');
	var noMatch = document.getElementById('ce-nomatch');
	var copyAllButton = document.getElementById('ce-copy-all');
	var chips = [].slice.call(editor.querySelectorAll('.ce-chip'));

	var rows = [].slice.call(editor.querySelectorAll('.ce-row')).map(function (el, i) {
	el.setAttribute('data-i', String(i));
	var ta = el.querySelector('.ce-input');
	var src = el.querySelector('.ce-src');
	var row = {
		el: el,
		key: el.getAttribute('data-key') || '',
		ta: ta,
		warnEl: el.querySelector('.ce-warn'),
		sameEl: el.querySelector('.ce-same'),
		source: src ? src.textContent : '',
		saved: '',
		unused: el.getAttribute('data-unused') === '1',
		empty: true, dirty: false, warn: false, same: false
	};
	row.comparable = state.compare && isComparable(row.source);
	row.hay = (row.key + '\n' + row.source).toLowerCase();
	return row;
	});

	function value(row) { return normalize(row.ta.value); }

	/* the baseline is what the browser put in the field (the newline emitted right
	 after <textarea> is swallowed by the HTML parser, so ta.value is the reference) */
	rows.forEach(function (row) { row.saved = value(row); });

	function updateRow(row) {
	var v = value(row);
	row.empty = v === '';
	row.el.classList.toggle('is-empty', row.empty);
	row.dirty = v !== row.saved;
	var warn = null;
	if (!row.empty && row.source !== '') {
		var d = diffTokens(row.source, v);
		if (d.missing.length || d.extra.length) { warn = d; }
	}
	row.warn = warn !== null;
	row.same = row.comparable && !row.empty && v === normalize(row.source);
	row.el.classList.toggle('is-same', row.same);
	if (row.sameEl) { row.sameEl.hidden = !row.same; }
	row.el.classList.toggle('is-dirty', row.dirty);
	row.el.classList.toggle('has-warning', row.warn);
	if (row.warnEl) {
		if (warn) {
		var msg = str('PLACEHOLDER_WARN');
		if (warn.missing.length) { msg += ' ' + str('PLACEHOLDER_MISSING') + ' ' + warn.missing.join(' '); }
		if (warn.extra.length) { msg += ' ' + str('PLACEHOLDER_EXTRA') + ' ' + warn.extra.join(' '); }
		row.warnEl.textContent = msg;
		row.warnEl.hidden = false;
		} else {
		row.warnEl.textContent = '';
		row.warnEl.hidden = true;
		}
	}
	}

	function anyDirty() {
	return rows.some(function (r) { return r.dirty; });
	}

	function refreshSummary() {
	var c = { all: rows.length, missing: 0, changed: 0, same: 0, warnings: 0, unused: 0 };
	rows.forEach(function (r) {
		if (r.empty) { c.missing++; }
		if (r.dirty) { c.changed++; }
		if (r.warn) { c.warnings++; }
		if (r.same) { c.same++; }
		if (r.unused) { c.unused++; }
	});
	Object.keys(c).forEach(function (name) {
		var el = editor.querySelector('[data-count="' + name + '"]');
		if (el) { el.textContent = String(c[name]); }
	});
	var done = rows.length - c.missing;
	if (progress) { progress.max = rows.length; progress.value = done; }
	if (progressText) { progressText.textContent = format(str('PROGRESS_TEXT'), { done: done, total: rows.length }); }
	if (saveButton) { saveButton.disabled = state.saving || !anyDirty(); }
	updateCopyButton();
	}

	function matches(row) {
	switch (state.filter) {
		case 'missing': if (!row.empty) { return false; } break;
		case 'changed': if (!row.dirty) { return false; } break;
		case 'warnings': if (!row.warn) { return false; } break;
		case 'same': if (!row.same) { return false; } break;
		case 'unused': if (!row.unused) { return false; } break;
	}
	if (state.search !== '') {
		return row.hay.indexOf(state.search) !== -1 || value(row).toLowerCase().indexOf(state.search) !== -1;
	}
	return true;
	}

	/* Re-filtering only happens when the filter or search changes (or after a save),
	 never while typing, so a row does not vanish under your cursor. */
	function applyFilter() {
	var visible = 0;
	rows.forEach(function (row) {
		var show = matches(row);
		row.el.hidden = !show;
		if (show) { visible++; }
	});
	if (noMatch) {
		noMatch.hidden = visible !== 0;
		noMatch.textContent = visible === 0 ? str('NO_MATCH') : '';
	}
	updateCopyButton();
	}

	function setStatus(text, kind, undo) {
	if (!statusEl) { return; }
	statusEl.textContent = text;
	statusEl.className = 'ce-status' + (text ? ' ce-status-' + (kind || 'info') : '');
	if (undo) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'ce-link-button';
		button.textContent = str('UNDO');
		button.addEventListener('click', undo);
		statusEl.appendChild(document.createTextNode(' '));
		statusEl.appendChild(button);
	}
	}

	/* ---- copy the source into every empty field of the rows that are shown ---- */
	function copyableRows() {
	return rows.filter(function (r) { return !r.el.hidden && r.empty && r.source !== ''; });
	}

	function updateCopyButton() {
	if (!copyAllButton) { return; }
	var n = copyableRows().length;
	copyAllButton.textContent = format(str('COPY_ALL'), { count: n });
	copyAllButton.disabled = n === 0;
	}

	function copyAll() {
	var targets = copyableRows();
	if (!targets.length) { return; }
	targets.forEach(function (row) { row.ta.value = row.source; updateRow(row); });
	targets.forEach(function (row) { autosize(row.ta); });
	refreshSummary();
	setStatus(format(str('COPIED_ALL'), { count: targets.length }), 'info', function undo() {
		var cleared = 0;
		targets.forEach(function (row) {
		if (value(row) === normalize(row.source)) { // only what is still the untouched copy
			row.ta.value = '';
			updateRow(row);
			autosize(row.ta);
			cleared++;
		}
		});
		refreshSummary();
		setStatus(format(str('UNDONE'), { count: cleared }), 'info');
	});
	}

	if (copyAllButton) { copyAllButton.addEventListener('click', copyAll); }

	function autosize(ta) {
	ta.style.height = 'auto';
	if (ta.scrollHeight) { ta.style.height = ta.scrollHeight + 'px'; }
	}

	function onInput(row) {
	updateRow(row);
	autosize(row.ta);
	refreshSummary();
	if (anyDirty()) { setStatus(str('UNSAVED'), 'info'); }
	}

	/* ---- events ---- */
	editor.addEventListener('input', function (event) {
	var el = event.target;
	if (el && el.classList && el.classList.contains('ce-input')) {
		var rowEl = el.closest('.ce-row');
		if (rowEl) { onInput(rows[Number(rowEl.getAttribute('data-i'))]); }
	}
	});

	editor.addEventListener('focusin', function (event) {
	if (event.target && event.target.classList && event.target.classList.contains('ce-input')) {
		autosize(event.target);
	}
	});

	editor.addEventListener('click', function (event) {
	var el = event.target;
	while (el && el !== editor && !(el.getAttribute && el.getAttribute('data-action') === 'copy')) {
		el = el.parentNode;
	}
	if (el && el !== editor) {
		var rowEl = el.closest('.ce-row');
		if (rowEl) {
		var row = rows[Number(rowEl.getAttribute('data-i'))];
		row.ta.value = row.source;
		onInput(row);
		row.ta.focus();
		}
	}
	});

	chips.forEach(function (chip) {
	chip.addEventListener('click', function () {
		state.filter = chip.getAttribute('data-filter') || 'all';
		chips.forEach(function (c) {
		var on = c === chip;
		c.classList.toggle('is-active', on);
		c.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		applyFilter();
	});
	});

	var searchTimer = null;
	if (searchInput) {
	searchInput.addEventListener('input', function () {
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(function () {
		state.search = searchInput.value.trim().toLowerCase();
		applyFilter();
		}, 120);
	});
	}

	window.addEventListener('beforeunload', function (event) {
	if (anyDirty()) {
		event.preventDefault();
		event.returnValue = '';
	}
	});

	document.addEventListener('keydown', function (event) {
	if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 's') {
		event.preventDefault();
		save();
	}
	});

	if (saveButton) { saveButton.addEventListener('click', save); }

	/* ---- saving ---- */
	function collectPairs() {
	var pairs = [];
	rows.forEach(function (row) {
		var v = value(row);
		if (v !== '') { pairs.push([row.key, v]); }
	});
	return pairs;
	}

	function post(nonce, version, pairs) {
	var body = new window.URLSearchParams();
	body.set('nonce', nonce);
	body.set('version', version);
	body.set('source', state.source);
	body.set('target', state.target);
	body.set('data', JSON.stringify(pairs));
	return window.fetch(state.endpoint, {
		method: 'POST',
		body: body,
		credentials: 'same-origin',
		headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
	}).then(function (response) {
		return response.text().then(function (text) {
		var json = null;
		try { json = JSON.parse(text); } catch (e) { json = null; }
		return json;
		});
	});
	}

	function fail(code) { return { code: code }; }

	/* One save, with at most one automatic retry for an expired nonce and one
	 "overwrite?" question if the file changed meanwhile. */
	function attempt(pairs, nonce, version, ctx) {
	return post(nonce, version, pairs).then(function (json) {
		if (!json || typeof json !== 'object') { throw fail('response'); }
		if (json.nonce) { state.nonce = json.nonce; }
		if (json.ok) { return json; }
		if (json.error === 'nonce' && !ctx.nonceRetried && json.nonce) {
		ctx.nonceRetried = true;
		return attempt(pairs, json.nonce, version, ctx);
		}
		if (json.error === 'conflict' && !ctx.asked && typeof json.version === 'string') {
		ctx.asked = true;
		if (window.confirm(str('CONFLICT_CONFIRM'))) {
			return attempt(pairs, state.nonce, json.version, ctx);
		}
		}
		throw fail(json.error || 'response');
	});
	}

	function errorText(code) {
	var map = {
		nonce: 'ERR_NONCE', conflict: 'ERR_CONFLICT', write_failed: 'ERR_WRITE_FAILED',
		backup_failed: 'ERR_BACKUP_FAILED', verify_failed: 'ERR_VERIFY_FAILED',
		invalid: 'ERR_INVALID', data: 'ERR_DATA', network: 'ERR_NETWORK', response: 'ERR_RESPONSE',
		broken: 'ERR_BROKEN', unsupported: 'ERR_STRUCTURE'
	};
	return str(map[code] || 'ERR_RESPONSE');
	}

	function save() {
	if (state.saving || !anyDirty() || typeof window.fetch !== 'function') { return Promise.resolve(false); }
	state.saving = true;
	refreshSummary();
	setStatus(str('SAVING'), 'info');
	var pairs = collectPairs();
	return attempt(pairs, state.nonce, state.version, { nonceRetried: false, asked: false })
		.then(function (json) {
		state.version = json.version || state.version;
		rows.forEach(function (row) { row.saved = value(row); updateRow(row); });
		state.saving = false;
		refreshSummary();
		applyFilter();
		setStatus(format(str('SAVED'), { count: json.count, time: new Date().toLocaleTimeString() }), 'ok');
		return true;
		})
		.catch(function (err) {
		state.saving = false;
		refreshSummary();
		var code = err && err.code ? err.code : 'network';
		setStatus(errorText(code), 'error');
		return false;
		});
	}

	/* ---- initial state ---- */
	rows.forEach(updateRow);
	refreshSummary();
	applyFilter();
}

ready(function () {
	var root = document.querySelector('.ce-translator');
	if (!root) { return; }
	initSelectScreen(root);
	var editor = document.getElementById('ce-editor');
	if (editor) { initEditor(editor); }
});
}());

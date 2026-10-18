<?php
/*
 * CE-Translator: editor data and the JSON save endpoint.
 * Compatible with PHP 7.4 - 8.4.
 *
 * The endpoint runs on GetSimple's "admin-pre-header" hook: that fires after
 * login_cookie_check() and before any HTML is sent, so the response can be
 * pure JSON. Every response carries a fresh nonce: nonces only live about an
 * hour, and a long translation session must not lose its work to that.
 * (Handing a fresh nonce to an authenticated request is safe: a cross-site
 * attacker cannot read the response.)
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/** Identifies the current content of a language file ('none' = does not exist yet). */
function ce_translator_file_version($plugin, $language) {
    $file = ce_translator_lang_file($plugin, $language);
    if ($file === false || !is_file($file)) {
        return 'none';
    }
    $hash = @sha1_file($file);
    return is_string($hash) ? $hash : 'none';
}

/** Languages written right-to-left. */
function ce_translator_is_rtl($language) {
    return in_array(substr((string) $language, 0, 2), array('ar', 'he', 'fa', 'ur'), true);
}

/**
 * A text is worth comparing with its source only if it contains a real word
 * (four letters in a row once tags are removed): "/", "123" or "OK" are the same
 * in every language. The JavaScript editor uses exactly the same rule.
 */
function ce_translator_is_comparable($text) {
    return preg_match('/\p{L}{4,}/u', preg_replace('/<[^>]*>/', '', (string) $text)) === 1;
}

/** en_US and en_GB: same language family, so "same as source" would flag nearly everything. */
function ce_translator_same_family($a, $b) {
    $fa = explode('_', (string) $a);
    $fb = explode('_', (string) $b);
    return $fa[0] === $fb[0];
}

/** "es_ES" -> "es-ES" for the HTML lang attribute (spell checking). */
function ce_translator_html_lang($language) {
    return str_replace('_', '-', (string) $language);
}

/**
 * Rows of the editor. Order: keys of the source file (in file order), then
 * keys found in the code but missing from the source (sorted), then keys that
 * only exist in the target file. 'unused' means "not found in the code" and is
 * only reported when the scan found at least one key (otherwise it is noise).
 */
function ce_translator_editor_rows($plugin, $source, $target) {
    $codeKeys = ce_translator_scan_keys($plugin);
    $codeSet = array();
    foreach ($codeKeys as $k) {
        $codeSet[$k] = true;
    }
    $src = $source === '' ? array() : ce_translator_load_language($plugin, $source);
    $tgt = ce_translator_load_language($plugin, $target);

    $order = array();
    foreach (array_keys($src) as $k) {
        $order[(string) $k] = true;
    }
    foreach ($codeKeys as $k) {
        $order[(string) $k] = true;
    }
    foreach (array_keys($tgt) as $k) {
        $order[(string) $k] = true;
    }
    $structured = ce_translator_structured_keys($plugin, $source, $target);
    $compare = $source !== '' && !ce_translator_same_family($source, $target);
    $rows = array();
    foreach (array_keys($order) as $k) {
        $k = (string) $k;
        if (isset($structured[$k])) {
            continue;   // arrays etc.: kept unchanged by the writer, not editable as text
        }
        $srcText = isset($src[$k]) ? $src[$k] : '';
        $tgtText = isset($tgt[$k]) ? $tgt[$k] : '';
        $rows[] = array(
            'key'    => $k,
            'source' => $srcText,
            'target' => $tgtText,
            'unused' => $codeKeys && !isset($codeSet[$k]),
            // browsers hand the editor LF only, so compare the same way here
            'same'   => $compare && $tgtText !== ''
                && str_replace(array("\r\n", "\r"), "\n", $srcText) === str_replace(array("\r\n", "\r"), "\n", $tgtText)
                && ce_translator_is_comparable($srcText),
        );
    }
    return $rows;
}

/** Every key an editor save may contain for this plugin and language pair. */
function ce_translator_allowed_keys($plugin, $source, $target) {
    $keys = ce_translator_scan_keys($plugin);
    if ($source !== '') {
        $keys = array_merge($keys, array_map('strval', array_keys(ce_translator_load_language($plugin, $source))));
    }
    return array_merge($keys, array_map('strval', array_keys(ce_translator_load_language($plugin, $target))));
}

/** Decodes the posted JSON list of [key, text] pairs into an ordered array, or null. */
function ce_translator_decode_pairs($json) {
    if (!is_string($json) || $json === '' || strlen($json) > 8388608) {
        return null;
    }
    $pairs = json_decode($json, true, 8);
    if (!is_array($pairs) || json_last_error() !== JSON_ERROR_NONE || count($pairs) > 50000) {
        return null;
    }
    $texts = array();
    foreach ($pairs as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !isset($pair[0], $pair[1])
            || !is_string($pair[0]) || !is_string($pair[1])) {
            return null;
        }
        $texts[$pair[0]] = $pair[1];
    }
    return $texts;
}

/**
 * Handles one save request. Returns array(http status, payload array).
 * Reads $_GET / $_POST; does not output anything, so it can be tested.
 */
function ce_translator_ajax_handle() {
    $fresh = function_exists('get_nonce') ? get_nonce('save', CE_TRANSLATOR_NONCE_FILE) : '';
    $fail = function ($status, $code, $extra = array()) use ($fresh) {
        return array($status, array_merge(array('ok' => false, 'error' => $code, 'nonce' => $fresh), $extra));
    };

    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $fail(405, 'invalid');
    }
    if (!ce_translator_nonce_ok('save')) {
        return $fail(403, 'nonce');
    }
    $plugin = isset($_GET['plugin']) && is_string($_GET['plugin']) ? $_GET['plugin'] : null;
    $target = isset($_POST['target']) && is_string($_POST['target']) ? $_POST['target'] : '';
    $source = isset($_POST['source']) && is_string($_POST['source']) ? $_POST['source'] : '';
    if ($plugin === null || !ce_translator_is_valid_plugin($plugin) || !ce_translator_valid_language($target)
        || ($source !== '' && !ce_translator_valid_language($source))) {
        return $fail(400, 'invalid');
    }

    $current = ce_translator_file_version($plugin, $target);
    $version = isset($_POST['version']) && is_string($_POST['version']) ? $_POST['version'] : '';
    if ($version !== $current) {
        return $fail(409, 'conflict', array('version' => $current));
    }

    $texts = ce_translator_decode_pairs(isset($_POST['data']) ? $_POST['data'] : null);
    if ($texts === null) {
        return $fail(400, 'data');
    }

    $result = ce_translator_save_language($plugin, $target, $texts, ce_translator_allowed_keys($plugin, $source, $target));
    if (!$result['ok']) {
        return $fail(500, $result['error']);
    }
    return array(200, array(
        'ok'      => true,
        'count'   => $result['count'],
        'backup'  => $result['backup'],
        'version' => ce_translator_file_version($plugin, $target),
        'nonce'   => $fresh,
    ));
}

/** Sends the JSON answer for a save request and stops. Called from the loader's hook. */
function ce_translator_ajax() {
    list($status, $payload) = ce_translator_ajax_handle();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $json === false ? '{"ok":false,"error":"response"}' : $json;
    exit;
}

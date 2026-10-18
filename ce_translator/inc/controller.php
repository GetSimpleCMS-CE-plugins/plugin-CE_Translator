<?php
/*
 * CE-Translator: request handling. Compatible with PHP 7.4 - 8.4.
 *
 * Selecting things (plugin, languages) only reads, so it uses GET and is
 * bookmarkable. Anything that changes a file (restore now, save in step 3) is
 * a POST that must carry a valid GetSimple nonce.
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

define('CE_TRANSLATOR_NONCE_FILE', 'ce_translator');

/** Nonce hidden field for a POST form. */
function ce_translator_nonce_field($action) {
    if (!function_exists('get_nonce')) {
        return '';
    }
    return '<input type="hidden" name="nonce" value="'
         . ce_translator_h(get_nonce($action, CE_TRANSLATOR_NONCE_FILE)) . '">';
}

/** True only for a POST with a valid nonce for $action. Fails closed. */
function ce_translator_nonce_ok($action) {
    return isset($_POST['nonce']) && is_string($_POST['nonce'])
        && function_exists('check_nonce')
        && check_nonce($_POST['nonce'], $action, CE_TRANSLATOR_NONCE_FILE);
}

function ce_translator_notice($type, $key) {
    return array('type' => $type, 'text' => ce_translator_t($key));
}

/** null = nothing selected, false = invalid, '' = GetSimple, else a plugin id. */
function ce_translator_request_plugin() {
    if (!isset($_GET['plugin']) || !is_string($_GET['plugin'])) {
        return null;
    }
    return ce_translator_is_valid_plugin($_GET['plugin']) ? $_GET['plugin'] : false;
}

/**
 * Validates the source/target choice made on the select screen.
 * Returns array('source' => '' | code, 'target' => code, 'is_new' => bool)
 * or a string with the i18n key of the error.
 */
function ce_translator_read_selection($plugin) {
    $source = isset($_GET['source']) && is_string($_GET['source']) ? $_GET['source'] : '';
    $target = isset($_GET['target']) && is_string($_GET['target']) ? $_GET['target'] : '';
    $existing = ce_translator_get_languages($plugin);

    if ($target === '__new__') {
        $target = isset($_GET['newlang']) && is_string($_GET['newlang']) ? trim($_GET['newlang']) : '';
    }
    if (!ce_translator_valid_language($target)) {
        return 'ERR_INVALID_LANGUAGE';
    }
    if ($source !== '') {
        if (!ce_translator_valid_language($source) || !in_array($source, $existing, true)) {
            return 'ERR_SOURCE_MISSING';
        }
        if ($source === $target) {
            return 'ERR_SAME_LANGUAGE';
        }
    }
    return array('source' => $source, 'target' => $target, 'is_new' => !in_array($target, $existing, true));
}

/** Handles the restore POST. Returns a notice array. */
function ce_translator_handle_restore($plugin) {
    if (!ce_translator_nonce_ok('restore')) {
        return ce_translator_notice('error', 'ERR_NONCE');
    }
    $language = isset($_POST['language']) && is_string($_POST['language']) ? $_POST['language'] : '';
    $name = isset($_POST['restore']) && is_string($_POST['restore']) ? $_POST['restore'] : '';
    $r = ce_translator_restore_backup($plugin, $language, $name);
    return $r['ok'] ? ce_translator_notice('updated', 'RESTORE_SUCCESS')
                    : ce_translator_notice('error', 'RESTORE_FAILURE');
}

function ce_translator_controller() {
    $notice = null;
    $plugin = ce_translator_request_plugin();
    if ($plugin === false) {
        $notice = ce_translator_notice('error', 'ERR_INVALID_PLUGIN');
        $plugin = null;
    }

    if ($plugin !== null && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['restore'])) {
        $notice = ce_translator_handle_restore($plugin);
    }

    $selection = null;
    if ($plugin !== null && isset($_GET['view']) && $_GET['view'] === 'edit') {
        $selection = ce_translator_read_selection($plugin);
        if (is_string($selection)) {
            $notice = ce_translator_notice('error', $selection);
            $selection = null;
        }
    }

    ce_translator_view_page_start($notice);
    ce_translator_view_plugin_picker(ce_translator_get_plugins(), $plugin);
    if ($plugin !== null) {
        if ($selection !== null) {
            ce_translator_view_editor($plugin, $selection);
        } else {
            ce_translator_view_overview($plugin);
        }
    }
    ce_translator_view_page_end();
}

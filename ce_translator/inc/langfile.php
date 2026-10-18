<?php
/*
 * CE-Translator: reading, safely writing and backing up language files.
 *
 * Compatible with PHP 7.4 - 8.4.
 *
 * Security notes:
 *  - Texts are always written as single-quoted PHP strings with only "\" and
 *    "'" escaped. Single-quoted strings never interpolate, so "$x", "{$x}" or
 *    "${x}" inside a translation stay plain text.
 *  - Before a file replaces the real one, its tokens are checked against a
 *    whitelist (without executing it).
 *  - Plugin and language always go through the whitelists in scanner.php.
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

require_once dirname(__FILE__) . '/patcher.php';

if (!defined('CE_TRANSLATOR_BACKUPS_KEEP')) {
    define('CE_TRANSLATOR_BACKUPS_KEEP', 10);
}

/* ---------------------------------------------------------------- reading */

/** Sorted list of the language codes that exist for a plugin ('' = GetSimple). */
function ce_translator_get_languages($plugin) {
    $dir = ce_translator_lang_dir($plugin);
    $languages = array();
    if ($dir === false) {
        return $languages;
    }
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return $languages;
    }
    foreach ($entries as $entry) {
        if (substr($entry, -4) !== '.php') {
            continue;
        }
        $code = substr($entry, 0, -4);
        if (ce_translator_valid_language($code) && is_file($dir . $entry)) {
            $languages[] = $code;
        }
    }
    sort($languages, SORT_STRING);
    return $languages;
}

/** Runs a language file in its own scope and returns its $i18n array. */
function ce_translator_include_i18n($ce_translator_file__) {
    $i18n = array();
    include $ce_translator_file__;
    return $i18n;
}

/**
 * Reads one language file.
 * Returns array('texts' => array, 'error' => null|'invalid'|'missing'|'broken').
 * A syntax error in a language file is reported, not fatal.
 */
function ce_translator_read_language($plugin, $language) {
    $result = array('texts' => array(), 'extras' => array(), 'error' => null);
    $file = ce_translator_lang_file($plugin, $language);
    if ($file === false) {
        $result['error'] = 'invalid';
        return $result;
    }
    if (!is_file($file)) {
        $result['error'] = 'missing';
        return $result;
    }
    $i18n = null;
    ob_start();
    try {
        $i18n = ce_translator_include_i18n($file);
    } catch (Throwable $e) {
        $result['error'] = 'broken';
    } finally {
        ob_end_clean();
    }
    if (is_array($i18n)) {
        foreach ($i18n as $key => $text) {
            if (is_string($text) || is_int($text) || is_float($text)) {
                $result['texts'][(string) $key] = (string) $text;
            } else {
                // structured entries (arrays such as GetSimple's TRANSLITERATION table, ...):
                // not editable as text, but they must survive every save untouched
                $result['extras'][(string) $key] = $text;
            }
        }
    } elseif ($result['error'] === null) {
        $result['error'] = 'broken';
    }
    return $result;
}

/** Keys whose value is not a string in the source or target file (kept unchanged, not editable). */
function ce_translator_structured_keys($plugin, $source, $target) {
    $keys = array();
    foreach (array($source, $target) as $language) {
        if ($language !== '' && $language !== null) {
            $r = ce_translator_read_language($plugin, $language);
            foreach (array_keys($r['extras']) as $k) {
                $keys[(string) $k] = true;
            }
        }
    }
    return $keys;
}

/** Texts of one language file, or an empty array if it cannot be read. */
function ce_translator_load_language($plugin, $language) {
    $r = ce_translator_read_language($plugin, $language);
    return $r['texts'];
}

/**
 * Overview for the select screen: all keys (code + every language file) and
 * per language how many are translated.
 * Returns false for an invalid plugin.
 */
function ce_translator_overview($plugin) {
    if (!ce_translator_is_valid_plugin($plugin)) {
        return false;
    }
    $codeKeys = ce_translator_scan_keys($plugin);
    $all = array();
    foreach ($codeKeys as $k) {
        $all[$k] = true;
    }
    $texts = array();
    $info = array();
    $structured = array();
    foreach (ce_translator_get_languages($plugin) as $language) {
        $r = ce_translator_read_language($plugin, $language);
        foreach (array_keys($r['extras']) as $k) {
            $structured[(string) $k] = true;
        }
        $texts[$language] = $r['texts'];
        $info[$language] = array('broken' => $r['error'] === 'broken');
        foreach ($r['texts'] as $k => $unused) {
            $all[(string) $k] = true;
        }
    }
    foreach ($structured as $k => $unused) {
        unset($all[$k]);
    }
    $codeKeys = array_values(array_filter($codeKeys, function ($k) use ($structured) { return !isset($structured[$k]); }));
    $keys = array_map('strval', array_keys($all));
    sort($keys, SORT_STRING);
    $total = count($keys);
    foreach ($info as $language => $unused) {
        $done = 0;
        foreach ($keys as $k) {
            if (isset($texts[$language][$k]) && $texts[$language][$k] !== '') {
                $done++;
            }
        }
        $info[$language]['translated'] = $done;
        $info[$language]['total'] = $total;
        $info[$language]['percent'] = $total > 0 ? (int) floor(100 * $done / $total) : 0;
    }
    return array('code_keys' => $codeKeys, 'keys' => $keys, 'languages' => $info);
}

/* ---------------------------------------------------------------- writing */

/** Keys must be non-empty, reasonably short and free of control characters. */
function ce_translator_valid_key($key) {
    return is_string($key) && $key !== '' && strlen($key) <= 200
        && preg_match('/^[^\x00-\x1F\x7F]+$/D', $key) === 1;
}

/** Escapes a value for a single-quoted PHP string. */
function ce_translator_escape($value) {
    $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
    $value = str_replace("\0", '', $value);
    return str_replace(array('\\', "'"), array('\\\\', "\\'"), $value);
}

/**
 * Cleans posted texts: drops invalid keys, non-scalar values and empty
 * strings (empty = not translated). "0" and whitespace are real translations.
 * If $allowedKeys is an array, keys outside it are dropped as well.
 */
function ce_translator_clean_texts($texts, $allowedKeys = null) {
    $clean = array();
    if (!is_array($texts)) {
        return $clean;
    }
    $allowed = null;
    if (is_array($allowedKeys)) {
        $allowed = array();
        foreach ($allowedKeys as $k) {
            $allowed[(string) $k] = true;
        }
    }
    foreach ($texts as $key => $text) {
        $key = (string) $key;
        if (!ce_translator_valid_key($key)) {
            continue;
        }
        if ($allowed !== null && !isset($allowed[$key])) {
            continue;
        }
        if (!is_string($text) && !is_int($text) && !is_float($text)) {
            continue;
        }
        $text = str_replace("\0", '', str_replace(array("\r\n", "\r"), "\n", (string) $text));
        if ($text === '') {
            continue;
        }
        $clean[$key] = $text;
    }
    return $clean;
}

/**
 * A scalar or array as PHP source built only from single-quoted literals,
 * numbers, true/false/null and [ ] arrays. Returns null for anything else
 * (objects, resources, INF/NAN): such a value cannot be written safely.
 */
function ce_translator_export_value($value) {
    if (is_string($value)) {
        return ce_translator_literal($value);
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return is_finite($value) ? var_export($value, true) : null;
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }
    if (is_array($value)) {
        $parts = array();
        foreach ($value as $k => $v) {
            $ev = ce_translator_export_value($v);
            if ($ev === null) {
                return null;
            }
            $parts[] = (is_int($k) ? (string) $k : ce_translator_literal($k)) . ' => ' . $ev;
        }
        return '[' . implode(', ', $parts) . ']';
    }
    return null;
}

/**
 * Builds a complete language file (used for NEW files, and as the fallback
 * for existing files the patcher cannot handle). $extras are the structured
 * entries (arrays, ...) to carry along. Returns null if one cannot be exported.
 */
function ce_translator_export_php($texts, $extras = array()) {
    $lines = array();
    foreach ($texts as $key => $text) {
        $lines[] = "    '" . ce_translator_escape((string) $key) . "' => '" . ce_translator_escape($text) . "'";
    }
    foreach ($extras as $key => $value) {
        $ev = ce_translator_export_value($value);
        if ($ev === null) {
            return null;
        }
        $lines[] = "    '" . ce_translator_escape((string) $key) . "' => " . $ev;
    }
    return "<?php\n\n\$i18n = [\n" . ($lines ? "\n" . implode(",\n", $lines) . "\n" : '') . "\n];\n";
}

/**
 * Checks generated code WITHOUT running it: it must parse, and may contain
 * nothing but "$i18n = ['..' => value, ...];" built from single-quoted
 * literals, numbers, true/false/null and nested [ ]. Skipped (returns
 * true) if the tokenizer extension is missing.
 */
function ce_translator_verify_php($code) {
    if (!function_exists('token_get_all')) {
        return true;
    }
    try {
        $tokens = token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        return false;
    }
    foreach ($tokens as $token) {
        if (is_string($token)) {
            if (strpos('=,;-[]', $token) === false) {
                return false;
            }
            continue;
        }
        switch ($token[0]) {
            case T_OPEN_TAG:
            case T_WHITESPACE:
            case T_DOUBLE_ARROW:
            case T_LNUMBER:
            case T_DNUMBER:
                break;
            case T_STRING:
                if (!in_array(strtolower($token[1]), array('true', 'false', 'null'), true)) {
                    return false;
                }
                break;
            case T_VARIABLE:
                if ($token[1] !== '$i18n') {
                    return false;
                }
                break;
            case T_CONSTANT_ENCAPSED_STRING:
                if ($token[1][0] !== "'") {
                    return false;
                }
                break;
            default:
                return false;
        }
    }
    return true;
}

/**
 * Writes a temp file in the same folder, optionally lets $verify inspect it
 * (it gets the temp path), then renames it over the target.
 * Returns true, 'write_failed' or 'verify_failed'.
 */
function ce_translator_atomic_write($file, $content, $verify = null) {
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return 'write_failed';
    }
    $tmp = @tempnam($dir, '.cet');
    if ($tmp === false) {
        return 'write_failed';
    }
    $written = @file_put_contents($tmp, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        @unlink($tmp);
        return 'write_failed';
    }
    if ($verify !== null && !call_user_func($verify, $tmp)) {
        @unlink($tmp);
        return 'verify_failed';
    }
    // Keep the mode of an existing file; a new file gets the site's GSCHMOD
    // (the same setting GetSimple uses for uploads) or 0644.
    $perms = is_file($file) ? (@fileperms($file) & 0777) : (defined('GSCHMOD') ? GSCHMOD : 0644);
    @chmod($tmp, $perms);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return 'write_failed';
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    return true;
}

/**
 * Loads a candidate language file and checks it holds exactly $expected:
 * every text identical, structured entries equal, no extra and no missing keys.
 * The candidate is the site's own file plus single-quoted literals we generated
 * (verified separately), so loading it runs nothing new.
 */
function ce_translator_verify_loaded($path, $expected) {
    $got = null;
    ob_start();
    try {
        $got = ce_translator_include_i18n($path);
    } catch (Throwable $e) {
        $got = null;
    } finally {
        ob_end_clean();
    }
    if (!is_array($got) || count($got) !== count($expected)) {
        return false;
    }
    foreach ($expected as $k => $v) {
        if (!array_key_exists($k, $got)) {
            return false;
        }
        if (is_string($v)) {
            if (!(is_string($got[$k]) || is_int($got[$k]) || is_float($got[$k])) || (string) $got[$k] !== $v) {
                return false;
            }
        } elseif ($got[$k] != $v) {
            return false;
        }
    }
    return true;
}

/* ---------------------------------------------------------------- backups */

function ce_translator_backup_dir() {
    return ce_translator_dir(GSBACKUPSPATH) . 'ce_translator/';
}

/** Creates the backup folder (protected from web access) if needed. */
function ce_translator_ensure_backup_dir() {
    $dir = ce_translator_backup_dir();
    if (!is_dir($dir)) {
        $mode = defined('GSCHMOD') ? GSCHMOD : 0755;
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            return false;
        }
    }
    if (!is_file($dir . '.htaccess')) {
        @file_put_contents($dir . '.htaccess',
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . 'index.html')) {
        @file_put_contents($dir . 'index.html', '');
    }
    return is_writable($dir) ? $dir : false;
}

/** "_core" can never collide with a plugin id: those cannot start with "_". */
function ce_translator_backup_prefix($plugin, $language) {
    return ($plugin === '' ? '_core' : $plugin) . '__' . $language . '__';
}

/** True if $name is a backup file of exactly this plugin and language. */
function ce_translator_backup_belongs($name, $plugin, $language) {
    if (!is_string($name) || !ce_translator_valid_language($language) || !ce_translator_is_valid_plugin($plugin)) {
        return false;
    }
    $prefix = ce_translator_backup_prefix($plugin, $language);
    if (strncmp($name, $prefix, strlen($prefix)) !== 0) {
        return false;
    }
    return preg_match('/^\d{8}-\d{6}-\d{2}\.bak$/D', substr($name, strlen($prefix))) === 1;
}

/** Backups for one plugin/language, newest first: array of name + time. */
function ce_translator_list_backups($plugin, $language) {
    $list = array();
    if (!ce_translator_valid_language($language) || !ce_translator_is_valid_plugin($plugin)) {
        return $list;
    }
    $dir = ce_translator_backup_dir();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return $list;
    }
    $names = array();
    foreach ($entries as $entry) {
        if (ce_translator_backup_belongs($entry, $plugin, $language)) {
            $names[] = $entry;
        }
    }
    rsort($names, SORT_STRING);
    foreach ($names as $name) {
        $list[] = array('name' => $name, 'time' => (int) @filemtime($dir . $name));
    }
    return $list;
}

/** Deletes all but the newest $keep backups of one plugin/language. */
function ce_translator_prune_backups($plugin, $language, $keep = CE_TRANSLATOR_BACKUPS_KEEP) {
    $list = ce_translator_list_backups($plugin, $language);
    foreach (array_slice($list, max(0, (int) $keep)) as $item) {
        @unlink(ce_translator_backup_dir() . $item['name']);
    }
}

/**
 * Copies the current language file to the backup folder.
 * Returns the backup name, '' if there was nothing to back up, false on failure.
 */
function ce_translator_create_backup($plugin, $language) {
    $file = ce_translator_lang_file($plugin, $language);
    if ($file === false) {
        return false;
    }
    if (!is_file($file)) {
        return '';
    }
    $dir = ce_translator_ensure_backup_dir();
    if ($dir === false) {
        return false;
    }
    // Fixed-width counter, so names sort correctly even for saves in the same
    // second. It is max+1 (never "first free"): after pruning, a freed low
    // number would make a brand-new backup look like the oldest one.
    $base = ce_translator_backup_prefix($plugin, $language) . date('Ymd-His') . '-';
    $max = 0;
    foreach ((array) @scandir($dir) as $entry) {
        if (strncmp($entry, $base, strlen($base)) === 0
            && preg_match('/^(\d{2})\.bak$/D', substr($entry, strlen($base)), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    if ($max >= 99) {
        return false;
    }
    $name = $base . sprintf('%02d', $max + 1) . '.bak';
    return @copy($file, $dir . $name) ? $name : false;
}

/* ------------------------------------------------------------ save / undo */

/**
 * Saves a translation.
 *
 * An existing file is patched in place (see patcher.php); a new file, or one
 * the patcher cannot understand, is written from scratch. Structured entries
 * (arrays) are always carried over untouched. The candidate file is loaded and
 * compared with what was asked for before it replaces the real one.
 *
 * Returns array('ok' => true, 'backup' => name|'', 'count' => n, 'mode' => 'new'|'patch'|'rewrite'|'unchanged')
 *      or array('ok' => false, 'error' => 'invalid'|'broken'|'unsupported'|'backup_failed'|'verify_failed'|'write_failed').
 */
function ce_translator_save_language($plugin, $language, $texts, $allowedKeys = null) {
    $file = ce_translator_lang_file($plugin, $language);
    if ($file === false) {
        return array('ok' => false, 'error' => 'invalid');
    }
    $exists = is_file($file);
    $read = $exists ? ce_translator_read_language($plugin, $language)
                    : array('texts' => array(), 'extras' => array(), 'error' => null);
    if ($read['error'] === 'broken') {
        return array('ok' => false, 'error' => 'broken');   // never overwrite a file we cannot read
    }

    $clean = ce_translator_clean_texts($texts, $allowedKeys);
    foreach ($read['extras'] as $k => $unused) {
        unset($clean[(string) $k]);                           // structured entries are not edited here
    }
    $structured = array();
    foreach ($read['extras'] as $k => $unused) {
        $structured[(string) $k] = true;
    }

    $expected = array();
    foreach ($read['extras'] as $k => $v) {
        $expected[(string) $k] = $v;
    }
    foreach ($clean as $k => $v) {
        $expected[(string) $k] = $v;
    }

    $code = null;
    $mode = 'new';
    if ($exists) {
        $existing = @file_get_contents($file);
        if (!is_string($existing)) {
            return array('ok' => false, 'error' => 'write_failed');
        }
        $code = ce_translator_patch_php($existing, $clean, $read['texts'], $structured);
        $mode = 'patch';
        if ($code === $existing) {
            return array('ok' => true, 'backup' => '', 'count' => count($clean), 'mode' => 'unchanged');
        }
    }
    if ($code === null) {
        $mode = $exists ? 'rewrite' : 'new';
        $code = ce_translator_export_php($clean, $read['extras']);
        if ($code === null) {
            return array('ok' => false, 'error' => 'unsupported');
        }
        if (!ce_translator_verify_php($code)) {
            return array('ok' => false, 'error' => 'verify_failed');
        }
    }

    $backup = ce_translator_create_backup($plugin, $language);
    if ($backup === false) {
        return array('ok' => false, 'error' => 'backup_failed');
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        $dmode = defined('GSCHMOD') ? GSCHMOD : 0755;
        if (!@mkdir($dir, $dmode, true) && !is_dir($dir)) {
            return array('ok' => false, 'error' => 'write_failed');
        }
    }
    $written = ce_translator_atomic_write($file, $code, function ($path) use ($expected) {
        return ce_translator_verify_loaded($path, $expected);
    });
    if ($written !== true) {
        return array('ok' => false, 'error' => $written);
    }
    ce_translator_prune_backups($plugin, $language);
    return array('ok' => true, 'backup' => $backup, 'count' => count($clean), 'mode' => $mode);
}

/**
 * Restores a backup over the language file. The current file is backed up
 * first, so a restore can itself be undone.
 * Returns array('ok' => true) or array('ok' => false, 'error' => 'invalid'|'missing'|'backup_failed'|'write_failed').
 */
function ce_translator_restore_backup($plugin, $language, $name) {
    $file = ce_translator_lang_file($plugin, $language);
    if ($file === false || !ce_translator_backup_belongs($name, $plugin, $language)) {
        return array('ok' => false, 'error' => 'invalid');
    }
    $path = ce_translator_backup_dir() . $name;
    $content = is_file($path) ? @file_get_contents($path) : false;
    if (!is_string($content)) {
        return array('ok' => false, 'error' => 'missing');
    }
    if (ce_translator_create_backup($plugin, $language) === false) {
        return array('ok' => false, 'error' => 'backup_failed');
    }
    if (ce_translator_atomic_write($file, $content) !== true) {
        return array('ok' => false, 'error' => 'write_failed');
    }
    ce_translator_prune_backups($plugin, $language);
    return array('ok' => true);
}

<?php
/*
 * CE-Translator: plugin, source file and i18n key scanner.
 *
 * Compatible with PHP 7.4 - 8.4. Nothing in here calls a GetSimple function;
 * it only relies on the GSROOTPATH, GSADMINPATH, GSLANGPATH and GSPLUGINPATH
 * constants, which makes it easy to test outside GetSimple.
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/**
 * A language code such as en_US, es_ES or pl_PL (also "ast" or "ast_ES").
 * This whitelist is what stops a request parameter from ever becoming a path
 * like "../../x". /D makes "$" match only at the very end of the string.
 */
function ce_translator_valid_language($language) {
    return is_string($language) && preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D', $language) === 1;
}

/** A plugin id as used in plugins/<id>.php: letters, digits, "_" and "-". */
function ce_translator_valid_plugin_name($plugin) {
    return is_string($plugin) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]*$/D', $plugin) === 1;
}

/** Normalised directory path with exactly one trailing slash. */
function ce_translator_dir($path) {
    return rtrim((string) $path, '/\\') . '/';
}

/** All plugin ids found as plugins/<id>.php, sorted case-insensitively. */
function ce_translator_get_plugins() {
    $plugins = array();
    $entries = @scandir(ce_translator_dir(GSPLUGINPATH));
    if (!is_array($entries)) {
        return $plugins;
    }
    foreach ($entries as $entry) {
        if (strtolower(substr($entry, -4)) !== '.php') {
            continue;
        }
        $name = substr($entry, 0, -4);
        if (!ce_translator_valid_plugin_name($name) || is_dir(ce_translator_dir(GSPLUGINPATH) . $entry)) {
            continue;
        }
        $plugins[] = $name;
    }
    sort($plugins, SORT_NATURAL | SORT_FLAG_CASE);
    return $plugins;
}

/**
 * '' means GetSimple itself, anything else must be a real plugin on disk.
 * Every function that touches the filesystem goes through this check.
 */
function ce_translator_is_valid_plugin($plugin) {
    if (!is_string($plugin)) {
        return false;
    }
    if ($plugin === '') {
        return true;
    }
    return ce_translator_valid_plugin_name($plugin) && in_array($plugin, ce_translator_get_plugins(), true);
}

/** Directory holding the language files, or false for an invalid plugin. */
function ce_translator_lang_dir($plugin) {
    if (!ce_translator_is_valid_plugin($plugin)) {
        return false;
    }
    if ($plugin === '') {
        return ce_translator_dir(GSLANGPATH);
    }
    return ce_translator_dir(GSPLUGINPATH) . $plugin . '/lang/';
}

/** Full path of one language file, or false if plugin or language is invalid. */
function ce_translator_lang_file($plugin, $language) {
    if (!ce_translator_valid_language($language)) {
        return false;
    }
    $dir = ce_translator_lang_dir($plugin);
    return $dir === false ? false : $dir . $language . '.php';
}

/**
 * The PHP files that may contain i18n calls: the plugin file plus everything
 * below its folder (any depth), or index.php plus admin/ for GetSimple itself.
 * Language folders are skipped, as are VCS and library folders.
 */
function ce_translator_get_php_files($plugin) {
    if (!ce_translator_is_valid_plugin($plugin)) {
        return array();
    }
    $files = array();
    if ($plugin === '') {
        $main = ce_translator_dir(GSROOTPATH) . 'index.php';
        $dir = ce_translator_dir(GSADMINPATH);
    } else {
        $main = ce_translator_dir(GSPLUGINPATH) . $plugin . '.php';
        $dir = ce_translator_dir(GSPLUGINPATH) . $plugin . '/';
    }
    if (is_file($main)) {
        $files[] = $main;
    }
    if (!is_dir($dir)) {
        return $files;
    }

    $skip = array('lang', '.git', '.svn', 'node_modules', 'vendor');
    try {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($skip) {
                if ($current->isLink()) {
                    return false;
                }
                if ($iterator->hasChildren()) {
                    return !in_array($current->getFilename(), $skip, true);
                }
                return $current->isFile()
                    && strtolower($current->getExtension()) === 'php'
                    && $current->getSize() <= 2097152;
            }
        );
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            $files[] = $file->getPathname();
        }
    } catch (Exception $e) {
        // unreadable folder: return what we have
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

/** Namespaces a source file registers with i18n_merge('name'...). */
function ce_translator_extract_namespaces($content) {
    $found = array();
    if (preg_match_all('/i18n_merge\s*\(\s*([\'"])([^\'"\/]+)\1/', (string) $content, $m)) {
        foreach ($m[2] as $ns) {
            $found[(string) $ns] = true;
        }
    }
    return array_map('strval', array_keys($found));
}

/**
 * i18n keys used in one source file.
 *
 * $namespaces is null for GetSimple itself (keys have no prefix) or a list of
 * namespaces for a plugin: only 'ns/KEY' style keys of those namespaces count,
 * so a plugin that happens to print another plugin's string does not pull that
 * plugin's keys into its own list. Dynamically built keys cannot be detected.
 */
function ce_translator_extract_keys($content, $namespaces) {
    $content = (string) $content;
    if ($namespaces === null) {
        $prefix = '';
    } else {
        $alternatives = array();
        foreach ($namespaces as $ns) {
            $alternatives[] = preg_quote($ns, '/');
        }
        if (!$alternatives) {
            return array();
        }
        $prefix = '(?:' . implode('|', $alternatives) . ')\/';
    }
    $q = '[\'"]';
    $key = '([^\'"\/]+)';
    $patterns = array(
        '/\$i18n\s*\[\s*' . $q . $prefix . $key . $q . '\s*\]/',
        '/\bi18n(?:_r)?\s*\(\s*' . $q . $prefix . $key . $q . '\s*[\),]/',
    );
    $keys = array();
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $m)) {
            foreach ($m[1] as $k) {
                $keys[(string) $k] = true;
            }
        }
    }
    return array_map('strval', array_keys($keys));
}

/** Sorted, de-duplicated list of the translatable keys used in the code. */
function ce_translator_scan_keys($plugin) {
    $contents = array();
    foreach (ce_translator_get_php_files($plugin) as $file) {
        $c = @file_get_contents($file);
        if (is_string($c)) {
            $contents[] = $c;
        }
    }
    if ($plugin === '') {
        $namespaces = null;
    } else {
        $namespaces = array($plugin);
        foreach ($contents as $c) {
            $namespaces = array_merge($namespaces, ce_translator_extract_namespaces($c));
        }
        $namespaces = array_values(array_unique($namespaces));
    }
    $keys = array();
    foreach ($contents as $c) {
        foreach (ce_translator_extract_keys($c, $namespaces) as $k) {
            $keys[$k] = true;
        }
    }
    $result = array_map('strval', array_keys($keys));
    sort($result, SORT_STRING);
    return $result;
}

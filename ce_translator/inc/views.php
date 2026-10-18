<?php
/*
 * CE-Translator: HTML output. Compatible with PHP 7.4 - 8.4.
 * Everything dynamic goes through ce_translator_h(). Strings from the plugin's
 * own language files are trusted markup (they may contain a link), like all
 * GetSimple i18n strings.
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

function ce_translator_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A translated string of this plugin. */
function ce_translator_t($key) {
    return i18n_r('ce_translator/' . $key);
}

/** Link to our page, query values URL-encoded, "&" written as "&amp;". */
function ce_translator_url($params = array()) {
    $params = array_merge(array('id' => 'ce_translator'), $params);
    return 'load.php?' . http_build_query($params, '', '&amp;');
}

/** Human name of a plugin id ('' = GetSimple itself). */
function ce_translator_plugin_label($plugin) {
    global $plugin_info;
    if ($plugin === '') {
        return ce_translator_t('CORE');
    }
    if (isset($plugin_info[$plugin]['name']) && is_string($plugin_info[$plugin]['name'])
        && $plugin_info[$plugin]['name'] !== '') {
        return $plugin_info[$plugin]['name'];
    }
    return $plugin;
}

function ce_translator_view_page_start($notice) {
    echo '<div class="ce-translator">' . "\n";
    echo '<h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="18" height="18" viewBox="0 0 16 16"><path d="M0 0h16v16H0z" fill="none"/><g fill="currentColor"><path d="M4.545 6.714L4.11 8H3l1.862-5h1.284L8 8H6.833l-.435-1.286zm1.634-.736L5.5 3.956h-.049l-.679 2.022z"/><path d="M0 2a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v3h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-3H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zm7.138 9.995q.289.451.63.846c-.748.575-1.673 1.001-2.768 1.292c.178.217.451.635.555.867c1.125-.359 2.08-.844 2.886-1.494c.777.665 1.739 1.165 2.93 1.472c.133-.254.414-.673.629-.89c-1.125-.253-2.057-.694-2.82-1.284c.681-.747 1.222-1.651 1.621-2.757H14V8h-3v1.047h.765c-.318.844-.74 1.546-1.272 2.13a6 6 0 0 1-.415-.492a2 2 0 0 1-.94.31"/></g></svg> ' . ce_translator_t('HEADER') . '</h3>' . "\n";
    if (is_array($notice)) {
        $class = $notice['type'] === 'updated' ? 'ce-notice ce-notice-ok' : 'ce-notice ce-notice-error';
        echo '<div class="' . $class . '" role="status">' . $notice['text'] . '</div>' . "\n";
    }
    echo '<p>' . ce_translator_t('DESCR') . '</p>' . "\n";
}

function ce_translator_view_page_end() {
    echo '</div>' . "\n";
}

/** Plugin dropdown. $current: null = nothing chosen, '' = GetSimple, else id. */
function ce_translator_view_plugin_picker($plugins, $current) {
    echo '<form method="get" action="load.php" class="ce-picker" id="ce-picker">' . "\n";
    echo '<input type="hidden" name="id" value="ce_translator">' . "\n";
    //echo '<label for="ce-plugin">' . ce_translator_t('PLUGIN') . '</label> ';
    echo '<select name="plugin" id="ce-plugin">' . "\n";
    if ($current === null) {
        echo '<option value="" disabled selected>' . ce_translator_h(strip_tags(ce_translator_t('CHOOSE'))) . '</option>' . "\n";
    }
    echo '<option value=""' . ($current === '' ? ' selected' : '') . '>' . ce_translator_h(ce_translator_plugin_label('')) . '</option>' . "\n";
    foreach ($plugins as $p) {
        echo '<option value="' . ce_translator_h($p) . '"' . ($current === $p ? ' selected' : '') . '>'
           . ce_translator_h(ce_translator_plugin_label($p)) . '</option>' . "\n";
    }
    echo '</select> ';
    echo '<input type="submit" class="submit" value="' . ce_translator_h(strip_tags(ce_translator_t('SELECT'))) . '">' . "\n";
    echo '</form>' . "\n";
}

/** Language table, new-language field and backups for one plugin. */
function ce_translator_view_overview($plugin) {
    $ov = ce_translator_overview($plugin);
    if ($ov === false) {
        return;
    }
    $label = ce_translator_h(ce_translator_plugin_label($plugin));
    echo '<h4 class="ce-plugin-name">' . $label . '</h4>' . "\n";

    if (!$ov['code_keys'] && !$ov['languages']) {
        echo '<p class="ce-hint">' . ce_translator_t('DOES_NOT_SUPPORT') . '</p>' . "\n";
        return;
    }

    echo '<p class="ce-stats">' . ce_translator_t('KEYS_IN_CODE') . ': <strong>' . count($ov['code_keys']) . '</strong><br>'
       . ce_translator_t('KEYS_TOTAL') . ': <strong>' . count($ov['keys']) . '</strong></p>' . "\n";

    $names = array_keys($ov['languages']);
    $defaultSource = in_array('en_US', $names, true) && empty($ov['languages']['en_US']['broken']) ? 'en_US' : '';

    echo '<form method="get" action="load.php" id="ce-select-form">' . "\n";
    echo '<input type="hidden" name="id" value="ce_translator">' . "\n";
    echo '<input type="hidden" name="plugin" value="' . ce_translator_h($plugin) . '">' . "\n";
    echo '<input type="hidden" name="view" value="edit">' . "\n";
    echo '<div class="ce-table-wrap"><table class="ce-table">' . "\n<thead><tr>"
       . '<th>' . ce_translator_t('LANGUAGE') . '</th>'
       . '<th>' . ce_translator_t('TRANSLATED') . '</th>'
       . '<th>' . ce_translator_t('PROGRESS') . '</th>'
       . '<th>' . ce_translator_t('SOURCE') . '</th>'
       . '<th>' . ce_translator_t('TARGET') . '</th>'
       . "</tr></thead>\n<tbody>\n";

    foreach ($ov['languages'] as $code => $info) {
        $c = ce_translator_h($code);
        $broken = !empty($info['broken']);
        echo '<tr><td><strong>' . $c . '</strong>';
        if ($broken) {
            echo ' <span class="ce-badge ce-badge-error">' . ce_translator_t('BROKEN') . '</span>';
        }
        echo '</td>';
        if ($broken) {
            echo '<td colspan="2">&ndash;</td>';
        } else {
            echo '<td>' . (int) $info['translated'] . ' / ' . (int) $info['total'] . '</td>';
            echo '<td><progress max="100" value="' . (int) $info['percent'] . '">' . (int) $info['percent'] . '%</progress> '
               . (int) $info['percent'] . '%</td>';
        }
        echo '<td><input type="radio" name="source" value="' . $c . '"'
           . ($code === $defaultSource ? ' checked' : '') . ($broken ? ' disabled' : '')
           . ' aria-label="' . ce_translator_h(strip_tags(ce_translator_t('SOURCE'))) . ' ' . $c . '"></td>';
        echo '<td><input type="radio" name="target" value="' . $c . '"' . ($broken ? ' disabled' : '')
           . ' aria-label="' . ce_translator_h(strip_tags(ce_translator_t('TARGET'))) . ' ' . $c . '"></td>';
        echo "</tr>\n";
    }

    echo '<tr class="ce-new-row"><td colspan="3">' . ce_translator_t('NEW_LANGUAGE')
       . ' <input type="text" class="text ce-newlang" name="newlang" id="ce-newlang" maxlength="6" size="8"'
       . ' pattern="[a-z]{2,3}(_[A-Z]{2})?" placeholder="fr_FR" autocomplete="off"'
       . ' title="' . ce_translator_h(strip_tags(ce_translator_t('NEW_LANGUAGE_HINT'))) . '"></td>';
    echo '<td><label><input type="radio" name="source" value=""' . ($defaultSource === '' ? ' checked' : '') . '> '
       . ce_translator_t('NONE') . '</label></td>';
    echo '<td><label><input type="radio" name="target" value="__new__" id="ce-target-new" checked> '
       . ce_translator_t('NEW') . '</label></td></tr>' . "\n";
    echo "</tbody></table></div>\n";
    echo '<p><input type="submit" class="submit ce-trans-btn" value="' . ce_translator_h(strip_tags(ce_translator_t('TRANSLATE'))) . '"></p>' . "\n";
    echo "</form>\n";

    ce_translator_view_backups($plugin, $names);
}

/** Backups per language, each restorable with a nonce-protected POST. */
function ce_translator_view_backups($plugin, $languages) {
    $blocks = '';
    foreach ($languages as $code) {
        $list = ce_translator_list_backups($plugin, $code);
        if (!$list) {
            continue;
        }
        $blocks .= '<details class="ce-backups"><summary>' . ce_translator_h($code) . ' (' . count($list) . ')</summary><ul>';
        foreach ($list as $item) {
            $when = $item['time'] > 0 ? date('Y-m-d H:i:s', $item['time']) : $item['name'];
            $blocks .= '<li>' . ce_translator_h($when)
                . ' <form method="post" action="' . ce_translator_url(array('plugin' => $plugin)) . '" class="ce-inline">'
                . ce_translator_nonce_field('restore')
                . '<input type="hidden" name="language" value="' . ce_translator_h($code) . '">'
                . '<button type="submit" name="restore" value="' . ce_translator_h($item['name']) . '" class="ce-link-button"'
                . ' data-confirm="' . ce_translator_h(strip_tags(ce_translator_t('RESTORE_CONFIRM'))) . '">'
                . ce_translator_t('RESTORE') . '</button></form></li>';
        }
        $blocks .= '</ul></details>';
    }
    if ($blocks !== '') {
        echo '<h4>' . ce_translator_t('BACKUPS') . '</h4>' . "\n" . $blocks . "\n";
    }
}

/** Strings the editor script needs, as plain text (it inserts them with textContent). */
function ce_translator_js_string_keys() {
    return array(
        'SAVE', 'SAVING', 'SAVED', 'UNSAVED', 'NO_MATCH', 'UNUSED_HINT', 'PLACEHOLDER_WARN',
        'PLACEHOLDER_MISSING', 'PLACEHOLDER_EXTRA', 'PROGRESS_TEXT', 'COPY_SOURCE', 'CONFLICT_CONFIRM',
        'ERR_NONCE', 'ERR_CONFLICT', 'ERR_WRITE_FAILED', 'ERR_BACKUP_FAILED', 'ERR_VERIFY_FAILED',
        'ERR_INVALID', 'ERR_DATA', 'ERR_RESPONSE', 'ERR_NETWORK', 'ERR_BROKEN', 'ERR_STRUCTURE',
        'COPY_ALL', 'COPIED_ALL', 'UNDONE', 'UNDO',
    );
}

function ce_translator_plain($html) {
    return html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8');
}

/** The translation editor. */
function ce_translator_view_editor($plugin, $selection) {
    $source = $selection['source'];
    $target = $selection['target'];
    $rows = ce_translator_editor_rows($plugin, $source, $target);
    $label = ce_translator_h(ce_translator_plugin_label($plugin));
    $hasSource = $source !== '';

    echo '<h4 class="ce-plugin-name">' . $label . ' <span class="ce-langs">'
       . ($hasSource ? ce_translator_h($source) : ce_translator_h(ce_translator_plain(ce_translator_t('NONE'))))
       . ' &rarr; ' . ce_translator_h($target) . '</span></h4>' . "\n";
    echo '<p><a class="ce-back" href="' . ce_translator_url(array('plugin' => $plugin)) . '">&larr; ' . ce_translator_t('BACK') . '</a></p>' . "\n";
    if ($selection['is_new']) {
        echo '<div class="ce-notice ce-notice-info">' . ce_translator_t('NEW_FILE_NOTE') . '</div>' . "\n";
    }
    $structured = count(ce_translator_structured_keys($plugin, $source, $target));
    if ($structured > 0) {
        echo '<div class="ce-notice ce-notice-info">' . str_replace('{count}', (string) $structured, ce_translator_t('STRUCTURED_NOTE')) . '</div>' . "\n";
    }
    if (!$rows) {
        echo '<p class="ce-hint">' . ce_translator_t('DOES_NOT_SUPPORT') . '</p>' . "\n";
        return;
    }

    $missing = 0;
    $unused = 0;
    $same = 0;
    foreach ($rows as $row) {
        if ($row['target'] === '') { $missing++; }
        if ($row['unused']) { $unused++; }
        if ($row['same']) { $same++; }
    }
    $compare = $hasSource && !ce_translator_same_family($source, $target);
    $total = count($rows);
    $done = $total - $missing;
    $copyable = 0;
    foreach ($rows as $row) {
        if ($row['source'] !== '' && $row['target'] === '') { $copyable++; }
    }

    $strings = array();
    foreach (ce_translator_js_string_keys() as $k) {
        $strings[$k] = ce_translator_plain(ce_translator_t($k));
    }
    $json = json_encode($strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo '<script type="application/json" id="ce-i18n">' . ($json === false ? '{}' : $json) . '</script>' . "\n";

    echo '<div class="ce-editor" id="ce-editor"'
       . ' data-endpoint="' . ce_translator_url(array('plugin' => $plugin, 'ajax' => 1)) . '"'
       . ' data-source="' . ce_translator_h($source) . '" data-target="' . ce_translator_h($target) . '"'
       . ' data-compare="' . ($compare ? '1' : '0') . '"'
       . ' data-nonce="' . ce_translator_h(function_exists('get_nonce') ? get_nonce('save', CE_TRANSLATOR_NONCE_FILE) : '') . '"'
       . ' data-version="' . ce_translator_h(ce_translator_file_version($plugin, $target)) . '">' . "\n";

    // toolbar
    echo '<div class="ce-toolbar">' . "\n";
    echo '<div class="ce-toolbar-main"><input type="search" id="ce-search" class="text ce-search" autocomplete="off"'
       . ' placeholder="' . ce_translator_h(ce_translator_plain(ce_translator_t('SEARCH'))) . '"'
       . ' aria-label="' . ce_translator_h(ce_translator_plain(ce_translator_t('SEARCH'))) . '">' . "\n";
    echo '<div class="ce-chips" role="group" aria-label="' . ce_translator_h(ce_translator_plain(ce_translator_t('FILTER'))) . '">';
    $chips = array('all' => array('FILTER_ALL', $total), 'missing' => array('FILTER_MISSING', $missing), 'changed' => array('FILTER_CHANGED', 0));
    if ($compare) {
        $chips['same'] = array('FILTER_SAME', $same);
    }
    $chips['warnings'] = array('FILTER_WARNINGS', 0);
    if ($unused > 0) {
        $chips['unused'] = array('FILTER_UNUSED', $unused);
    }
    foreach ($chips as $name => $chip) {
        echo '<button type="button" class="ce-chip' . ($name === 'all' ? ' is-active' : '') . '" data-filter="' . $name . '"'
           . ' aria-pressed="' . ($name === 'all' ? 'true' : 'false') . '">' . ce_translator_t($chip[0])
           . ' <span class="ce-count" data-count="' . $name . '">' . (int) $chip[1] . '</span></button>';
    }
    echo "</div></div>\n";
    echo '<div class="ce-toolbar-side"><span class="ce-progress-text" id="ce-progress-text"></span> '
       . '<progress id="ce-progress" max="' . $total . '" value="' . $done . '"></progress> '
       . ($hasSource ? '<button type="button" class="ce-copy-all" id="ce-copy-all"' . ($copyable ? '' : ' disabled') . ' title="'
           . ce_translator_h(ce_translator_plain(ce_translator_t('COPY_ALL_HINT'))) . '">'
           . ce_translator_h(str_replace('{count}', (string) $copyable, ce_translator_plain(ce_translator_t('COPY_ALL')))) . '</button> ' : '')
       . '<button type="button" class="submit ce-save" id="ce-save" disabled>' . ce_translator_t('SAVE') . '</button></div>' . "\n";
    echo "</div>\n";
    echo '<div class="ce-status" id="ce-status" role="status" aria-live="polite"></div>' . "\n";

    // rows
    $srcLang = ce_translator_h(ce_translator_html_lang($source));
    $tgtLang = ce_translator_h(ce_translator_html_lang($target));
    $tgtDir = ce_translator_is_rtl($target) ? ' dir="rtl"' : '';
    $srcDir = $hasSource && ce_translator_is_rtl($source) ? ' dir="rtl"' : '';
    echo '<div class="ce-rows' . ($hasSource ? '' : ' ce-rows-nosource') . '" id="ce-rows">' . "\n";
    foreach ($rows as $row) {
        $k = ce_translator_h($row['key']);
        $len = strlen($row['source']) > strlen($row['target']) ? strlen($row['source']) : strlen($row['target']);
        $lines = min(12, max(1, substr_count($row['source'], "\n") + 1 + (int) floor($len / 70)));
        echo '<div class="ce-row" data-key="' . $k . '"' . ($row['unused'] ? ' data-unused="1"' : '') . '>';
        echo '<div class="ce-key"><code>' . $k . '</code>';
        if ($compare) {
            echo ' <span class="ce-badge ce-same"' . ($row['same'] ? '' : ' hidden') . ' title="'
               . ce_translator_h(ce_translator_plain(ce_translator_t('SAME_HINT'))) . '">' . ce_translator_t('FILTER_SAME') . '</span>';
        }
        if ($row['unused']) {
            echo ' <span class="ce-badge" title="' . ce_translator_h(ce_translator_plain(ce_translator_t('UNUSED_HINT'))) . '">'
               . ce_translator_t('FILTER_UNUSED') . '</span>';
        }
        echo '</div>';
        if ($hasSource) {
            echo '<div class="ce-src" lang="' . $srcLang . '"' . $srcDir . '>' . ce_translator_h($row['source']) . '</div>';
        }
        // the leading newline after <textarea> is swallowed by browsers, so a text that starts with a newline survives
        echo '<div class="ce-tgt"><textarea class="ce-input" rows="' . $lines . '" lang="' . $tgtLang . '"' . $tgtDir
           . ' spellcheck="true" aria-label="' . ce_translator_h(ce_translator_plain(ce_translator_t('TARGET'))) . ' ' . $k . '">' . "\n"
           . ce_translator_h($row['target']) . '</textarea>';
        if ($hasSource) {
            echo '<button type="button" class="ce-copy" data-action="copy" title="'
               . ce_translator_h(ce_translator_plain(ce_translator_t('COPY_SOURCE'))) . '" aria-label="'
               . ce_translator_h(ce_translator_plain(ce_translator_t('COPY_SOURCE'))) . '">&darr;</button>';
        }
        echo '<div class="ce-warn" hidden></div></div></div>' . "\n";
    }
    echo '</div>' . "\n";
    echo '<p class="ce-hint ce-nomatch" id="ce-nomatch" hidden></p>' . "\n";
    echo '</div>' . "\n";
}

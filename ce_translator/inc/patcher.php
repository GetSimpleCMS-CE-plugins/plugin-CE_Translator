<?php
/*
 * CE-Translator: structure-preserving language file patcher.
 * Compatible with PHP 7.4 - 8.4.
 *
 * Real language files carry things a rewrite would destroy: a header with the
 * translators' credits, per-source-file section comments, the original
 * formatting and entries whose value is an array (GetSimple's TRANSLITERATION
 * table). So an existing file is never regenerated. Instead its tokens are
 * located and only these edits are made:
 *
 *   - a changed value:  that one value expression is replaced
 *   - a cleared text:   that one entry is removed
 *   - a new text:       appended after the last entry
 *
 * Everything else stays byte for byte, so a save with no changes produces an
 * identical file and a single change produces a one-line diff in git.
 *
 * Only text WE generate is new code, and it is always a single-quoted literal
 * (see ce_translator_escape). The caller verifies the result before it
 * replaces the real file. Anything this patcher does not fully understand makes
 * it return null, and the caller falls back to a full rewrite.
 */
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

/** A string as a single-quoted PHP literal. */
function ce_translator_literal($value) {
    return "'" . ce_translator_escape($value) . "'";
}

/**
 * The string value of a plain string literal token, or null if it cannot be
 * decoded safely (double-quoted with an escape or a variable inside).
 */
function ce_translator_decode_literal($text) {
    $quote = substr($text, 0, 1);
    $inner = substr($text, 1, -1);
    if ($quote === "'") {
        return strtr($inner, array('\\\\' => '\\', "\\'" => "'"));
    }
    if ($quote === '"' && strpos($inner, '\\') === false && strpos($inner, '$') === false) {
        return $inner;
    }
    return null;
}

/** Tokens with their byte offsets. */
function ce_translator_tokenize($code) {
    $out = array();
    $pos = 0;
    foreach (@token_get_all($code) as $tok) {
        $id = is_array($tok) ? $tok[0] : null;
        $text = is_array($tok) ? $tok[1] : $tok;
        $len = strlen($text);
        $out[] = array('id' => $id, 'text' => $text, 'start' => $pos, 'end' => $pos + $len);
        $pos += $len;
    }
    return $out;
}

function ce_translator_is_insignificant($token) {
    return $token['id'] === T_WHITESPACE || $token['id'] === T_COMMENT || $token['id'] === T_DOC_COMMENT;
}

function ce_translator_next_significant($tokens, $from) {
    for ($i = $from, $n = count($tokens); $i < $n; $i++) {
        if (!ce_translator_is_insignificant($tokens[$i])) {
            return $i;
        }
    }
    return null;
}

function ce_translator_is_open($token) {
    return ($token['id'] === null && ($token['text'] === '(' || $token['text'] === '[' || $token['text'] === '{'))
        || $token['id'] === T_CURLY_OPEN || $token['id'] === T_DOLLAR_OPEN_CURLY_BRACES;
}

function ce_translator_is_close($token) {
    return $token['id'] === null && ($token['text'] === ')' || $token['text'] === ']' || $token['text'] === '}');
}

/**
 * Finds the single "$i18n = array(...)" / "$i18n = [...]" assignment and
 * parses its entries. Returns null for anything else (several assignments,
 * "$i18n['KEY'] = ..." style, keys it cannot decode, duplicate keys, ...).
 *
 * Duplicate keys are allowed (real language files have them; PHP keeps the
 * last definition). Result: array('entries' => [...], 'close' => offset of the closing bracket,
 * 'open' => offset of the opening bracket). Each entry has: key, keyStart,
 * valueStart, valueEnd, entryEnd (after the comma if any) and hasComma.
 */
function ce_translator_parse_array($tokens) {
    $n = count($tokens);
    $open = null;
    for ($i = 0; $i < $n; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== '$i18n') {
            continue;
        }
        if ($open !== null) {
            return null;
        }
        $j = ce_translator_next_significant($tokens, $i + 1);
        if ($j === null || $tokens[$j]['id'] !== null || $tokens[$j]['text'] !== '=') {
            return null;
        }
        $k = ce_translator_next_significant($tokens, $j + 1);
        if ($k === null) {
            return null;
        }
        if ($tokens[$k]['id'] === T_ARRAY) {
            $m = ce_translator_next_significant($tokens, $k + 1);
            if ($m === null || $tokens[$m]['id'] !== null || $tokens[$m]['text'] !== '(') {
                return null;
            }
            $open = $m;
        } elseif ($tokens[$k]['id'] === null && $tokens[$k]['text'] === '[') {
            $open = $k;
        } else {
            return null;
        }
    }
    if ($open === null) {
        return null;
    }

    $depth = 0;
    $close = null;
    for ($i = $open; $i < $n; $i++) {
        if (ce_translator_is_open($tokens[$i])) {
            $depth++;
        } elseif (ce_translator_is_close($tokens[$i])) {
            $depth--;
            if ($depth === 0) {
                $close = $i;
                break;
            }
        }
    }
    if ($close === null) {
        return null;
    }

    $entries = array();
    $cur = null;
    $state = 'key';
    $d = 0;
    for ($i = $open + 1; $i < $close; $i++) {
        $t = $tokens[$i];
        if (ce_translator_is_insignificant($t)) {
            continue;
        }
        if ($d === 0 && $t['id'] === T_DOUBLE_ARROW && $state === 'key') {
            if ($cur === null) {
                return null;
            }
            $state = 'value';
            continue;
        }
        if ($d === 0 && $t['id'] === null && $t['text'] === ',') {
            if ($state !== 'value' || $cur === null || !$cur['value']) {
                return null;
            }
            $cur['comma'] = $t;
            $entries[] = $cur;
            $cur = null;
            $state = 'key';
            continue;
        }
        if ($cur === null) {
            $cur = array('key' => array(), 'value' => array(), 'comma' => null);
        }
        $cur[$state][] = $t;
        if (ce_translator_is_open($t)) {
            $d++;
        } elseif (ce_translator_is_close($t)) {
            $d--;
        }
    }
    if ($cur !== null) {
        if ($state !== 'value' || !$cur['value']) {
            return null;
        }
        $entries[] = $cur;
    }

    $parsed = array();
    foreach ($entries as $e) {
        if (count($e['key']) !== 1) {
            return null;
        }
        $kt = $e['key'][0];
        if ($kt['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $key = ce_translator_decode_literal($kt['text']);
        } elseif ($kt['id'] === T_LNUMBER) {
            $key = $kt['text'];
        } else {
            $key = null;
        }
        if ($key === null) {
            return null;
        }
        $first = $e['value'][0];
        $last = $e['value'][count($e['value']) - 1];
        $parsed[] = array(
            'key'        => (string) $key,
            'keyStart'   => $kt['start'],
            'valueStart' => $first['start'],
            'valueEnd'   => $last['end'],
            'entryEnd'   => $e['comma'] !== null ? $e['comma']['end'] : $last['end'],
            'hasComma'   => $e['comma'] !== null,
        );
    }
    return array('entries' => $parsed, 'open' => $tokens[$open]['start'], 'close' => $tokens[$close]['start']);
}

/** Byte range to delete for one entry: the whole line when it is alone on it. */
function ce_translator_delete_range($code, $entry) {
    $len = strlen($code);
    $start = $entry['keyStart'];
    $p = $entry['entryEnd'];
    while ($p < $len && ($code[$p] === ' ' || $code[$p] === "\t")) {
        $p++;
    }
    // a comment after the entry on the same line belongs to it
    if (substr($code, $p, 2) === '//' || substr($code, $p, 1) === '#') {
        $p += strcspn($code, "\r\n", $p);
    } elseif (substr($code, $p, 2) === '/*') {
        $close = strpos($code, '*/', $p);
        if ($close !== false && strcspn($code, "\r\n", $p) > $close - $p) {
            $p = $close + 2;
            while ($p < $len && ($code[$p] === ' ' || $code[$p] === "\t")) {
                $p++;
            }
        }
    }
    $lineStart = $start;
    while ($lineStart > 0 && ($code[$lineStart - 1] === ' ' || $code[$lineStart - 1] === "\t")) {
        $lineStart--;
    }
    $alone = ($lineStart === 0 || $code[$lineStart - 1] === "\n")
          && ($p >= $len || $code[$p] === "\n" || $code[$p] === "\r");
    if ($alone) {
        if (substr($code, $p, 2) === "\r\n") {
            $p += 2;
        } elseif ($p < $len) {
            $p += 1;
        }
        return array($lineStart, $p);
    }
    return array($start, $p);
}

/**
 * Patches $code so that its texts equal $desired.
 *
 *   $current    the texts as loaded from $code (key => string)
 *   $structured keys (as array keys) whose value is not a string; never touched
 *
 * Returns the new source, or null if the file is not understood well enough
 * to patch it safely.
 */
function ce_translator_patch_php($code, $desired, $current, $structured) {
    $tokens = ce_translator_tokenize($code);
    $arr = ce_translator_parse_array($tokens);
    if ($arr === null) {
        return null;
    }
    $eol = strpos($code, "\r\n") !== false ? "\r\n" : "\n";
    $edits = array();
    $fragments = array();   // everything we generate, verified below
    $seen = array();
    $lastIndex = array();   // PHP keeps the LAST definition of a duplicated key
    foreach ($arr['entries'] as $i => $e) {
        $lastIndex[$e['key']] = $i;
    }
    $survivors = array();   // entries that stay (the new entries are appended after the last of them)

    foreach ($arr['entries'] as $i => $e) {
        $k = $e['key'];
        $seen[$k] = true;
        if (isset($structured[$k]) || !array_key_exists($k, $current)) {
            $survivors[] = $e;
            continue;
        }
        if (array_key_exists($k, $desired)) {
            $survivors[] = $e;
            if ($i === $lastIndex[$k] && $current[$k] !== $desired[$k]) {
                $lit = ce_translator_literal($desired[$k]);
                $fragments[] = $lit;
                $edits[] = array($e['valueStart'], $e['valueEnd'], $lit);
            }
        } else {
            // every definition goes, otherwise a shadowed duplicate would come back to life
            $range = ce_translator_delete_range($code, $e);
            $edits[] = array($range[0], $range[1], '');
        }
    }

    $new = array();
    foreach ($desired as $k => $v) {
        if (!isset($seen[(string) $k]) && !isset($structured[(string) $k])) {
            $new[(string) $k] = $v;
        }
    }
    if ($new) {
        $lines = array();
        foreach ($new as $k => $v) {
            $lines[] = "'" . ce_translator_escape((string) $k) . "' => " . ce_translator_literal($v);
        }
        $fragments[] = implode(', ', $lines);
        $entries = $survivors;
        if (!$entries) {
            $edits[] = array($arr['close'], $arr['close'], $eol . '    ' . implode(',' . $eol . '    ', $lines) . ',' . $eol);
        } else {
            $last = $entries[count($entries) - 1];
            $comma = $last['hasComma'] ? '' : ',';
            $between = substr($code, $last['entryEnd'], $arr['close'] - $last['entryEnd']);
            if (strpos($between, "\n") === false) {
                // one-line array: new entries go on the same line
                $edits[] = array($last['entryEnd'], $last['entryEnd'], $comma . ' ' . implode(', ', $lines));
            } else {
                $first = $entries[0];
                $ls = $first['keyStart'];
                while ($ls > 0 && ($code[$ls - 1] === ' ' || $code[$ls - 1] === "\t")) {
                    $ls--;
                }
                $indent = ($ls === 0 || $code[$ls - 1] === "\n") ? substr($code, $ls, $first['keyStart'] - $ls) : '    ';
                $text = $eol . $indent . implode(',' . $eol . $indent, $lines) . ',';
                $nl = strpos($code, "\n", $last['entryEnd']);
                $pos = ($nl > 0 && $code[$nl - 1] === "\r") ? $nl - 1 : $nl;
                if ($pos === $last['entryEnd']) {
                    $edits[] = array($pos, $pos, $comma . $text);
                } else {
                    if ($comma !== '') {
                        $edits[] = array($last['entryEnd'], $last['entryEnd'], $comma);
                    }
                    $edits[] = array($pos, $pos, $text);
                }
            }
        }
    }

    // what we generate must be nothing but single-quoted literals
    if ($fragments) {
        $probe = "<?php\n\$i18n = [";
        foreach ($fragments as $i => $f) {
            $probe .= (strpos($f, ' => ') !== false ? $f : "'k$i' => " . $f) . ",\n";
        }
        if (!ce_translator_verify_php($probe . "];\n")) {
            return null;
        }
    }

    usort($edits, function ($a, $b) {
        return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
    });
    $prevEnd = 0;
    foreach ($edits as $ed) {
        if ($ed[0] < $prevEnd) {
            return null;   // overlapping edits: do not guess
        }
        $prevEnd = $ed[1];
    }
    for ($i = count($edits) - 1; $i >= 0; $i--) {
        $code = substr($code, 0, $edits[$i][0]) . $edits[$i][2] . substr($code, $edits[$i][1]);
    }
    return $code;
}

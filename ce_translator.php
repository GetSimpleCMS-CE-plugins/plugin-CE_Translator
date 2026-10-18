<?php
/*
Plugin Name: CE-Translator
Description: Allows you to edit and translate GS and Plugin i18n language files and keys.
Version: 1.0
Author: risingisland (based on Translate by Martin Vlcek)
Author URI: https://ko-fi.com/ericmontgomery
*/
if (!defined('IN_GS')) { die('you cannot load this page directly.'); }

# get correct id for plugin
$thisfile = basename(__FILE__, '.php');

# register plugin
register_plugin(
	$thisfile,
	'CE-Translator',
	'1.0',
	'risingisland',
	'https://getsimple-ce.ovh/ce-plugins',
	'Allows you to edit and translate GS and Plugin i18n language files and keys.',
	'plugins',
	'ce_translator_show'
);

# i18n
i18n_merge($thisfile) || i18n_merge($thisfile, 'en_US');

# sidebar entry
add_action('plugins-sidebar', 'createSideMenu', array($thisfile, i18n_r($thisfile . '/MENU'). ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="18" height="18" viewBox="0 0 16 16"><path d="M0 0h16v16H0z" fill="none"/><g fill="currentColor"><path d="M4.545 6.714L4.11 8H3l1.862-5h1.284L8 8H6.833l-.435-1.286zm1.634-.736L5.5 3.956h-.049l-.679 2.022z"/><path d="M0 2a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v3h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-3H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zm7.138 9.995q.289.451.63.846c-.748.575-1.673 1.001-2.768 1.292c.178.217.451.635.555.867c1.125-.359 2.08-.844 2.886-1.494c.777.665 1.739 1.165 2.93 1.472c.133-.254.414-.673.629-.89c-1.125-.253-2.057-.694-2.82-1.284c.681-.747 1.222-1.651 1.621-2.757H14V8h-3v1.047h.765c-.318.844-.74 1.546-1.272 2.13a6 6 0 0 1-.415-.492a2 2 0 0 1-.94.31"/></g></svg>'));

# JSON save endpoint: runs after login_cookie_check() and before any output
add_action('admin-pre-header', 'ce_translator_pre_header');

# stylesheet and script, only on our own page
if (basename($_SERVER['PHP_SELF']) === 'load.php' && isset($_GET['id']) && $_GET['id'] === $thisfile) {
	global $SITEURL;
	register_style($thisfile, $SITEURL . 'plugins/ce_translator/assets/ce-translator.css', '2.0.0-dev', 'screen');
	queue_style($thisfile, GSBACK);
	register_script($thisfile, $SITEURL . 'plugins/ce_translator/assets/ce-translator.js', '2.0.0-dev', true);
	queue_script($thisfile, GSBACK);
}

function ce_translator_load_includes() {
	$dir = GSPLUGINPATH . 'ce_translator/inc/';
	require_once $dir . 'scanner.php';
	require_once $dir . 'langfile.php';
	require_once $dir . 'views.php';
	require_once $dir . 'controller.php';
	require_once $dir . 'editor.php';
}

function ce_translator_pre_header() {
	if (basename($_SERVER['PHP_SELF']) === 'load.php' && isset($_GET['id']) && $_GET['id'] === 'ce_translator'
		&& isset($_GET['ajax'])) {
		ce_translator_load_includes();
		ce_translator_ajax();   // sends JSON and exits
	}
}

function ce_translator_show() {
	ce_translator_load_includes();
	ce_translator_controller();
}

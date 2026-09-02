<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Snippetów (loader)
 */

require_once __DIR__ . '/snippets/definitions.php';
require_once __DIR__ . '/snippets/wpisy.php';        // model wpisów: rodzaj, miejsce, grupa
require_once __DIR__ . '/snippets/validation.php';
require_once __DIR__ . '/snippets/engine.php';
require_once __DIR__ . '/snippets/ajax.php';
require_once __DIR__ . '/snippets/panel.php';    // lista wpisów i edytor

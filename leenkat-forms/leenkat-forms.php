<?php
/**
 * Plugin Name: Leenkat Forms
 * Plugin URI: https://leenkat.com/projects/leenkat-forms/
 * Description: Build WordPress forms with conditional logic, multi-step pages, notifications and private uploads.
 * Version: 1.0.1
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Leenkat
 * Author URI: https://leenkat.com/
 * License: GPL-2.0-or-later
 * Text Domain: leenkat-forms
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

define('LEENKAT_FORMS_VERSION', '1.0.1');
define('LEENKAT_FORMS_FILE', __FILE__);
define('LEENKAT_FORMS_DIR', plugin_dir_path(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'LeenkatForms\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $relative = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative)) { return; }
    $file = LEENKAT_FORMS_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require_once $file; }
});

register_activation_hook(__FILE__, [\LeenkatForms\Bootstrap\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [\LeenkatForms\Bootstrap\Lifecycle::class, 'deactivate']);
add_action('plugins_loaded', [\LeenkatForms\Bootstrap\Plugin::class, 'boot']);







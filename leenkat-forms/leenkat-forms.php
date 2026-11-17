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
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

define( 'LEENKAT_FORMS_VERSION', '1.0.1' );
define( 'LEENKAT_FORMS_FILE', __FILE__ );
define( 'LEENKAT_FORMS_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $requested_class ): void {
		$prefix = 'LeenkatForms\\';
		if ( ! str_starts_with( $requested_class, $prefix ) ) {
			return;
		}

		$relative = substr( $requested_class, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $relative ) ) {
			return;
		}

		$relative_path = str_replace( '\\', '/', $relative );
		$separator     = strrpos( $relative_path, '/' );
		$directory     = false === $separator ? '' : substr( $relative_path, 0, $separator + 1 );
		$class_name    = false === $separator ? $relative_path : substr( $relative_path, $separator + 1 );
		$root          = LEENKAT_FORMS_DIR . 'src/';
		$class_file    = $root . $directory . 'class-' . strtolower( $class_name ) . '.php';
		if ( ! is_file( $class_file ) ) {
			$class_file = $root . $directory . strtolower( $class_name ) . '.php';
		}
		if ( ! is_file( $class_file ) ) {
			// Handle the previous file names when the WordPress install is being upgraded.
			$class_file = $root . $relative_path . '.php';
		}
		if ( is_file( $class_file ) ) {
			require_once $class_file;
		}
	}
);

register_activation_hook( __FILE__, array( \LeenkatForms\Bootstrap\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LeenkatForms\Bootstrap\Lifecycle::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \LeenkatForms\Bootstrap\Plugin::class, 'boot' ) );

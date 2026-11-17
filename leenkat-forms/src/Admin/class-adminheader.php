<?php
/**
 * Shared headers for plugin administration screens.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Settings-style brand header, shared without changing page controls or settings. */
final class AdminHeader {
	/**
	 * Render the header and title for a settings screen.
	 *
	 * @param string $title Title for this operation.
	 */
	public static function settings( string $title ): void {
		echo '<header class="lkf-settings-brand"><img src="' . esc_url( plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ) ) . '" width="64" height="64" alt=""><h1>' . esc_html( $title ) . '</h1></header>';
	}
}

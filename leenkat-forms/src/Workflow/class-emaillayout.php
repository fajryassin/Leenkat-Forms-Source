<?php
/**
 * HTML layout and typography for notification messages.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Workflow;

use LeenkatForms\Settings\SiteSettings;

/**
 * HTML layout and typography for notification messages.
 */
final class EmailLayout {
	/**
	 * Render the configured wrapper for the notification body.
	 *
	 * @param string $body     Message or response body.
	 * @param string $template Template for this operation.
	 * @param string $title    Title for this operation.
	 * @param array  $email    Email for this operation.
	 * @param string $mode     Mode for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function render( string $body, string $template, string $title, array $email, string $mode = 'auto' ): string {
		[$accent, $default_font, $padding, $width] = match ( $template ) {
			'compact'=>array( '#444444', 'Arial, sans-serif', 16, 520 ), 'modern'=>array( '#087e84', 'Arial, sans-serif', 30, 640 ), 'elegant'=>array( '#69583a', 'Georgia, serif', 36, 640 ), 'tech'=>array( '#193344', 'monospace', 24, 640 ), default=>array( '#444444', 'Arial, sans-serif', 30, 640 )};
		$light          = $email[ 'dark' === $mode ? 'dark' : 'light' ];
		$dark           = $email['dark'];
		$font           = self::font( $light['typography'], $default_font );
		$dark_font      = self::font( $dark['typography'], $default_font );
		$accent_heading = in_array( $template, array( 'modern', 'elegant', 'tech' ), true );
		$header         = $accent_heading ? $accent : $light['body'];
		$header_text    = $accent_heading ? '#ffffff' : $light['text'];
		$css            = 'body,table,td{font-family:' . $font . '}.lkf-email-body a{color:' . $light['links'] . '}.lkf-email-body table{color:inherit}.lkf-email-logo-dark{display:none;max-height:0;overflow:hidden}';
		if ( 'auto' === $mode ) {
			$rules = '.lkf-email-background{background:' . $dark['background'] . '!important}.lkf-email-panel,.lkf-email-content,.lkf-email-logo-cell{background:' . $dark['body'] . '!important;color:' . $dark['text'] . '!important}.lkf-email-content,.lkf-email-content th,.lkf-email-content td{color:' . $dark['text'] . '!important;font-family:' . $dark_font . '!important}.lkf-email-body a{color:' . $dark['links'] . '!important}';
			if ( ! $accent_heading ) {
				$rules .= '.lkf-email-heading{background:' . $dark['body'] . '!important;color:' . $dark['text'] . '!important}';
			}
			$rules .= '.lkf-email-heading{font-family:' . $dark_font . '!important}.lkf-email-logo-light{display:none!important;max-height:0!important;overflow:hidden!important}.lkf-email-logo-dark{display:inline-block!important;max-height:none!important;overflow:visible!important}';
			$css   .= '@media(prefers-color-scheme:dark){' . $rules . '}';
		}
		$logo        = '';
		$light_image = SiteSettings::image( (int) $email['light']['header_image'] );
		$dark_image  = SiteSettings::image( (int) $email['dark']['header_image'] ) ?? $light_image;
		$image_tag   = static function ( ?array $image, string $css_class, string $size ): string {
			if ( ! $image ) {
				return '';
			}[$max_width, $max_height] = match ( $size ) {
				'small'=>array( 240, 120 ), 'large'=>array( 500, 240 ), default=>array( 350, 180 )};
			$scale        = min( 1, $max_width / max( 1, $image['width'] ), $max_height / max( 1, $image['height'] ) );
			$image_width  = max( 1, (int) round( $image['width'] * $scale ) );
			$image_height = max( 1, (int) round( $image['height'] * $scale ) );
			return '<img class="' . $css_class . '" src="' . esc_url( $image['url'] ) . '" alt="' . esc_attr( $image['alt'] ) . '" width="' . $image_width . '" height="' . $image_height . '" style="display:' . ( 'lkf-email-logo-dark' === $css_class ? 'none' : 'inline-block' ) . ';max-width:100%;height:auto;border:0">';
		};
		$light_size  = $email['light']['header_image_size'] ?? 'medium';
		$dark_size   = $email['dark']['header_image_size'] ?? 'medium';
		if ( 'auto' === $mode ) {
			$images = $image_tag( $light_image, 'lkf-email-logo-light', $light_size ) . $image_tag( $dark_image, 'lkf-email-logo-dark', $dark_size );
		} else {
			$images = $image_tag( 'dark' === $mode ? $dark_image : $light_image, 'lkf-email-logo', 'dark' === $mode ? $dark_size : $light_size );
		}
		if ( '' !== $images ) {
			$logo = '<tr><td class="lkf-email-logo-cell" style="padding:24px ' . $padding . 'px 8px;background:' . $light['body'] . '">' . $images . '</td></tr>';
		}
		$body      = nl2br( $body, false );
		$language  = function_exists( 'get_locale' ) ? str_replace( '_', '-', get_locale() ) : 'en-US';
		$direction = function_exists( 'is_rtl' ) && is_rtl() ? 'rtl' : 'ltr';
		return '<!doctype html><html lang="' . esc_attr( $language ) . '" dir="' . esc_attr( $direction ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><style>' . $css . '</style></head><body class="lkf-email-background lkf-email-body" style="margin:0;background:' . $light['background'] . ';color:' . $light['text'] . ';font-family:' . $font . ';font-size:15px;line-height:1.6"><table role="presentation" width="100%" class="lkf-email-background" style="background:' . $light['background'] . '"><tr><td style="padding:24px 12px"><table role="presentation" width="100%" class="lkf-email-panel" style="max-width:' . $width . 'px;margin:auto;background:' . $light['body'] . ';border:1px solid ' . $accent . ';border-collapse:collapse">' . $logo . '<tr><td class="lkf-email-heading" style="padding:' . $padding . 'px;background:' . $header . ';color:' . $header_text . ';font-size:22px;font-weight:bold;font-family:' . $font . '">' . esc_html( $title ) . '</td></tr><tr><td class="lkf-email-content" style="padding:' . $padding . 'px;background:' . $light['body'] . ';color:' . $light['text'] . ';font-family:' . $font . '">' . $body . '</td></tr></table></td></tr></table></body></html>';
	}
	/**
	 * Return the supported font stack for the email layout.
	 *
	 * @param string $value    Value being processed.
	 * @param string $fallback Fallback for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function font( string $value, string $fallback ): string {
		return match ( $value ) {
			'sans'=>'Arial, Helvetica, sans-serif', 'serif'=>'Georgia, Times New Roman, serif', 'mono'=>'Courier New, monospace', default=>$fallback};}
}

<?php
/**
 * Preparation and cleanup of attachments for delivered email.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** Collect verified attachment data for the current message without writing plaintext files. */
final class MailAttachments {
	public const MAX_BYTES = 20 * 1024 * 1024;
	/**
	 * Prepare verified attachments for the current email delivery.
	 *
	 * @param array $item       Item for this operation.
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return array|false Result for the requested operation.
	 */
	public static function prepare( array $item, array $definition, array $values, array $context ): array|false {
		$attachments = array();
		$bytes       = 0;
		if ( ! empty( $item['file_attachments'] ) && ! empty( $context['uploads'] ) ) {
			$entry_id = (int) ( $context['entry_id'] ?? 0 );
			$files    = $entry_id > 0 ? \LeenkatForms\Files\FileRecords::notificationFiles( $entry_id ) : ( $context['uploads'] ?? array() );
			$known    = array();
			foreach ( $definition['fields'] ?? array() as $field ) {
				if ( ( $field['type'] ?? '' ) === 'file_upload' ) {
					$known[] = $field['id'];
				}
			}
			foreach ( $files as $record ) {
				if ( ! in_array( $record['field_id'] ?? '', $item['file_fields'] ?? array(), true ) ) {
					continue;
				}
				if ( ! in_array( $record['field_id'], $known, true ) ) {
					return false;
				}
				$real = \LeenkatForms\Files\FileRecords::mailPath( $record, $entry_id );
				if ( ! $real ) {
					return false;
				}
				$limit = self::MAX_BYTES - $bytes + 1;
				try {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Keep the bounded local attachment read under the remaining mail-size limit. WP_Filesystem get_contents does not accept a read length.
					$contents = is_resource( $real ) ? stream_get_contents( $real, $limit ) : file_get_contents( $real, false, null, 0, $limit );
				} finally {
					\LeenkatForms\Files\PrivateStorage::close( $real );
				}
				if ( ! is_string( $contents ) || strlen( $contents ) >= $limit ) {
					return false;
				}
				$bytes += strlen( $contents );
				$name   = sanitize_file_name( $record['name'] ?? 'attachment' );
				$stem   = pathinfo( $name, PATHINFO_FILENAME );
				$ext    = pathinfo( $name, PATHINFO_EXTENSION );
				$n      = 1;
				while ( isset( $attachments[ $name ] ) ) {
					$name = $stem . '-' . ( ++$n ) . ( '' !== $ext ? '.' . $ext : '' );
				}
				$attachments[ $name ] = $contents;
			}
		}
		if ( ! empty( $item['csv_attachment'] ) ) {
			$csv = self::csv( $item, $definition, $values, $context );
			if ( strlen( $csv ) + $bytes > self::MAX_BYTES ) {
				return false;
			}
			$name  = sanitize_file_name( $item['csv_filename'] ?? 'entry-details' );
			$name  = preg_replace( '/\.csv$/i', '', $name );
			$name  = $name ? $name : 'entry-details';
			$name .= '.csv';
			$stem  = pathinfo( $name, PATHINFO_FILENAME );
			$n     = 1;
			while ( isset( $attachments[ $name ] ) ) {
				$name = $stem . '-' . ( ++$n ) . '.csv';
			}
			$attachments[ $name ] = $csv;
		}
		return $attachments;
	}
	/**
	 * Build the temporary entry CSV attachment.
	 *
	 * @param array $item       Item for this operation.
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return string Result for the requested operation.
	 */
	public static function csv( array $item, array $definition, array $values, array $context ): string {
		$selection = $item['csv_fields'] ?? array( 'all_fields' );
		$labels    = array();
		$cells     = array();
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( in_array( $field['type'], array( 'captcha', 'section_divider', 'page_break' ), true ) || ( $values[ $field['id'] ] ?? null ) === null || ( ! in_array( 'all_fields', $selection, true ) && ! in_array( $field['id'], $selection, true ) ) ) {
				continue;
			}
			$labels[] = $field['label'];
			$cells[]  = NotificationMessage::value( $values[ $field['id'] ] );
		}
		foreach ( array(
			'entry_id'   => 'Entry ID',
			'form_id'    => 'Form ID',
			'entry_date' => 'Entry Date',
		) as $key => $label ) {
			if ( in_array( $key, $selection, true ) ) {
					$labels[] = $label;
					$cells[]  = (string) ( $context[ $key ] ?? '' );}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native php://memory stream creates the CSV before moving it into verified private storage.
		$stream = fopen( 'php://memory', 'w+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native php://memory stream creates the CSV before moving it into verified private storage.
		fwrite( $stream, "\xEF\xBB\xBF" );
		$safe = static fn( $value )=>preg_match( '/^[\x00-\x20]*[=+@-]|^[\t\r\n]/u', (string) $value ) ? "'" . $value : $value;
		fputcsv( $stream, array_map( $safe, $labels ), ',', '"', '' );
		fputcsv( $stream, array_map( $safe, $cells ), ',', '"', '' );
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native php://memory stream creates the CSV before moving it into verified private storage.
		fclose( $stream );
		return $csv;
	}
	/**
	 * Send the prepared message with its verified attachments.
	 *
	 * @param array $message     Message text for this operation.
	 * @param array $attachments Attachments for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function send( array $message, array $attachments ): bool {
		if ( ! $attachments ) {
			return wp_mail( $message['to'], $message['subject'], $message['message'], $message['headers'] );
		}

		$header  = 'X-Leenkat-Forms-Attachment-Context';
		$token   = bin2hex( random_bytes( 16 ) );
		$added   = false;
		$prepare = static function ( $mailer ) use ( $header, $token, $attachments, &$added ): void {
			foreach ( $mailer->getCustomHeaders() as [$name, $value] ) {
				if ( 0 !== strcasecmp( $name, $header ) || ! hash_equals( $token, $value ) ) {
					continue;
				}
				$mailer->clearCustomHeader( $header );
				foreach ( $attachments as $filename => $contents ) {
					if ( ! $mailer->addStringAttachment( $contents, $filename ) ) {
						throw new \RuntimeException( 'Leenkat Forms could not attach message data.' );
					}
				}
				$added = true;
				return;
			}
		};
		add_action( 'phpmailer_init', $prepare, 1 );
		try {
			return wp_mail(
				$message['to'],
				$message['subject'],
				$message['message'],
				array( ...$message['headers'], $header . ': ' . $token )
			) && $added;
		} catch ( \Throwable ) {
			return false;
		} finally {
			remove_action( 'phpmailer_init', $prepare, 1 );
		}
	}
}

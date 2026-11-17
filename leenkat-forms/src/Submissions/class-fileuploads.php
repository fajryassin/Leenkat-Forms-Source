<?php
/**
 * Validation, staging and cleanup of submitted attachment files.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** Files enter through a validated published form; WordPress performs the final MIME checks. */
final class FileUploads {
	public const EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'mp3', 'mp4', 'ogg', 'wav' );
	/**
	 * Return the allowed extensions for the upload field.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function extensions( array $settings ): array {
		$requested = preg_split( '/[\s,]+/', strtolower( (string) ( $settings['allowed_extensions'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return $requested ? array_values( array_intersect( array_map( static fn( string $ext ): string => ltrim( $ext, '.' ), $requested ), self::EXTENSIONS ) ) : self::EXTENSIONS;
	}
	/**
	 * Resolve the upload size limit for a field.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return int Result for the requested operation.
	 */
	public static function maxBytes( array $settings ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return min( wp_max_upload_size(), (int) ( ( ( $settings['max_file_size'] ?? 0 ) ? ( $settings['max_file_size'] ?? 0 ) : 50 ) * MB_IN_BYTES ), 50 * MB_IN_BYTES );
	}
	/**
	 * Effective per-file bound, after the unchanged WordPress and plugin ceilings.
	 *
	 * @param array $settings Settings used by the current operation.
	 */
	public static function sizeLabel( array $settings ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$megabytes = rtrim( rtrim( number_format( self::maxBytes( $settings ) / MB_IN_BYTES, 2, '.', '' ), '0' ), '.' );
		return sprintf( /* translators: %s: maximum upload size in megabytes. */ __( '%s MB', 'leenkat-forms' ), $megabytes );
	}
	/**
	 * Validate submitted files before persisting the entry.
	 *
	 * @param mixed $files Files for this operation.
	 * @param array $field Field definition used by this operation.
	 * @return array|\WP_Error Result for the requested operation.
	 */
	public static function prepare( mixed $files, array $field ): array|\WP_Error {
		$settings = $field['settings'] ?? array();
		if ( null === $files ) {
			return empty( $field['required'] ) ? array() : self::error( 'required', $settings ); }
		if ( ! is_array( $files ) || ! is_array( $files['name'] ?? null ) || ! array_is_list( $files['name'] ) ) {
			return self::error( 'failed', $settings ); }
		foreach ( array( 'tmp_name', 'error' ) as $key ) {
			if ( ! is_array( $files[ $key ] ?? null ) || ! array_is_list( $files[ $key ] ) ||
				count( $files[ $key ] ) !== count( $files['name'] ) ) {
				return self::error( 'failed', $settings );
			}
		}
		if ( isset( $files['full_path'] ) && ! is_array( $files['full_path'] ) ) {
			return self::error( 'failed', $settings );
		}
		if ( count( $files['name'] ) > 10 ) {
			return self::error( 'count', $settings );
		}
		$selected = 0;
		foreach ( $files['name'] as $i => $name ) {
			if ( ( $files['error'][ $i ] ?? -1 ) !== UPLOAD_ERR_NO_FILE ) {
				++$selected;
			}
		}
		if ( $selected > ( $settings['max_files'] ?? 1 ) ) {
			return self::error( 'count', $settings );
		}
		$prepared = array();
		foreach ( $files['name'] as $i => $name ) {
			$upload_error = $files['error'][ $i ] ?? -1;
			if ( UPLOAD_ERR_NO_FILE === $upload_error ) {
				continue; }
			if ( in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
				return self::error( 'size', $settings );
			}
			$tmp = $files['tmp_name'][ $i ] ?? null;
			if ( ! is_string( $name ) || ! is_string( $tmp ) || UPLOAD_ERR_OK !== $upload_error || ! is_uploaded_file( $tmp ) ) {
				return self::error( 'failed', $settings ); }
			if ( false !== strpbrk( $name, "/\\\r\n\0" ) ) {
				return self::error( 'failed', $settings );
			}
			if ( isset( $files['full_path'][ $i ] ) && ( ! is_string( $files['full_path'][ $i ] ) || false !== strpbrk( $files['full_path'][ $i ], "/\\:\r\n\0" ) ) ) {
				return self::error( 'failed', $settings );
			}
			if ( preg_match( '/\.(php[0-9]*|pht(?:ml)?|phar|html?|shtml|cgi|pl|py|rb|aspx?|jsp?|svg|exe|bat|cmd|ps1|com|scr|msi|dll)(?:\.|$)/i', $name ) ) {
				return self::error( 'type', $settings );
			}
			$name = sanitize_file_name( $name );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' === $name || strlen( $name ) > 200 ) {
				return self::error( 'failed', $settings );
			}
			if ( ! in_array( $ext, self::extensions( $settings ), true ) ) {
				return self::error( 'type', $settings );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A PHP temporary upload can become unavailable after validation. Preserve the normal invalid-upload response without warning output.
			$size = @filesize( $tmp );
			if ( ! $size ) {
				return self::error( 'failed', $settings );
			}
			if ( $size > self::maxBytes( $settings ) ) {
				return self::error( 'size', $settings );
			}
			$mime = wp_check_filetype_and_ext( $tmp, $name, get_allowed_mime_types() );
			if ( ! empty( $mime['proper_filename'] ) || empty( $mime['ext'] ) || empty( $mime['type'] ) || ! \LeenkatForms\Files\MimePolicy::valid( $tmp, $ext ) ) {
				return self::error( 'mime', $settings ); }
			$prepared[] = array(
				'name'     => $name,
				'tmp_name' => $tmp,
				'type'     => $mime['type'],
				'size'     => $size,
				'error'    => 0,
				'sha256'   => hash_file( 'sha256', $tmp ),
			);
		}
		if ( ! empty( $field['required'] ) && ! $prepared ) {
			return self::error( 'required', $settings ); }
		return $prepared;
	}
	/**
	 * Store the prepared uploads for the current submission.
	 *
	 * @param array $prepared Prepared for this operation.
	 * @param int   $formId   Form identifier.
	 * @param array $created  Created for this operation.
	 * @return string|\WP_Error Result for the requested operation.
	 */
	public static function store( array $prepared, int $formId, array &$created ): string|\WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		$display = array();
		foreach ( $prepared as $file ) {
			$name = $file['name'];
			try {
				$key = \LeenkatForms\Files\PrivateStorage::put(
					$file['tmp_name'],
					strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
					(int) $file['size'],
					$file['sha256'],
					$formId // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
				);
			} catch ( \Throwable ) {
				return self::error( 'storage', array(), 503 );
			}
			$created[] = array(
				'storage_key'   => $key,
				'kind'          => 'private',
				'attachment_id' => 0,
				'name'          => $name,
				'mime'          => $file['type'],
				'size'          => (int) $file['size'],
				'sha256'        => $file['sha256'],
			);
			$display[] = $name;
		}
		return implode( "\n", $display );
	}
	/**
	 * Remove staged file resources after an incomplete submission.
	 *
	 * @param array $created Created for this operation.
	 */
	public static function cleanup( array $created ): void {
		\LeenkatForms\Files\FileRecords::discard( $created );
	}
	/**
	 * Build a field-level error for an invalid upload.
	 *
	 * @param string $reason   Reason for this operation.
	 * @param array  $settings Settings used by the current operation.
	 * @param int    $status   Status of the current record.
	 * @return \WP_Error Result for the requested operation.
	 */
	private static function error( string $reason, array $settings = array(), int $status = 400 ): \WP_Error {
		$messages = \LeenkatForms\Frontend\FieldErrors::messages();
		$message  = match ( $reason ) {
			'required'=>$messages['required'],
			'count'=>sprintf( $messages['file_count'], (int) ( $settings['max_files'] ?? 1 ) ),
			'size'=>sprintf( $messages['file_size'], self::sizeLabel( $settings ) ),
			'type'=>sprintf( $messages['file_type'], implode( ', ', self::extensions( $settings ) ) ),
			'mime'=>$messages['file_mime'],
			'storage'=>$messages['file_storage'],
			default=>$messages['file_failed'],
		};
		return new \WP_Error(
			'leenfo_upload',
			$message,
			array(
				'status'        => $status,
				'upload_reason' => $reason,
			)
		);
	}
}

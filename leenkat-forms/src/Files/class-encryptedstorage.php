<?php
/**
 * Authenticated encryption and per-form private upload paths.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/** Authenticated, chunked objects in uploads. Only ciphertext is written there. */
final class EncryptedStorage {
	public const KEY_OPTION = 'leenkat_forms_storage_key_v2';
	public const MAX_BYTES  = 52428800;
	private const CHUNK     = 65536;
	private const MAGIC     = 'LKF2';
	/**
	 * Temporary decrypted streams that PHP closes at request shutdown.
	 *
	 * @var array<string, resource>
	 */
	private static array $temporary = array();

	/**
	 * Reject a missing or invalid private storage operation.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function fail(): never {
		throw new \RuntimeException( esc_html__( 'Encrypted private storage is unavailable.', 'leenkat-forms' ) );
	}

	/**
	 * Read or initialize the key used for encrypted file storage.
	 *
	 * @param bool $create Create for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function key( bool $create = false ): string {
		$value = get_option( self::KEY_OPTION, null );
		if ( null === $value && $create ) {
			add_option( self::KEY_OPTION, bin2hex( random_bytes( 32 ) ), '', false );
			$value = get_option( self::KEY_OPTION, null );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			self::fail(); }
		return hex2bin( $value );
	}

	/**
	 * Encrypt and authenticate a migration journal.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @param string $json Json for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function sealJournal( string $name, string $json ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 transports authenticated binary journal data through WordPress options and preserves the existing envelope format.
		return base64_encode(
			$nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
				$json,
				'leenkat-forms-journal-v1|' . $name,
				$nonce,
				self::key()
			)
		);
	}

	/**
	 * Verify and open an encrypted migration journal.
	 *
	 * @param string $name   Name used to resolve the requested item.
	 * @param string $sealed Sealed for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function unsealJournal( string $name, string $sealed ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64 transports authenticated binary journal data through WordPress options and preserves the existing envelope format.
		$raw         = base64_decode( $sealed, true );
		$nonce_bytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) < $nonce_bytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES ) {
			self::fail();
		}
		$json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr( $raw, $nonce_bytes ),
			'leenkat-forms-journal-v1|' . $name,
			substr( $raw, 0, $nonce_bytes ),
			self::key()
		);
		if ( false === $json || strlen( $json ) > 33554432 ) {
			self::fail(); }
		return $json;
	}

	/**
	 * Return the identity used for encrypted private storage.
	 *
	 * @param bool $create Create for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function identity( bool $create = false ): string {
		return hash_hmac( 'sha256', 'leenkat-forms-site-identity-v2', self::key( $create ) );
	}

	/**
	 * Resolve the site upload directory for encrypted storage.
	 *
	 * @param bool $create Create for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function root( bool $create = false ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = $uploads['basedir'] ?? null;
		if ( ! empty( $uploads['error'] ) || ! is_string( $base ) || ! preg_match( '~^(?:[A-Za-z]:/|/)~', wp_normalize_path( $base ) ) ||
			preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~', wp_normalize_path( $base ) ) || ! PrivateStorage::noLinks( $base ) ) {
			self::fail(); }
		$root = rtrim( $base, '/\\' ) . '/leenkat-forms-private-' . substr( self::identity( $create ), 0, 32 );
		if ( ! PrivateStorage::noLinks( $root ) ) {
			self::fail(); }
		if ( $create && ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			self::fail(); }
		if ( file_exists( $root ) ) {
			$canonical = realpath( $root );
			$parent    = realpath( $base );
			if ( ! $canonical || ! $parent || ! is_dir( $canonical ) || ! PrivateStorage::noLinks( $root ) ||
				! PrivateStorage::inside( $canonical, $parent ) || PrivateStorage::inside( $parent, $canonical ) ) {
				self::fail(); }
			return wp_normalize_path( $canonical );
		}
		return wp_normalize_path( $root );
	}

	/**
	 * Check whether encrypted private storage is ready.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' );
	}

	/**
	 * Release a file or memory stream opened by encrypted storage.
	 *
	 * @param mixed $stream Stream for this operation.
	 */
	public static function close( $stream ): void {
		if ( is_resource( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the in-memory decrypted stream, which has no filesystem path.
			fclose( $stream );
		}
	}

	/**
	 * Resolve the path from a validated object key and the current site's uploads configuration.
	 *
	 * @param string $name   Name used to resolve the requested item.
	 * @param bool   $create Create for this operation.
	 */
	public static function objectRoot( string $name, bool $create = false ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( preg_match( '/^p3-([1-9][0-9]{0,18})-[a-f0-9]{64}\.lkf$/D', $name, $match ) ) {
			return self::formRoot( $match[1], $create );
		}
		return self::root( $create );
	}

	/**
	 * Resolve the encrypted storage directory for a form.
	 *
	 * @param string $formId Form identifier.
	 * @param bool   $create Create for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function formRoot( string $formId, bool $create = false ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $formId and its method binding.
		$uploads = wp_upload_dir( null, false );
		$base    = $uploads['basedir'] ?? '';
		if ( ! preg_match( '/^[1-9][0-9]{0,18}$/D', $formId ) || ! empty( $uploads['error'] ) || // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			! is_string( $base ) || ! preg_match( '~^(?:[A-Za-z]:/|/)~', wp_normalize_path( $base ) ) ||
			preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~', wp_normalize_path( $base ) ) ) {
			self::fail();
		}
		$root = rtrim( wp_normalize_path( $base ), '/' ) . '/leenkat-forms/' . $formId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		if ( ! PrivateStorage::noLinks( $root ) || ( $create && ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) {
			self::fail();
		}
		if ( file_exists( $root ) ) {
			$resolved = realpath( $root );
			$parent   = realpath( $base );
			if ( ! $resolved || ! $parent || ! is_dir( $resolved ) || ! PrivateStorage::noLinks( $root ) ||
				! PrivateStorage::inside( $resolved, $parent ) ) {
				self::fail();
			}
		}
		return $root;
	}

	/** Enumerate only numeric form subdirectories owned by this storage layout. */
	public static function formRoots(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$parent = dirname( self::formRoot( '1' ) );
		if ( ! is_dir( $parent ) ) {
			return array();
		}
		$roots = array();
		foreach ( new \DirectoryIterator( $parent ) as $entry ) {
			$form_id = $entry->getFilename();
			if ( ! $entry->isDir() || ! preg_match( '/^[1-9][0-9]{0,18}$/D', $form_id ) ) {
				continue;
			}
			$roots[ 'form:' . $form_id ] = self::formRoot( $form_id );
		}
		ksort( $roots );
		return $roots;
	}

	/**
	 * Build the associated data that binds a file to its entry context.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @return string Result for the requested operation.
	 */
	private static function aad( string $name ): string {
		return 'leenkat-forms-object-v2|' . $name; }

	/**
	 * Atomic replacement is allowed only for an existing encrypted migration journal.
	 *
	 * @param string $name    Name used to resolve the requested item.
	 * @param mixed  $in      In for this operation.
	 * @param bool   $replace Replace for this operation.
	 */
	public static function write( string $name, $in, bool $replace = false ): void {
		if ( ! function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_push' ) || ! self::validName( $name ) || ! is_resource( $in ) ) {
			self::fail(); }
		$root = self::objectRoot( $name, true );
		$dest = $root . '/' . $name;
		if ( ! PrivateStorage::noLinks( $dest ) || ( file_exists( $dest ) && ( ! $replace || ! is_file( $dest ) ) ) ) {
			self::fail(); }
		$tmp = $replace ? $root . '/write-' . bin2hex( random_bytes( 32 ) ) . '.lkf' : $dest . '.partial';
		if ( ! PrivateStorage::noLinks( $tmp ) ) {
			self::fail(); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		$out = @fopen( $tmp, 'xb' );
		if ( ! $out ) {
			self::fail(); }
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees. Apply private permissions on the owned local file on a best-effort basis. Warning output can disclose the local path on unsupported filesystems.
			@chmod( $tmp, 0600 );
			[$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push( self::key( true ) );
			$prefix           = self::MAGIC . $header;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
			if ( fwrite( $out, $prefix ) !== strlen( $prefix ) ) {
				self::fail(); }
			$size = 0;
			while ( true ) {
				$plain = stream_get_contents( $in, self::CHUNK );
				if ( false === $plain || ( '' === $plain && ! feof( $in ) ) ) {
					self::fail(); }
				$size += strlen( $plain );
				if ( $size > self::MAX_BYTES ) {
					self::fail(); }
				$last   = strlen( $plain ) < self::CHUNK;
				$packet = sodium_crypto_secretstream_xchacha20poly1305_push(
					$state,
					$plain,
					self::aad( $name ),
					$last
					? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
				);
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
				if ( fwrite( $out, $packet ) !== strlen( $packet ) ) {
					self::fail(); }
				if ( $last ) {
					break; }
			}
			if ( ! fflush( $out ) || ! fsync( $out ) ) {
				self::fail(); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
			fclose( $out );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees. A verified local rename can fail because the destination changed or storage is full. The existing failure branch preserves atomic publication without warning output.
			if ( ! PrivateStorage::noLinks( $dest ) || ! @rename( $tmp, $dest ) ) {
				self::fail(); }
		} finally {
			if ( is_resource( $out ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
				fclose( $out ); }
			if ( is_file( $tmp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees. The verified path may disappear between validation and cleanup. Preserve the existing boolean or best-effort result without disclosing the path.
				@unlink( $tmp ); }
		}
	}

	/**
	 * Validate a name in the encrypted storage format.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function validName( string $name ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return (bool) preg_match( '/^(?:p2-[a-f0-9]{64}|p3-[1-9][0-9]{0,18}-[a-f0-9]{64}|migration-[a-f0-9]{32})\.lkf$/D', $name );
	}

	/**
	 * Resolve the on-disk path for an encrypted file name.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @return string|null Result for the requested operation.
	 */
	public static function ciphertext( string $name ): ?string {
		if ( ! self::validName( $name ) ) {
			return null; }
		try {
			$root = self::objectRoot( $name ); } catch ( \Throwable ) {
			return null; }
			$path = $root . '/' . $name;
			$real = realpath( $path );
			return $real && PrivateStorage::inside( $real, $root ) && PrivateStorage::noLinks( $path ) && is_file( $real ) ? $real : null;
	}

	/**
	 * Verify the complete stream before exposing even one plaintext byte to callers.
	 *
	 * @param string      $name  Name used to resolve the requested item.
	 * @param int|null    $bytes Bytes for this operation.
	 * @param string|null $sha   Sha for this operation.
	 */
	public static function open( string $name, ?int $bytes = null, ?string $sha = null ) {
		$path = self::ciphertext( $name );
		if ( ! $path ) {
			return null; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		$in = @fopen( $path, 'rb' );
		if ( ! $in ) {
			return null; }
		$out = null;
		try {
			$header = stream_get_contents( $in, 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES );
			if ( ! is_string( $header ) || strlen( $header ) !== 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES || ! str_starts_with( $header, self::MAGIC ) ) {
				return null; }
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( substr( $header, 4 ), self::key() );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- This memory stream cannot spill to a plaintext file.
			$out = fopen( 'php://memory', 'w+b' );
			if ( ! $out ) {
				return null; }
			$size  = 0;
			$hash  = hash_init( 'sha256' );
			$frame = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
			while ( true ) {
				$packet = stream_get_contents( $in, $frame );
				if ( ! is_string( $packet ) || '' === $packet ) {
					return null; }
				$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $packet, self::aad( $name ) );
				if ( false === $result ) {
					return null; }
				[$plain, $tag] = $result;
				$last          = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag;
				if ( $last ? ( strlen( $packet ) >= $frame || false !== fgetc( $in ) || ! feof( $in ) ) :
					( SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE !== $tag || strlen( $packet ) !== $frame ) ) {
					return null; }
				$size += strlen( $plain );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
				if ( $size > self::MAX_BYTES || ( null !== $bytes && $size > $bytes ) || fwrite( $out, $plain ) !== strlen( $plain ) ) {
					return null; }
				hash_update( $hash, $plain );
				if ( $last ) {
					break; }
			}
			if ( ( null !== $bytes && $size !== $bytes ) || ( null !== $sha && ! hash_equals( $sha, hash_final( $hash ) ) ) || ! rewind( $out ) ) {
				return null; }
			$temp = $out;
			$out  = null;
			return $temp;
		} catch ( \Throwable ) {
			return null; } finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
			fclose( $in );
			if ( is_resource( $out ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
				fclose( $out ); }
			}
	}
}

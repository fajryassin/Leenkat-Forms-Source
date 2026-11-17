<?php
/**
 * Allowed attachment types and content validation.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/** Content detection supplements WordPress's extension allowlist. Archives are never extracted. */
final class MimePolicy {
	/**
	 * Check whether the file contents match the permitted MIME policy.
	 *
	 * @param string $path      Filesystem path to check.
	 * @param string $extension Extension for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function valid( string $path, string $extension ): bool {
		if ( ! class_exists( 'finfo' ) || ! is_file( $path ) ) {
			return false;
		}$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );
		$types = array(
			'jpg'  => array( 'image/jpeg' ),
			'jpeg' => array( 'image/jpeg' ),
			'png'  => array( 'image/png' ),
			'gif'  => array( 'image/gif' ),
			'webp' => array( 'image/webp' ),
			'pdf'  => array( 'application/pdf' ),
			'txt'  => array( 'text/plain' ),
			'csv'  => array( 'text/plain', 'text/csv', 'application/csv' ),
			'doc'  => array( 'application/msword', 'application/CDFV2', 'application/x-ole-storage' ),
			'xls'  => array( 'application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage' ),
			'ppt'  => array( 'application/vnd.ms-powerpoint', 'application/CDFV2', 'application/x-ole-storage' ),
			'docx' => array( 'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			'xlsx' => array( 'application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
			'pptx' => array( 'application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ),
			'zip'  => array( 'application/zip', 'application/x-zip', 'application/x-zip-compressed' ),
			'mp3'  => array( 'audio/mpeg', 'audio/mp3' ),
			'mp4'  => array( 'video/mp4', 'audio/mp4' ),
			'ogg'  => array( 'audio/ogg', 'application/ogg', 'video/ogg' ),
			'wav'  => array( 'audio/x-wav', 'audio/wav', 'audio/vnd.wave' ),
		);
		if ( ! in_array( $mime, $types[ $extension ] ?? array(), true ) ) {
			return false;
		}
		if ( in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Untrusted image bytes may fail to parse. Consume the result in the existing MIME policy without emitting parser warnings.
			$image = @getimagesize( $path );
			if ( ! $image || ( $image['mime'] ?? '' ) !== ( 'jpg' === $extension ? 'image/jpeg' : 'image/' . $extension ) ) {
				return false;
			}
		}
		if ( in_array( $extension, array( 'docx', 'xlsx', 'pptx' ), true ) ) {
			// WordPress's bundled ZIP reader is used only after bounding its central directory.
			$size = filesize( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
			$in = @fopen( $path, 'rb' );
			if ( ! $in ) {
				return false;
			}$length = min( $size, 65557 );
			fseek( $in, $size - $length );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
			$tail = fread( $in, $length );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
			fclose( $in );
			$offset = strrpos( $tail, "PK\x05\x06" );
			if ( false === $offset || strlen( $tail ) - $offset < 22 ) {
				return false;
			}
			$end = unpack( 'vdisk/vstart/vdisk_entries/ventries/Vbytes/Voffset/vcomment', substr( $tail, $offset + 4, 18 ) );
			if ( 0 !== $end['disk'] || 0 !== $end['start'] || $end['entries'] < 1 || $end['entries'] > 5000 || $end['disk_entries'] !== $end['entries'] || $end['bytes'] > 4194304 || $end['offset'] + $end['bytes'] > $size - $length + $offset || strlen( $tail ) !== $offset + 22 + $end['comment'] ) {
				return false;
			}
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$zip  = new \PclZip( $path );
			$list = $zip->listContent();
			if ( ! is_array( $list ) || count( $list ) !== $end['entries'] ) {
				return false;
			}$names = array();
			foreach ( $list as $file ) {
				$name = $file['filename'] ?? '';
				if ( ! is_string( $name ) || strlen( $name ) > 1000 || str_contains( str_replace( '\\', '/', $name ), '../' ) || preg_match( '~(?:^/|^[A-Za-z]:|(?:^|/)vbaProject\.bin$)~i', $name ) ) {
					return false;
				}$names[] = $name;}
			if ( ! in_array( '[Content_Types].xml', $names, true ) || ! in_array(
				array(
					'docx' => 'word/document.xml',
					'xlsx' => 'xl/workbook.xml',
					'pptx' => 'ppt/presentation.xml',
				)[ $extension ],
				$names,
				true
			) ) {
				return false;
			}
		}
		return true;
	}
}

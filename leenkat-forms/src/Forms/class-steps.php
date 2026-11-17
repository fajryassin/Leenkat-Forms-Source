<?php
/**
 * Validation and runtime helpers for form page breaks.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Presentation only: answers are always validated by the existing full-form pipeline. */
final class Steps {
	/**
	 * Check whether the form definition contains page breaks.
	 *
	 * @param array $definition Form definition being processed.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function hasBreaks( array $definition ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( ( $field['type'] ?? '' ) === 'page_break' ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Layout elements are adapted into the same page contract used since dev.47.
	 *
	 * @param array $definition Form definition being processed.
	 */
	public static function layout( array $definition ): array {
		if ( self::hasBreaks( $definition ) ) {
			$ids   = array_column( $definition['fields'], 'id' );
			$first = 'page_start';
			while ( in_array( $first, $ids, true ) ) {
				$first .= '_';
			}
			$text   = static function ( string $value, string $fallback ): string {
				$value = trim( $value );
				return '' === $value ? $fallback : $value;
			};
			$title  = $text( $definition['settings']['general']['first_page_title'] ?? '', sprintf( /* translators: %d: step number. */ __( 'Step %d', 'leenkat-forms' ), 1 ) );
			$result = array(
				array(
					'id'     => $first,
					'title'  => $title,
					'fields' => array(),
				),
			);
			$index  = 0;
			foreach ( $definition['fields'] as $field ) {
				if ( 'page_break' !== $field['type'] ) {
					$result[ $index ]['fields'][] = $field;
					continue;}
				$s                              = $field['settings'] ?? array();
				$result[ $index ]['next_label'] = $text( $s['next_label'] ?? '', __( 'Next', 'leenkat-forms' ) );
				++$index;
				$result[] = array(
					'id'             => $field['id'],
					'title'          => $text( $s['page_title'] ?? '', sprintf( /* translators: %d: step number. */ __( 'Step %d', 'leenkat-forms' ), $index + 1 ) ),
					'previous_label' => $text( $s['previous_label'] ?? '', __( 'Previous', 'leenkat-forms' ) ),
					'fields'         => array(),
				);
			}
			return $result;
		}
		$steps = $definition['steps'] ?? array();
		if ( ! $steps ) {
			return array();
		}
		$membership = array();
		foreach ( $steps as $index => $step ) {
			foreach ( $step['field_ids'] as $id ) {
				$membership[ $id ] = $index;
			}
		}
		$result = array();
		foreach ( $steps as $index => $step ) {
			$step_title = trim( $step['title'] ?? '' );
			$result[]   = array(
				'id'     => $step['id'],
				'title'  => $step_title ? $step_title : sprintf( /* translators: %d: step number. */ __( 'Step %d', 'leenkat-forms' ), $index + 1 ),
				'fields' => array(),
			);
		}
		foreach ( $definition['fields'] as $field ) {
			$result[ $membership[ $field['id'] ] ?? 0 ]['fields'][] = $field;
		}
		return $result;
	}
	/**
	 * Validate page breaks and step configuration in a form definition.
	 *
	 * @param array $definition Form definition being processed.
	 * @return array Result for the requested operation.
	 */
	public static function definitionErrors( array $definition ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! self::hasBreaks( $definition ) ) {
			return array();
		}
		$errors         = array();
		$fields         = $definition['fields'];
		$previous_break = true;
		$count          = 0;
		if ( ! empty( $definition['steps'] ) ) {
			$errors[] = __( 'Page Break fields cannot be combined with legacy steps. Convert the draft first.', 'leenkat-forms' );
		}
		foreach ( $fields as $field ) {
			if ( 'page_break' !== $field['type'] ) {
				$previous_break = false;
				continue;}
			if ( $previous_break ) {
				$errors[] = __( 'Page Breaks cannot lead the form or be consecutive.', 'leenkat-forms' );
			}
			$previous_break = true;
			++$count;
			if ( ! empty( $field['required'] ) || ! empty( $field['searchable'] ) ) {
				$errors[] = __( 'Page Break is a layout element and cannot be required or searchable.', 'leenkat-forms' );
			}
			foreach ( array(
				'page_title'     => 500,
				'next_label'     => 200,
				'previous_label' => 200,
			) as $key => $limit ) {
				if ( array_key_exists( $key, $field['settings'] ?? array() ) && ( ! is_string( $field['settings'][ $key ] ) || strlen( $field['settings'][ $key ] ) > $limit || ! preg_match( '//u', $field['settings'][ $key ] ) ) ) {
					$errors[] = __( 'Invalid Page Break ', 'leenkat-forms' ) . $key . '.';
				}
			}
		}
		if ( $previous_break ) {
			$errors[] = __( 'A Page Break cannot end the form.', 'leenkat-forms' );
		}
		if ( $count > 49 ) {
			$errors[] = __( 'A form supports at most 50 pages.', 'leenkat-forms' );
		}
		return array_values( array_unique( $errors ) );
	}
	/**
	 * Check whether the form steps can be published.
	 *
	 * @param array $definition Form definition being processed.
	 * @return array Result for the requested operation.
	 */
	public static function publicationErrors( array $definition ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! self::hasBreaks( $definition ) && count( $definition['steps'] ?? array() ) < 2 ) {
			return array();
		}
		$positions = array();
		foreach ( self::layout( $definition ) as $index => $step ) {
			foreach ( $step['fields'] as $field ) {
				$positions[ $field['id'] ] = $index;
			}
		}
		foreach ( $definition['rules'] ?? array() as $rule ) {
			foreach ( $rule['groups'] ?? array( array( $rule ) ) as $group ) {
				foreach ( $group as $condition ) {
					if ( ( $positions[ $condition['source_field_id'] ?? '' ] ?? -1 ) > ( $positions[ $rule['field_id'] ] ?? PHP_INT_MAX ) ) {
						return array( __( 'A conditional source must be on the same or an earlier step than its dependent field.', 'leenkat-forms' ) );
					}
				}
			}
		}
		return array();
	}
}

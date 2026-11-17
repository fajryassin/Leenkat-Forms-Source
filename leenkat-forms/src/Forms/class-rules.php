<?php
/**
 * Conditional rules controlling field and page visibility.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Restricted one-level visibility grammar; no dynamic code execution. */
final class Rules {
	/**
	 * Check whether a field can be used as a condition source.
	 *
	 * @param array $field Field definition used by this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function sourceAllowed( array $field ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return in_array( $field['type'] ?? '', array( 'text', 'textarea', 'email', 'phone', 'url', 'number', 'number_slider', 'select', 'radio', 'rating', 'checkbox', 'checkboxes', 'consent', 'name', 'date', 'time' ), true );
	}
	/**
	 * Comparison projection of submitted input, separate from output/storage sanitization.
	 *
	 * @param array $field Field definition used by this operation.
	 * @param mixed $value Value being processed.
	 */
	public static function normalizeSource( array $field, mixed $value ): mixed { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! self::sourceAllowed( $field ) ) {
			return null; }
		$type = $field['type'];
		if ( in_array( $type, array( 'checkbox', 'consent' ), true ) ) {
			return in_array( $value, array( '1', 1, true ), true ) ? '1' : ''; }
		if ( 'checkboxes' === $type || ( 'select' === $type && ! empty( $field['settings']['multiple'] ) ) ) {
			return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array(); }
		if ( in_array( $type, array( 'select', 'radio', 'rating' ), true ) ) {
			return is_string( $value ) ? $value : ''; }
		if ( 'name' === $type ) {
			$parts = array();
			foreach ( NameField::parts( $field['settings'] ?? array() ) as $part ) {
				$text = is_array( $value ) && is_string( $value[ $part ] ?? null ) ? TextLength::normalize( $value[ $part ] ) : '';
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}return implode( ' ', $parts );
		}
		return is_string( $value ) ? TextLength::normalize( $value ) : '';
	}
	/**
	 * Normalize a numeric operand for a condition.
	 *
	 * @param string $value Value being processed.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	private static function numeric( string $value ): bool {
		return 1 === preg_match( '/^[+-]?(?:[0-9]+\\.?[0-9]*|\\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD', TextLength::normalize( $value ) ) && is_finite( (float) $value );
	}
	/**
	 * Evaluate a configured condition against submitted values.
	 *
	 * @param array $rule   Rule for this operation.
	 * @param array $values Values from the current submission.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function condition( array $rule, array $values ): bool {
		if ( empty( $rule['source_field_id'] ) ) {
			return false; }
		$value = $values[ $rule['source_field_id'] ] ?? null;
		if ( is_array( $value ) ) {
			if ( 'is_empty' === $rule['operator'] ) {
				return array() === $value; }
			if ( 'not_empty' === $rule['operator'] ) {
				return array() !== $value; }
			$positive = match ( $rule['operator'] ) {
				'not_equals'=>'equals', 'not_contains'=>'contains', default=>$rule['operator'] };
			$matches = array_filter( $value, static fn( $item ): bool => self::condition( array_merge( $rule, array( 'operator' => $positive ) ), array( $rule['source_field_id'] => $item ) ) );
			return in_array( $rule['operator'], array( 'not_equals', 'not_contains' ), true ) ? ! $matches : (bool) $matches;
		}
		$actual   = true === $value ? '1' : ( false === $value || null === $value ? '' : (string) $value );
		$expected = (string) ( $rule['value'] ?? '' );
		return match ( $rule['operator'] ) {
			'equals' => $actual === $expected,
			'not_equals' => $actual !== $expected,
			'is_empty' => '' === $actual,
			'not_empty' => '' !== $actual,
			'contains' => str_contains( $actual, $expected ),
			'not_contains' => ! str_contains( $actual, $expected ),
			'starts_with' => str_starts_with( $actual, $expected ),
			'ends_with' => str_ends_with( $actual, $expected ),
			'greater_than' => self::numeric( $actual ) && self::numeric( $expected ) && (float) $actual > (float) $expected,
			'less_than' => self::numeric( $actual ) && self::numeric( $expected ) && (float) $actual < (float) $expected,
			default => false,
		};
	}
	/**
	 * Determine whether the form item is visible for the supplied values.
	 *
	 * @param string     $fieldId Field id for this operation.
	 * @param array      $rules   Rules for this operation.
	 * @param array      $values  Values from the current submission.
	 * @param array|null $fields  Fields handled by this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function visible( string $fieldId, array $rules, array $values, ?array $fields = null ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
		foreach ( $rules as $rule ) {
			if ( ( $rule['field_id'] ?? null ) !== $fieldId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
				continue; }
			if ( null !== $fields ) {
				$allowed = array();
				foreach ( $fields as $field ) {
					if ( self::sourceAllowed( $field ) ) {
						$allowed[] = $field['id'];
					}
				}foreach ( $rule['groups'] ?? array( array( $rule ) ) as $group ) {
					foreach ( $group as $clause ) {
						if ( ! in_array( $clause['source_field_id'] ?? '', $allowed, true ) ) {
										return false;
						}
					}
				}
			}
			$matches = isset( $rule['groups'] )
				? array_reduce( $rule['groups'], static fn( bool $any, array $group ): bool => $any || array_reduce( $group, static fn( bool $all, array $condition ): bool => $all && self::condition( $condition, $values ), true ), false )
				: self::condition( $rule, $values );
			if ( ( $rule['action'] ?? 'show' ) === 'hide' ? $matches : ! $matches ) {
				return false; }
		}
		return true;
	}
}

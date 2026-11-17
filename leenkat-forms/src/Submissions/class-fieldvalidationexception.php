<?php
/**
 * Field-specific validation errors returned by submission processing.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** Internal collection signal; caught before any storage or delivery work. */
final class FieldValidationException extends \RuntimeException {
	/**
	 * Create an exception that carries the invalid field details.
	 *
	 * @param array          $errors   Collected validation errors.
	 * @param \WP_Error|null $original Original for this operation.
	 */
	public function __construct( public readonly array $errors, public readonly ?\WP_Error $original = null ) {
		parent::__construct( __( 'Invalid form field', 'leenkat-forms' ) );}
}

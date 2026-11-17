<?php
/**
 * Contract for form field handlers and validation.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Contracts;

/**
 * Contract for form field handlers and validation.
 */
interface FieldTypeInterface {
	/**
	 * Return the field type identifier implemented by the handler.
	 *
	 * @return string Result for the requested operation.
	 */
	public function type(): string;
	/**
	 * Validate a field value under its form definition.
	 *
	 * @param mixed $value    Value being processed.
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public function validate( mixed $value, array $settings ): array;
}

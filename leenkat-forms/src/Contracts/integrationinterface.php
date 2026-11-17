<?php
/**
 * Contract for optional WordPress integrations.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Contracts;

/**
 * Contract for optional WordPress integrations.
 */
interface IntegrationInterface {
	/**
	 * Return the integration identifier.
	 *
	 * @return string Result for the requested operation.
	 */
	public function id(): string;
	/**
	 * Check whether the integration should be enabled.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public function isEnabled(): bool; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
	/**
	 * Register the integration with WordPress.
	 */
	public function register(): void;
}

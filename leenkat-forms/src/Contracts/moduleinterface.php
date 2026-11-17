<?php
/**
 * Contract for registering plugin modules.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Contracts;

/**
 * Contract for registering plugin modules.
 */
interface ModuleInterface {
	/**
	 * Return the module identifier.
	 *
	 * @return string Result for the requested operation.
	 */
	public function id(): string;
	/**
	 * Check whether the module should be loaded in this environment.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public function shouldLoad(): bool; // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
	/**
	 * Register the module hooks.
	 */
	public function register(): void;
}

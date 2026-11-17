<?php
/**
 * Module registration and initialization.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Core;

use LeenkatForms\Contracts\ModuleInterface;
/**
 * Module registration and initialization.
 */
final class ModuleRegistry {
	/**
	 * Registered modules indexed by key.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();
	/**
	 * Add a module to the registry.
	 *
	 * @param ModuleInterface $module Module for this operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public function register( ModuleInterface $module ): void {
		$id = $module->id();
		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/D', $id ) || isset( $this->modules[ $id ] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid or duplicate module identifier.', 'leenkat-forms' ) );
		}
		$this->modules[ $id ] = $module;
	}
	/**
	 * Register eligible modules with WordPress.
	 */
	public function boot(): void {
		foreach ( $this->modules as $module ) {
			if ( $module->shouldLoad() ) {
				$module->register(); }
		}
	}
	/**
	 * Return the list of registered module identifiers.
	 *
	 * @return array Result for the requested operation.
	 */
	public function ids(): array {
		return array_keys( $this->modules ); }
}

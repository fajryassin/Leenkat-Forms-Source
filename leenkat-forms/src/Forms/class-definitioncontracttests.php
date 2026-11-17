<?php
/**
 * Internal checks for supported form-definition contracts.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Deterministic, read-only checks of the schema-v1 contract. */
final class DefinitionContractTests {
	/**
	 * Build representative cases for the definition contract checks.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function cases(): array {
		$base  = array(
			'schema_version' => 1,
			'title'          => 'Test',
			'fields'         => array(
				array(
					'id'       => 'email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				),
			),
		);
		$cases = array(
			'valid minimal definition'         => array( DefinitionValidator::validate( $base ) === array(), true ),
			'reject duplicate IDs'             => array(
				DefinitionValidator::validate(
					array(
						'schema_version' => 1,
						'title'          => 'Test',
						'fields'         => array(
							array(
								'id'    => 'email',
								'type'  => 'email',
								'label' => 'Email',
							),
							array(
								'id'    => 'email',
								'type'  => 'text',
								'label' => 'Text',
							),
						),
					)
				) !== array(),
				true,
			),
			'reject unknown step field'        => array(
				DefinitionValidator::validate(
					$base + array(
						'steps' => array(
							array(
								'id'        => 'first',
								'field_ids' => array( 'missing' ),
							),
						),
					)
				) !== array(),
				true,
			),
			'reject invalid schema version'    => array(
				DefinitionValidator::validate(
					array(
						'schema_version' => 2,
						'title'          => 'Test',
						'fields'         => array(),
					)
				) !== array(),
				true,
			),
			'reject non-list fields'           => array(
				DefinitionValidator::validate(
					array(
						'schema_version' => 1,
						'title'          => 'Test',
						'fields'         => array(
							'email' => array(
								'id'    => 'email',
								'type'  => 'email',
								'label' => 'Email',
							),
						),
					)
				) !== array(),
				true,
			),
			'reject malformed required'        => array(
				DefinitionValidator::validate(
					array(
						'schema_version' => 1,
						'title'          => 'Test',
						'fields'         => array(
							array(
								'id'       => 'email',
								'type'     => 'email',
								'label'    => 'Email',
								'required' => 'yes',
							),
						),
					)
				) !== array(),
				true,
			),
			'reject duplicate step assignment' => array(
				DefinitionValidator::validate(
					$base + array(
						'steps' => array(
							array(
								'id'        => 'first',
								'field_ids' => array( 'email' ),
							),
							array(
								'id'        => 'second',
								'field_ids' => array( 'email' ),
							),
						),
					)
				) !== array(),
				true,
			),
			'stable canonical JSON hash'       => array(
				DefinitionCodec::hash( $base ) === DefinitionCodec::hash(
					array(
						'fields'         => $base['fields'],
						'title'          => 'Test',
						'schema_version' => 1,
					)
				),
				true,
			),
		);
		return array_map( static fn( array $test_case ): bool => $test_case[0] === $test_case[1], $cases );
	}
	/**
	 * Run the form-definition contract checks.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function run(): bool {
		return ! in_array( false, self::cases(), true ); }
}

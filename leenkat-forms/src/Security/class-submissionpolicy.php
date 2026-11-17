<?php
/**
 * Limits and policy defaults for public form submissions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Immutable public-endpoint policy defaults. */
final class SubmissionPolicy {
	public const MAX_FIELDS                 = 300;
	public const MAX_REQUEST_BYTES          = 1048576; // Excludes separately validated file uploads.
	public const REQUIRE_PUBLISHED_FORM     = true;
	public const PUBLIC_READ_ALLOWED        = false;
	public const SERVER_VALIDATION_REQUIRED = true;
}

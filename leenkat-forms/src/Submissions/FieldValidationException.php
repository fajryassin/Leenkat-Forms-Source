<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** Internal collection signal; caught before any storage or delivery work. */
final class FieldValidationException extends \RuntimeException {
    public function __construct(public readonly array $errors,public readonly ?\WP_Error $original=null){parent::__construct(__('Invalid form field', 'leenkat-forms'));}
}

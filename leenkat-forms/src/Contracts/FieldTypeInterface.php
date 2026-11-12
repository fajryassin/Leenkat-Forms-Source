<?php
declare(strict_types=1);
namespace LeenkatForms\Contracts;
interface FieldTypeInterface {
    public function type(): string;
    public function validate(mixed $value, array $settings): array;
}

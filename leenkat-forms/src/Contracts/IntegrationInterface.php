<?php
declare(strict_types=1);
namespace LeenkatForms\Contracts;
interface IntegrationInterface {
    public function id(): string;
    public function isEnabled(): bool;
    public function register(): void;
}

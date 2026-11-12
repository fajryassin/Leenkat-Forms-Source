<?php
declare(strict_types=1);
namespace LeenkatForms\Contracts;
interface ModuleInterface {
    public function id(): string;
    public function shouldLoad(): bool;
    public function register(): void;
}

<?php
declare(strict_types=1);
namespace LeenkatForms\Core;
use LeenkatForms\Contracts\ModuleInterface;
final class ModuleRegistry {
    /** @var array<string, ModuleInterface> */
    private array $modules = [];
    public function register(ModuleInterface $module): void {
        $id = $module->id();
        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $id) || isset($this->modules[$id])) {
            throw new \InvalidArgumentException(esc_html__('Invalid or duplicate module identifier.', 'leenkat-forms'));
        }
        $this->modules[$id] = $module;
    }
    public function boot(): void {
        foreach ($this->modules as $module) {
            if ($module->shouldLoad()) { $module->register(); }
        }
    }
    public function ids(): array { return array_keys($this->modules); }
}

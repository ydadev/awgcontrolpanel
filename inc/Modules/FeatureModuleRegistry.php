<?php

final class FeatureModuleRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $modules = [];
    private static bool $booted = false;
    private static bool $routesRegistered = false;

    /**
     * @param array{enabled?:string,disabled?:string}|null $configuration
     */
    public static function boot(string $modulesPath, ?array $configuration = null): void
    {
        $manifests = self::discover($modulesPath);
        $configuration ??= [
            'enabled' => (string) (getenv('MODULES_ENABLED') ?: ''),
            'disabled' => (string) (getenv('MODULES_DISABLED') ?: ''),
        ];

        $enabledFilter = self::parseList((string) ($configuration['enabled'] ?? ''));
        $disabled = self::parseList((string) ($configuration['disabled'] ?? ''));
        $known = array_fill_keys(array_keys($manifests), true);

        foreach (array_unique(array_merge($enabledFilter, $disabled)) as $id) {
            if (!isset($known[$id])) {
                throw new RuntimeException("Unknown feature module in configuration: {$id}");
            }
        }

        $explicitAllowlist = $enabledFilter !== [];
        $enabledLookup = array_fill_keys($enabledFilter, true);
        $disabledLookup = array_fill_keys($disabled, true);

        foreach ($manifests as $id => $manifest) {
            foreach ($manifest['dependencies'] as $dependency) {
                if (!isset($known[$dependency])) {
                    throw new RuntimeException("Feature module {$id} has unknown dependency: {$dependency}");
                }
            }
        }

        foreach ($manifests as $id => &$manifest) {
            $required = (bool) $manifest['required'];
            if ($required && isset($disabledLookup[$id])) {
                throw new RuntimeException("Required feature module cannot be disabled: {$id}");
            }

            $enabled = $required || ($explicitAllowlist
                ? isset($enabledLookup[$id])
                : (bool) $manifest['default_enabled']);
            if (isset($disabledLookup[$id])) {
                $enabled = false;
            }
            $manifest['enabled'] = $enabled;
        }
        unset($manifest);

        foreach ($manifests as $id => $manifest) {
            if (!$manifest['enabled']) {
                continue;
            }
            foreach ($manifest['dependencies'] as $dependency) {
                if (!$manifests[$dependency]['enabled']) {
                    throw new RuntimeException("Feature module {$id} requires disabled module: {$dependency}");
                }
            }
        }

        self::$modules = $manifests;
        self::$booted = true;
    }

    public static function isEnabled(string $id): bool
    {
        self::ensureBooted();
        return isset(self::$modules[$id]) && (bool) self::$modules[$id]['enabled'];
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /** @return array<string, array<string, mixed>> */
    public static function states(): array
    {
        self::ensureBooted();
        $states = self::$modules;
        foreach ($states as &$module) {
            $module['has_routes'] = $module['routes_file'] !== null;
            unset($module['routes_file']);
        }
        unset($module);
        return $states;
    }

    public static function registerRoutes(): void
    {
        self::ensureBooted();
        if (self::$routesRegistered) {
            throw new RuntimeException('Feature module routes have already been registered');
        }

        foreach (self::$modules as $module) {
            if ($module['routes_file'] === null) {
                continue;
            }
            $registrar = require $module['routes_file'];
            if (!is_callable($registrar)) {
                throw new RuntimeException("Feature module route file must return a callable: {$module['id']}");
            }
            $registrar();
        }
        self::$routesRegistered = true;
    }

    public static function resetForTests(): void
    {
        self::$modules = [];
        self::$booted = false;
        self::$routesRegistered = false;
    }

    /** @return array<string, array<string, mixed>> */
    private static function discover(string $modulesPath): array
    {
        if (!is_dir($modulesPath)) {
            throw new RuntimeException("Feature modules directory does not exist: {$modulesPath}");
        }

        $manifestFiles = glob(rtrim($modulesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'module.php') ?: [];
        sort($manifestFiles, SORT_STRING);
        $modules = [];

        foreach ($manifestFiles as $manifestFile) {
            $manifest = require $manifestFile;
            if (!is_array($manifest)) {
                throw new RuntimeException("Feature module manifest must return an array: {$manifestFile}");
            }
            $id = (string) ($manifest['id'] ?? '');
            $directoryId = basename(dirname($manifestFile));
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $id) || $id !== $directoryId) {
                throw new RuntimeException("Invalid feature module id in: {$manifestFile}");
            }
            if (isset($modules[$id])) {
                throw new RuntimeException("Duplicate feature module id: {$id}");
            }

            $dependencies = array_values(array_unique(array_map('strval', $manifest['dependencies'] ?? [])));
            $routesFile = null;
            if (isset($manifest['routes'])) {
                $routesName = (string) $manifest['routes'];
                if ($routesName === '' || basename($routesName) !== $routesName) {
                    throw new RuntimeException("Invalid routes filename for feature module: {$id}");
                }
                $candidate = dirname($manifestFile) . DIRECTORY_SEPARATOR . $routesName;
                if (!is_file($candidate)) {
                    throw new RuntimeException("Feature module routes file does not exist: {$id}");
                }
                $routesFile = $candidate;
            }
            $modules[$id] = [
                'id' => $id,
                'name' => (string) ($manifest['name'] ?? $id),
                'description' => (string) ($manifest['description'] ?? ''),
                'required' => (bool) ($manifest['required'] ?? false),
                'default_enabled' => (bool) ($manifest['default_enabled'] ?? true),
                'dependencies' => $dependencies,
                'owned_tables' => array_values(array_unique(array_map('strval', $manifest['owned_tables'] ?? []))),
                'workers' => array_values(array_unique(array_map('strval', $manifest['workers'] ?? []))),
                'routes_file' => $routesFile,
                'enabled' => false,
            ];
        }

        if (!isset($modules['core']) || !$modules['core']['required']) {
            throw new RuntimeException('A required core feature module manifest is mandatory');
        }
        return $modules;
    }

    /** @return array<int, string> */
    private static function parseList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $items = array_map(static fn(string $item): string => strtolower(trim($item)), explode(',', $value));
        return array_values(array_unique(array_filter($items, static fn(string $item): bool => $item !== '')));
    }

    private static function ensureBooted(): void
    {
        if (!self::$booted) {
            throw new RuntimeException('FeatureModuleRegistry has not been booted');
        }
    }
}

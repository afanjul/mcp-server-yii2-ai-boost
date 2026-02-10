<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools\Base;

use yii\base\Component;

/**
 * Base class for MCP Tools
 *
 * All MCP tools should extend this class and implement the required methods.
 * 
 * @property \yii\base\Application $app The Yii application instance
 */
abstract class BaseTool extends Component
{
    /**
     * @var string Base path to the Yii2 application
     */
    public $basePath;

    /**
     * Get the tool name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get the tool description
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get the tool input schema (JSON Schema)
     *
     * @return array
     */
    abstract public function getInputSchema(): array;

    /**
     * Execute the tool with given arguments
     *
     * @param array $arguments Tool arguments
     * @return mixed Result data
     * @throws \Exception
     */
    abstract public function execute(array $arguments): mixed;

    /**
     * Sanitize output to remove sensitive data
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    protected function sanitize(mixed $data): mixed
    {
        // List of sensitive keys to filter
        $sensitiveKeys = [
            'password', 'secret', 'key', 'token', 'api_key', 'private_key',
            'auth_key', 'access_token', 'refresh_token', 'client_secret',
        ];

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Only check string keys for sensitive patterns
                if (is_string($key)) {
                    $lowerKey = strtolower($key);

                    // Check if key contains sensitive pattern
                    $isSensitive = false;
                    foreach ($sensitiveKeys as $pattern) {
                        if (stripos($lowerKey, $pattern) !== false) {
                            $isSensitive = true;
                            break;
                        }
                    }

                    if ($isSensitive) {
                        $sanitized[$key] = '***REDACTED***';
                    } else {
                        $sanitized[$key] = $this->sanitize($value);
                    }
                } else {
                    // Non-string keys (integers, etc) are always safe
                    $sanitized[$key] = $this->sanitize($value);
                }
            }
            return $sanitized;
        } elseif (is_string($data) && !empty($data)) {
            // Don't sanitize regular strings
            return $data;
        }

        return $data;
    }

    /**
     * Get all database connections
     *
     * @return array Array of database names and connection info
     */
    protected function getDbConnections(): array
    {
        $connections = [];
        $app = \Yii::$app;

        // Main database connection
        if ($app->has('db')) {
            $db = $app->get('db');
            $connections['main'] = [
                'dsn' => $db->dsn,
                'driver' => $this->getDbDriver($db->dsn),
                'username' => $db->username,
            ];
        }

        // Additional named connections
        foreach ($app->get('components', []) as $name => $config) {
            if (
                is_array($config) && isset($config['class']) &&
                (stripos($config['class'], 'yii\db\Connection') !== false)
            ) {
                if ($name !== 'db') {
                    $db = $app->get($name);
                    $connections[$name] = [
                        'dsn' => $db->dsn,
                        'driver' => $this->getDbDriver($db->dsn),
                        'username' => $db->username,
                    ];
                }
            }
        }

        return $connections;
    }

    /**
     * Extract database driver from DSN
     *
     * @param string $dsn Database DSN
     * @return string Driver name
     */
    protected function getDbDriver(string $dsn): string
    {
        $driver = explode(':', $dsn)[0] ?? 'unknown';
        return $driver;
    }

    /**
     * Discover Active Record models in the application models directory
     *
     * @return array Fully qualified class names
     */
    protected function getActiveRecordModels(): array
    {
        return $this->getActiveRecordModelsFromPath('@app/models');
    }

    /**
     * Extract fully qualified class name from a PHP file using token parsing
     *
     * @param string $file Absolute file path
     * @return string|null Fully qualified class name or null
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $namespace = '';
        $className = '';

        $tokens = token_get_all(file_get_contents($file));

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    // PHP 8.0+ uses T_NAME_QUALIFIED for multi-part namespace names
                    if (defined('T_NAME_QUALIFIED') && $tokens[$j][0] === T_NAME_QUALIFIED) {
                        $namespace = $tokens[$j][1];
                        break;
                    } elseif ($tokens[$j][0] === T_STRING) {
                        $namespace .= $tokens[$j][1];
                    } elseif ($tokens[$j][0] === T_NS_SEPARATOR) {
                        $namespace .= '\\';
                    } elseif ($tokens[$j][0] === ';') {
                        break;
                    }
                }
            }

            // Skip ::class constant access (T_DOUBLE_COLON followed by T_CLASS)
            if ($tokens[$i][0] === T_CLASS && ($i === 0 || $tokens[$i - 1][0] !== T_DOUBLE_COLON)) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
            }
        }

        return $namespace && $className ? $namespace . '\\' . $className : null;
    }

    /**
     * Check if a class extends yii\db\ActiveRecord by walking the parent chain
     *
     * @param string $className Fully qualified class name
     * @return bool
     */
    protected function isActiveRecordModel(string $className): bool
    {
        try {
            if (!class_exists($className)) {
                return false;
            }

            $reflection = new \ReflectionClass($className);
            $parent = $reflection->getParentClass();

            while ($parent) {
                if ($parent->getName() === 'yii\db\ActiveRecord') {
                    return true;
                }
                $parent = $parent->getParentClass();
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resolve a model class from a fully qualified class name
     *
     * @param string $model FQCN (e.g., "common\models\Contact", "app\models\User")
     * @return string Fully qualified class name
     * @throws \Exception If model cannot be found or is not an ActiveRecord
     */
    protected function resolveModelClass(string $model): string
    {
        // If it contains a backslash, treat as FQCN
        if (strpos($model, '\\') !== false) {
            if (!class_exists($model)) {
                throw new \Exception("Model class '$model' not found");
            }
            if (!$this->isActiveRecordModel($model)) {
                throw new \Exception("Class '$model' is not an ActiveRecord model");
            }
            return $model;
        }

        // Search in multiple directories for better auto-detection
        $searchPaths = [
            'common\models',  // For multi-tenant applications
            '@app/models',    // Standard Yii2 applications
            'backend\models', // Backend-specific models
            'frontend/models', // Frontend-specific models
        ];

        $foundModels = [];
        
        foreach ($searchPaths as $path) {
            $models = $this->getActiveRecordModelsFromPath($path);
            foreach ($models as $className) {
                $parts = explode('\\', $className);
                $shortName = end($parts);
                if (strcasecmp($shortName, $model) === 0) {
                    $foundModels[] = $className;
                }
            }
        }

        if (count($foundModels) === 1) {
            return $foundModels[0];
        }
        
        if (count($foundModels) > 1) {
            throw new \Exception(
                "Multiple models found for '$model':\n" .
                implode("\n", $foundModels) .
                "\nPlease use full class name to specify which one."
            );
        }

        // No found - show available models with full class names
        $availableModels = $this->getAvailableModelNames();
        throw new \Exception(
            "Model '$model' not found.\n" .
            "Available models (use full class name):\n" . implode("\n", array_slice($availableModels, 0, 10)) .
            (count($availableModels) > 10 ? "\n... and " . (count($availableModels) - 10) . " more" : "")
        );
    }

    /**
     * Get Active Record models from a specific path
     *
     * @param string $path Path to search (e.g., 'common\models')
     * @return array Fully qualified class names
     */
    protected function getActiveRecordModelsFromPath(string $path): array
    {
        $modelsPath = \Yii::getAlias($path);
        if (!is_dir($modelsPath)) {
            return [];
        }

        $models = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modelsPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $this->getClassNameFromFile($file->getPathname());
                if ($className && $this->isActiveRecordModel($className)) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    /**
     * Get all available model names from all search paths
     *
     * @return array Array of model names
     */
    protected function getAvailableModelNames(): array
    {
        $searchPaths = [
            'common\models',
            '@app/models',
            'backend\models',
            'frontend/models',
        ];

        $allModels = [];
        foreach ($searchPaths as $path) {
            $models = $this->getActiveRecordModelsFromPath($path);
            $allModels = array_merge($allModels, $models);
        }

        // Remove duplicates and sort
        $allModels = array_unique($allModels);
        sort($allModels);

        return $allModels;
    }
}

<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Model Inspector Tool
 *
 * Provides Active Record model analysis including:
 * - Attributes with types, labels, and hints
 * - Relations (hasOne/hasMany) with link details
 * - Attached behaviors
 * - Scenarios with active attributes
 * - Fields and extra fields for API serialization
 */
final class ModelInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'model_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect Active Record models including attributes, relations, behaviors, scenarios, and fields';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'Model class name or short name (e.g., "User" or "app\\models\\User")',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: attributes, relations, behaviors, scenarios, fields, all',
                ],
                'tenant_id' => [
                    'type' => 'integer',
                    'description' => 'Tenant ID for multi-tenant applications (optional, auto-detects if not provided)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $modelName = $arguments['model'] ?? '';
        $include = $arguments['include'] ?? ['attributes', 'relations'];
        $tenantId = $arguments['tenant_id'] ?? null;

        if (empty($modelName)) {
            return ['models' => $this->getActiveRecordModels()];
        }

        if (in_array('all', $include)) {
            $include = ['attributes', 'relations', 'behaviors', 'scenarios', 'fields'];
        }

        $className = $this->resolveModelClass($modelName);

        // Setup tenant context before instantiation
        $this->setupTenantContext($tenantId);

        try {
            $instance = new $className();
        } catch (\Exception $e) {
            throw new \Exception("Cannot instantiate model '$className': " . $e->getMessage());
        } finally {
            // Always cleanup tenant context
            $this->clearTenantContext();
        }

        $result = [
            'class' => $className,
            'table' => $className::tableName(),
            'primary_key' => $className::primaryKey(),
        ];

        if (in_array('attributes', $include)) {
            $result['attributes'] = $this->getAttributes($instance);
        }

        if (in_array('relations', $include)) {
            $result['relations'] = $this->getRelations($instance);
        }

        if (in_array('behaviors', $include)) {
            $result['behaviors'] = $this->inspectBehaviors($instance);
        }

        if (in_array('scenarios', $include)) {
            $result['scenarios'] = $this->getScenarios($instance);
        }

        if (in_array('fields', $include)) {
            $result['fields'] = $this->getFields($instance);
        }

        return $result;
    }

    /**
     * Get model attributes with types from table schema, labels, and hints
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getAttributes(object $instance): array
    {
        $attributes = $instance->attributes();
        $labels = $instance->attributeLabels();
        $hints = $instance->attributeHints();

        $tableSchema = null;
        if (method_exists($instance, 'getTableSchema')) {
            try {
                $tableSchema = $instance::getTableSchema();
            } catch (\Exception $e) {
                // Table may not exist
            }
        }

        $result = [];
        foreach ($attributes as $name) {
            $attr = [
                'label' => $labels[$name] ?? null,
                'hint' => $hints[$name] ?? null,
            ];

            if ($tableSchema && isset($tableSchema->columns[$name])) {
                $column = $tableSchema->columns[$name];
                $attr['type'] = $column->type;
                $attr['db_type'] = $column->dbType;
                $attr['php_type'] = $column->phpType;
                $attr['size'] = $column->size;
                $attr['allow_null'] = $column->allowNull;
                $attr['default'] = $column->defaultValue;
                $attr['auto_increment'] = $column->autoIncrement;
            }

            $result[$name] = $attr;
        }

        return $result;
    }

    /**
     * Discover model relations by inspecting getter methods that return ActiveQuery
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getRelations(object $instance): array
    {
        $relations = [];

        $reflection = new \ReflectionClass($instance);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            // Only consider get*() methods with no required parameters
            if (strpos($methodName, 'get') !== 0 || strlen($methodName) <= 3) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Skip methods declared in framework classes
            $declaringClass = $method->getDeclaringClass()->getName();
            if (strpos($declaringClass, 'yii\\') === 0) {
                continue;
            }

            // Try to call the method and check if it returns an ActiveQuery
            try {
                $returnValue = $instance->{$methodName}();

                if (!($returnValue instanceof \yii\db\ActiveQueryInterface)) {
                    continue;
                }

                // Extract relation name from method name (getOrders -> orders)
                $relationName = lcfirst(substr($methodName, 3));

                $relation = [
                    'name' => $relationName,
                    'type' => $returnValue->multiple ? 'hasMany' : 'hasOne',
                ];

                if (isset($returnValue->modelClass)) {
                    $relation['model_class'] = $returnValue->modelClass;
                }

                if (isset($returnValue->link)) {
                    $relation['link'] = $returnValue->link;
                }

                if (!empty($returnValue->via)) {
                    $relation['via'] = is_string($returnValue->via)
                        ? $returnValue->via
                        : '[junction]';
                }

                $relations[$relationName] = $relation;
            } catch (\Exception $e) {
                // Method threw an exception — skip it
            }
        }

        return $relations;
    }

    /**
     * Get attached behaviors with their class and configuration
     *
     * @param object $instance Model instance
     * @return array
     */
    private function inspectBehaviors(object $instance): array
    {
        $behaviors = [];

        try {
            $attachedBehaviors = $instance->getBehaviors();
        } catch (\Exception $e) {
            return $behaviors;
        }

        foreach ($attachedBehaviors as $name => $behavior) {
            $behaviorInfo = [
                'class' => get_class($behavior),
            ];

            // Get public properties specific to this behavior
            try {
                $reflection = new \ReflectionClass($behavior);
                $props = [];
                foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                    if ($property->isStatic()) {
                        continue;
                    }
                    // Skip properties inherited from base Behavior class
                    if ($property->getDeclaringClass()->getName() === 'yii\\base\\Behavior') {
                        continue;
                    }
                    $propName = $property->getName();
                    try {
                        $value = $property->getValue($behavior);
                        if (is_object($value)) {
                            $props[$propName] = get_class($value);
                        } elseif (is_array($value)) {
                            $props[$propName] = $value;
                        } else {
                            $props[$propName] = $value;
                        }
                    } catch (\Exception $e) {
                        $props[$propName] = '[unable to read]';
                    }
                }
                if (!empty($props)) {
                    $behaviorInfo['properties'] = $this->sanitize($props);
                }
            } catch (\Exception $e) {
                // Cannot reflect behavior
            }

            $behaviors[$name] = $behaviorInfo;
        }

        return $behaviors;
    }

    /**
     * Get all defined scenarios with their active and safe attributes
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getScenarios(object $instance): array
    {
        $scenarios = $instance->scenarios();
        $result = [];

        foreach ($scenarios as $scenarioName => $attributes) {
            $safe = [];
            $all = [];

            foreach ($attributes as $attribute) {
                if (strpos($attribute, '!') === 0) {
                    $all[] = substr($attribute, 1);
                } else {
                    $safe[] = $attribute;
                    $all[] = $attribute;
                }
            }

            $result[$scenarioName] = [
                'attributes' => $all,
                'safe_attributes' => $safe,
            ];
        }

        return $result;
    }

    /**
     * Get fields and extra fields for API serialization
     *
     * @param object $instance Model instance
     * @return array
     */
    private function getFields(object $instance): array
    {
        // Populate attributes so BaseActiveRecord::fields() returns column names
        // (fields() uses _attributes, which is empty on a fresh instance)
        $attributes = $instance->attributes();
        foreach ($attributes as $attr) {
            $instance->setAttribute($attr, $instance->getAttribute($attr));
        }

        $result = [
            'fields' => array_keys($instance->fields()),
        ];

        if (method_exists($instance, 'extraFields')) {
            $result['extra_fields'] = $instance->extraFields();
        }

        return $result;
    }

    /**
     * Setup tenant context before model instantiation
     * 
     * @param int|null $tenantId Tenant ID or null to auto-detect
     * @return void
     */
    private function setupTenantContext(?int $tenantId): void
    {
        if ($tenantId === null) {
            // Auto-detect first available tenant
            $tenantId = $this->getDefaultTenantId();
        }

        if ($tenantId && \Yii::$app->has('tenantResolver')) {
            \Yii::$app->tenantResolver->setTenantId($tenantId);
        }
    }

    /**
     * Clear tenant context after execution
     * 
     * @return void
     */
    private function clearTenantContext(): void
    {
        if (\Yii::$app->has('tenantResolver')) {
            // Optional: reset tenant_id to null
            // \Yii::$app->tenantResolver->setTenantId(null);
        }
    }

    /**
     * Get default tenant ID by querying the first account
     * 
     * @return int|null
     */
    private function getDefaultTenantId(): ?int
    {
        try {
            $db = \Yii::$app->db;
            $tenantId = $db->createCommand(
                'SELECT account_id FROM account ORDER BY account_id ASC LIMIT 1'
            )->queryScalar();

            return $tenantId ? (int)$tenantId : 1;
        } catch (\Exception $e) {
            // Fallback: use tenant_id = 1
            return 1;
        }
    }
}

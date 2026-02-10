<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Validation Rules Tool
 *
 * Provides model validation introspection including:
 * - All validation rules with their parameters
 * - Custom vs built-in validator classification
 * - Error messages per rule
 * - Constraint summary (required, unique, type, range)
 * - Safe attributes per scenario
 */
final class ValidationRulesTool extends BaseTool
{
    /**
     * Built-in Yii2 validator short names
     *
     * @var array
     */
    private const BUILTIN_VALIDATORS = [
        'boolean', 'captcha', 'compare', 'date', 'datetime', 'time',
        'default', 'double', 'number', 'each', 'email', 'exist',
        'file', 'filter', 'image', 'in', 'integer', 'match',
        'required', 'safe', 'string', 'trim', 'unique', 'url', 'ip',
    ];

    public function getName(): string
    {
        return 'validation_rules';
    }

    public function getDescription(): string
    {
        return 'Inspect model validation rules, error messages, constraints, and safe attributes';
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
                'scenario' => [
                    'type' => 'string',
                    'description' => 'Filter rules by scenario (optional)',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: rules, messages, constraints, safe_attributes, all',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $modelName = $arguments['model'] ?? '';
        $scenario = $arguments['scenario'] ?? null;
        $include = $arguments['include'] ?? ['rules', 'constraints'];

        if (empty($modelName)) {
            return ['models' => $this->getActiveRecordModels()];
        }

        if (in_array('all', $include)) {
            $include = ['rules', 'messages', 'constraints', 'safe_attributes'];
        }

        $className = $this->resolveModelClass($modelName);

        try {
            $instance = new $className();
        } catch (\Exception $e) {
            throw new \Exception("Cannot instantiate model '$className': " . $e->getMessage());
        }

        $result = [
            'class' => $className,
            'table' => $className::tableName(),
        ];

        if ($scenario !== null) {
            $result['scenario'] = $scenario;
        }

        if (in_array('rules', $include)) {
            $result['rules'] = $this->getRules($instance, $scenario);
        }

        if (in_array('messages', $include)) {
            $result['messages'] = $this->getMessages($instance, $scenario);
        }

        if (in_array('constraints', $include)) {
            $result['constraints'] = $this->getConstraints($instance, $scenario);
        }

        if (in_array('safe_attributes', $include)) {
            $result['safe_attributes'] = $this->getSafeAttributes($instance, $scenario);
        }

        return $result;
    }

    /**
     * Parse raw rules() array into structured rule definitions
     *
     * @param object $instance Model instance
     * @param string|null $scenario Optional scenario filter
     * @return array
     */
    private function getRules(object $instance, ?string $scenario): array
    {
        $rawRules = $instance->rules();
        $parsed = [];

        foreach ($rawRules as $rule) {
            if (!is_array($rule) || count($rule) < 2) {
                continue;
            }

            // First element is attribute(s), second is validator
            $attributes = (array) $rule[0];
            $validator = $rule[1];

            // Extract options (everything after first two elements)
            $options = array_slice($rule, 2);
            $params = [];
            // Options can be key-value pairs in the array
            foreach ($options as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            // Also check for inline options in the rule array itself
            foreach ($rule as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $on = isset($params['on']) ? (array) $params['on'] : [];
            $except = isset($params['except']) ? (array) $params['except'] : [];

            // Filter by scenario if provided
            if ($scenario !== null) {
                if (!empty($on) && !in_array($scenario, $on)) {
                    continue;
                }
                if (!empty($except) && in_array($scenario, $except)) {
                    continue;
                }
            }

            // Classify validator type
            $isBuiltin = true;
            $validatorType = $validator;
            if (is_string($validator)) {
                if (!in_array($validator, self::BUILTIN_VALIDATORS)) {
                    $isBuiltin = strpos($validator, '\\') === false;
                }
            } elseif (is_array($validator) || $validator instanceof \Closure) {
                $isBuiltin = false;
                $validatorType = 'inline';
            }

            // Remove meta-options from params for cleaner output
            $cleanParams = $params;
            unset($cleanParams['on'], $cleanParams['except'], $cleanParams['message']);

            $entry = [
                'attributes' => $attributes,
                'validator' => is_string($validatorType) ? $validatorType : 'inline',
                'builtin' => $isBuiltin,
            ];

            if (!empty($on)) {
                $entry['on'] = $on;
            }
            if (!empty($except)) {
                $entry['except'] = $except;
            }
            if (isset($params['message'])) {
                $entry['message'] = $params['message'];
            }
            if (!empty($cleanParams)) {
                $entry['params'] = $cleanParams;
            }

            $parsed[] = $entry;
        }

        return $parsed;
    }

    /**
     * Extract error messages from validator instances grouped by attribute
     *
     * @param object $instance Model instance
     * @param string|null $scenario Optional scenario filter
     * @return array
     */
    private function getMessages(object $instance, ?string $scenario): array
    {
        if ($scenario !== null) {
            $instance->setScenario($scenario);
        }

        $messages = [];

        try {
            $validators = $instance->getActiveValidators();
        } catch (\Exception $e) {
            return $messages;
        }

        foreach ($validators as $validator) {
            /** @var string $validatorClass */
            $validatorClass = get_class($validator);
            $lastSlash = strrpos($validatorClass, '\\');
            $shortClass = $lastSlash !== false
                ? substr($validatorClass, $lastSlash + 1)
                : $validatorClass;

            $attributes = $validator->getAttributeNames();
            $message = $validator->message;

            foreach ($attributes as $attribute) {
                if (!isset($messages[$attribute])) {
                    $messages[$attribute] = [];
                }
                $entry = [
                    'validator' => $shortClass,
                ];
                if ($message !== null) {
                    $entry['message'] = $message;
                }
                $messages[$attribute][] = $entry;
            }
        }

        return $messages;
    }

    /**
     * Build a constraint summary categorized by constraint type
     *
     * @param object $instance Model instance
     * @param string|null $scenario Optional scenario filter
     * @return array
     */
    private function getConstraints(object $instance, ?string $scenario): array
    {
        if ($scenario !== null) {
            $instance->setScenario($scenario);
        }

        $constraints = [];

        try {
            $validators = $instance->getActiveValidators();
        } catch (\Exception $e) {
            return $constraints;
        }

        foreach ($validators as $validator) {
            /** @var string $validatorClass */
            $validatorClass = get_class($validator);
            $attributes = $validator->getAttributeNames();

            foreach ($attributes as $attribute) {
                $constraint = $this->extractConstraint($validatorClass, $validator);

                $type = $constraint['type'];
                unset($constraint['type']);

                if (!isset($constraints[$type])) {
                    $constraints[$type] = [];
                }

                $entry = ['attribute' => $attribute];
                if (!empty($constraint)) {
                    $entry = array_merge($entry, $constraint);
                }

                $constraints[$type][] = $entry;
            }
        }

        return $constraints;
    }

    /**
     * Extract constraint details from a validator instance
     *
     * @param string $validatorClass Validator class name
     * @param object $validator Validator instance
     * @return array Constraint details
     */
    private function extractConstraint(string $validatorClass, object $validator): array
    {
        $classMap = [
            'yii\\validators\\RequiredValidator' => 'required',
            'yii\\validators\\UniqueValidator' => 'unique',
            'yii\\validators\\StringValidator' => 'string',
            'yii\\validators\\NumberValidator' => 'number',
            'yii\\validators\\EmailValidator' => 'email',
            'yii\\validators\\UrlValidator' => 'url',
            'yii\\validators\\BooleanValidator' => 'boolean',
            'yii\\validators\\DateValidator' => 'date',
            'yii\\validators\\RangeValidator' => 'in',
            'yii\\validators\\RegularExpressionValidator' => 'match',
            'yii\\validators\\ExistValidator' => 'exist',
            'yii\\validators\\CompareValidator' => 'compare',
            'yii\\validators\\FileValidator' => 'file',
            'yii\\validators\\ImageValidator' => 'image',
            'yii\\validators\\DefaultValueValidator' => 'default',
            'yii\\validators\\FilterValidator' => 'filter',
            'yii\\validators\\SafeValidator' => 'safe',
            'yii\\validators\\EachValidator' => 'each',
            'yii\\validators\\IpValidator' => 'ip',
        ];

        $type = $classMap[$validatorClass] ?? null;
        if ($type === null) {
            // Custom validator
            return ['type' => 'custom', 'class' => $validatorClass];
        }

        $constraint = ['type' => $type];

        // Extract type-specific parameters
        switch ($type) {
            case 'string':
                if (isset($validator->min)) {
                    $constraint['min'] = $validator->min;
                }
                if (isset($validator->max)) {
                    $constraint['max'] = $validator->max;
                }
                if (isset($validator->length)) {
                    $constraint['length'] = $validator->length;
                }
                break;

            case 'number':
                if (isset($validator->min)) {
                    $constraint['min'] = $validator->min;
                }
                if (isset($validator->max)) {
                    $constraint['max'] = $validator->max;
                }
                if (isset($validator->integerOnly) && $validator->integerOnly) {
                    $constraint['integer_only'] = true;
                }
                break;

            case 'in':
                if (isset($validator->range)) {
                    $constraint['range'] = $validator->range;
                }
                break;

            case 'match':
                if (isset($validator->pattern)) {
                    $constraint['pattern'] = $validator->pattern;
                }
                break;

            case 'exist':
                if (isset($validator->targetClass)) {
                    $constraint['target_class'] = $validator->targetClass;
                }
                if (isset($validator->targetAttribute)) {
                    $constraint['target_attribute'] = $validator->targetAttribute;
                }
                break;

            case 'compare':
                if (isset($validator->compareAttribute)) {
                    $constraint['compare_attribute'] = $validator->compareAttribute;
                }
                if (isset($validator->operator)) {
                    $constraint['operator'] = $validator->operator;
                }
                break;

            case 'unique':
                if (isset($validator->targetClass)) {
                    $constraint['target_class'] = $validator->targetClass;
                }
                if (isset($validator->targetAttribute)) {
                    $constraint['target_attribute'] = $validator->targetAttribute;
                }
                break;

            case 'date':
                if (isset($validator->format)) {
                    $constraint['format'] = $validator->format;
                }
                break;

            case 'file':
            case 'image':
                if (isset($validator->extensions)) {
                    $constraint['extensions'] = $validator->extensions;
                }
                if (isset($validator->maxSize)) {
                    $constraint['max_size'] = $validator->maxSize;
                }
                break;

            case 'default':
                if (isset($validator->value)) {
                    $constraint['value'] = $validator->value;
                }
                break;
        }

        return $constraint;
    }

    /**
     * Get safe attributes for each scenario or a specific scenario
     *
     * @param object $instance Model instance
     * @param string|null $scenario Optional specific scenario
     * @return array
     */
    private function getSafeAttributes(object $instance, ?string $scenario): array
    {
        if ($scenario !== null) {
            $instance->setScenario($scenario);
            return [$scenario => $instance->safeAttributes()];
        }

        $scenarios = $instance->scenarios();
        $result = [];

        foreach (array_keys($scenarios) as $scenarioName) {
            $instance->setScenario($scenarioName);
            $result[$scenarioName] = $instance->safeAttributes();
        }

        return $result;
    }
}

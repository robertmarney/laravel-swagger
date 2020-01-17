<?php

namespace Mtrajano\LaravelSwagger\Definitions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mtrajano\LaravelSwagger\DataObjects\Route;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

class DefinitionGenerator
{
    /**
     * @var Route
     */
    private $route;

    /**
     * @var Model
     */
    private $model;
    /**
     * @var array
     */
    private $definitions = [];

    public function __construct(Route $route)
    {
        $this->route = $route;
    }

    /**
     * @return array
     * @throws ReflectionException
     */
    public function generate()
    {
        if (!$this->canGenerate()) {
            return [];
        }

        $set = $this->setModelFromRouteAction();
        if ($set === false) {
            return [];
        }

        $this->generateFromCurrentModel();

        $this->generateFromRelations();

        return array_reverse($this->definitions);
    }

    private function getPropertyDefinition($column)
    {
        $definition = $this->mountColumnDefinition($column);

        $modelFake = $this->getModelFake();
        // TODO: Create tests to case when has no factory defined to model.
        if ($modelFake) {
            $definition['example'] = (string) $modelFake->{$column};
        }

        return $definition;
    }

    /**
     * Get model searching on route and define the found model on $this->model
     * property.
     *
     * @throws ReflectionException
     */
    private function setModelFromRouteAction()
    {
        $modelName = $this->getModelNameFromMethodDocs()
            ?? $this->getModelNameFromControllerDocs();

        if (!$modelName) {
            return false;
        }

        $modelInstance = new $modelName;

        if (!$modelInstance instanceof Model) {
            throw new RuntimeException(
                'The @model must be an instance of ['.Model::class.']'
            );
        }

        $this->model = $modelInstance;
    }

    /**
     * @return string|null
     * @throws ReflectionException
     */
    private function getModelNameFromMethodDocs(): ?string
    {
        $action = $this->route->action();

        $actionInstance = is_string($action) ? $this->getActionClassInstance($action) : null;

        $docBlock = $actionInstance ? ($actionInstance->getDocComment() ?: '') : '';

        return $this->getAnnotation('@model', $docBlock);
    }

    /**
     * @return string|null
     * @throws ReflectionException
     */
    private function getModelNameFromControllerDocs(): ?string
    {
        $action = $this->route->action();

        list($class, $method) = is_string($action)
            ? Str::parseCallback($action)
            : [null, null];

        if (!$class) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        $docBlock = $reflection->getDocComment();

        return $this->getAnnotation('@model', $docBlock);
    }

    private function getModelNameFromControllerName(): ?string
    {
        $action = $this->route->action();

        list($class, $method) = is_string($action)
            ? Str::parseCallback($action)
            : [null, null];

        if (!$class) {
            return null;
        }

        return Str::replaceLast('Controller', '', class_basename($class));
    }

    /**
     * @param string $action
     * @return ReflectionMethod
     * @throws ReflectionException
     */
    private function getActionClassInstance(string $action)
    {
        list($class, $method) = Str::parseCallback($action);

        return new ReflectionMethod($class, $method);
    }

    private function canGenerate()
    {
        return $this->allowsHttpMethodGenerate();
    }

    private function getHttpMethod()
    {
        $methods = $this->route->methods();
        return reset($methods);
    }

    private function allowsHttpMethodGenerate(): bool
    {
        $allowGenerateDefinitionMethods = ['get', 'post'];

        $methods = array_filter($this->route->methods(), function ($route) {
            return $route !== 'head';
        });

        foreach ($methods as $method) {
            if (!in_array($method, $allowGenerateDefinitionMethods)) {
                return false;
            }
        }
        return true;
    }

    private function getModelColumns()
    {
        return Schema::getColumnListing($this->model->getTable());
    }

    private function getDefinitionProperties()
    {
        $columns = $this->getModelColumns();

        $hiddenColumns = $this->model->getHidden();

        if (method_exists($this->model, 'getAppends')) {
            $appends = $this->model->getAppends();
            // TODO: Test condition
            if (!is_array($appends)) {
                throw new \RuntimeException('The return type of the "getAppends" method must be an array.');
            }

            $columns = array_merge($columns, $this->model->getAppends());
        }

        $properties = [];
        foreach ($columns as $column) {
            if (in_array($column, $hiddenColumns)) {
                continue;
            }

            $properties[$column] = $this->getPropertyDefinition($column);
        }

        return $properties;
    }

    private function getDefinitionName(): string
    {
        return class_basename($this->model);
    }

    private function getAnnotation(string $annotationName, string $docBlock)
    {
        preg_match_all('#@(.*?)\n#s', $docBlock, $annotations);

        foreach (reset($annotations) as $annotation) {
            if (Str::startsWith($annotation, $annotationName)) {
                return trim(Str::replaceFirst($annotationName, '', $annotation));
            }
        }

        return null;
    }

    /**
     * Create an instance of the model with fake data or return null.
     *
     * @return Model|null
     * @todo Check problem creating registries on production database:
     *       - Change the connection?
     *       - Abort?
     */
    private function getModelFake(): ?Model
    {
        try {
            return factory(get_class($this->model))->create();
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Identify all relationships for a given model
     *
     * @todo Create unit test fot this method.
     * @param Model $model Model
     * @param string $heritage A flag that indicates whether parent and/or child relationships should be included
     * @return  array
     * @throws ReflectionException
     */
    public function getAllRelations(Model $model = null, $heritage = 'all')
    {
        $modelName = get_class($model);
        $types = ['children' => 'Has', 'parents' => 'Belongs', 'all' => ''];
        $heritage = in_array($heritage, array_keys($types)) ? $heritage : 'all';

        $reflectionClass = new ReflectionClass($model);
        $traits = $reflectionClass->getTraits(); // Use this to omit trait methods
        $traitMethodNames = [];
        foreach ($traits as $name => $trait) {
            $traitMethods = $trait->getMethods();
            foreach ($traitMethods as $traitMethod) {
                $traitMethodNames[] = $traitMethod->getName();
            }
        }

        // Checking the return value actually requires executing the method.  So use this to avoid infinite recursion.
        $currentMethod = collect(explode('::', __METHOD__))->last();
        $filter = $types[$heritage];
        $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);  // The method must be public

        $methods = collect($methods)
            ->filter(function (ReflectionMethod $method) use ($modelName, $traitMethodNames, $currentMethod) {
                $methodName = $method->getName();
                if (!in_array($methodName, $traitMethodNames) // The method must not originate in a trait
                    && strpos($methodName, '__') !== 0        // It must not be a magic method
                    && $method->class === $modelName          // It must be in the self scope and not inherited
                    && !$method->isStatic()                   // It must be in the this scope and not static
                    && $methodName != $currentMethod          // It must not be an override of this one
                ) {
                    $parameters = (new ReflectionMethod($modelName, $methodName))->getParameters();
                    return collect($parameters)->filter(function (ReflectionParameter $parameter) {
                        return !$parameter->isOptional(); // The method must have no required parameters
                    })->isEmpty(); // If required parameters exist, this will be false and omit this method
                }
                return false;
            })
            ->map(function (ReflectionMethod $method) use ($model, $filter) {
                $methodName = $method->getName();
                /** @var Relation|mixed $relation */
                $relation = $model->$methodName();  //Must return a Relation child. This is why we only want to do this once
                if (is_subclass_of($relation, Relation::class)) {
                    $type = (new ReflectionClass($relation))->getShortName();  //If relation is of the desired heritage
                    if (!$filter || strpos($type, $filter) === 0) {
                        return [
                            'method' => $methodName,
                            'related_model' => $relation->getRelated(),
                            'relation' => get_class($relation),
                        ];
                    }
                }
                return null;
            })
            ->filter() // Remove elements reflecting methods that do not have the desired return type
            ->toArray();

        return $methods;
    }

    private function generateFromCurrentModel()
    {
        if ($this->definitionExists()) {
            return false;
        }

        $this->definitions += [
            $this->getDefinitionName() => [
                'type' => 'object',
                'properties' => $this->getDefinitionProperties(),
            ],
        ];
    }

    /**
     * @param Model $model
     * @return $this
     */
    private function setModel(Model $model)
    {
        $this->model = $model;
        return $this;
    }

    private function mountColumnDefinition(string $column)
    {
        $casts = $this->model->getCasts();
        $datesFields = $this->model->getDates();

        if (in_array($column, $datesFields)) {
            return [
                'type' => 'string',
                'format' => 'date-time',
            ];
        }

        $defaultDefinition = [
            'type' => 'string'
        ];

        $laravelTypesSwaggerTypesMapping = [
            'float' => [
                'type' => 'number',
                'format' => 'float',
            ],
            'int' => [
                'type' => 'integer',
            ],
            'boolean' => [
                'type' => 'boolean',
            ],
            'string' => $defaultDefinition,
        ];

        $columnType = $this->model->hasCast($column) ? $casts[$column] : 'string';

        return $laravelTypesSwaggerTypesMapping[$columnType] ?? $defaultDefinition;
    }

    /**
     * Set property data on specific definition.
     *
     * @param string $definition
     * @param string $property
     * @param $data
     */
    private function setPropertyOnDefinition(
        string $definition,
        string $property,
        $data
    ) {
        $this->definitions[$definition]['properties'][$property] = $data;
    }

    /**
     * Generate the definition from model Relations.
     *
     * @throws ReflectionException
     */
    private function generateFromRelations()
    {
        $relations = $this->getAllRelations($this->model);

        $baseModel = $this->model;
        foreach ($relations as $relation) {
            $this->setModel($baseModel);

            $relatedModel = $relation['related_model'];

            $relationPropertyData = [
                '$ref' => '#/definitions/'.class_basename($relatedModel)
            ];
            if (Str::contains($relation['relation'], 'Many')) {
                $relationPropertyData = [
                    'type' => 'array',
                    'items' => $relationPropertyData,
                ];
            }

            $this->setPropertyOnDefinition(
                $this->getDefinitionName(),
                $relation['method'],
                $relationPropertyData
            );

            $generated = $this
                ->setModel($relatedModel)
                ->generateFromCurrentModel();

            if ($generated === false) {
                continue;
            }

            $this->generateFromRelations();
        }
    }

    /**
     * Check if definition exists.
     *
     * @return bool
     */
    private function definitionExists()
    {
        return isset($this->definitions[$this->getDefinitionName()]);
    }
}
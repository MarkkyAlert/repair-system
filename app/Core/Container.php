<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function set(string $id, mixed $concrete): void
    {
        if (!$concrete instanceof Closure) {
            $this->instance($id, $concrete);
            return;
        }

        $this->bindings[$id] = [
            'factory' => $concrete,
            'singleton' => false,
        ];
    }

    public function singleton(string $id, mixed $concrete): void
    {
        if (!$concrete instanceof Closure) {
            $this->instance($id, $concrete);
            return;
        }

        $this->bindings[$id] = [
            'factory' => $concrete,
            'singleton' => true,
        ];
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->bindings)) {
            $binding = $this->bindings[$id];
            $resolved = $binding['factory']($this);

            if ($binding['singleton']) {
                $this->instances[$id] = $resolved;
            }

            return $resolved;
        }

        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new RuntimeException("Unable to resolve [$id].");
    }

    public function build(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Class [$class] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new RuntimeException("Unable to resolve parameter [{$parameter->getName()}] in class [$class].");
            }

            $dependencies[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}

<?php

declare (strict_types=1);
namespace Pay4PaymentDeps\DI;

use Pay4PaymentDeps\DI\Compiler\RequestedEntryHolder;
use Pay4PaymentDeps\DI\Definition\Definition;
use Pay4PaymentDeps\DI\Definition\Exception\InvalidDefinition;
use Pay4PaymentDeps\DI\Invoker\FactoryParameterResolver;
use Pay4PaymentDeps\Invoker\Exception\NotCallableException;
use Pay4PaymentDeps\Invoker\Exception\NotEnoughParametersException;
use Pay4PaymentDeps\Invoker\Invoker;
use Pay4PaymentDeps\Invoker\InvokerInterface;
use Pay4PaymentDeps\Invoker\ParameterResolver\AssociativeArrayResolver;
use Pay4PaymentDeps\Invoker\ParameterResolver\DefaultValueResolver;
use Pay4PaymentDeps\Invoker\ParameterResolver\NumericArrayResolver;
use Pay4PaymentDeps\Invoker\ParameterResolver\ResolverChain;
/**
 * Compiled version of the dependency injection container.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
abstract class CompiledContainer extends Container
{
    /**
     * This const is overridden in child classes (compiled containers).
     * @var array
     */
    protected const METHOD_MAPPING = [];
    private ?InvokerInterface $factoryInvoker = null;
    public function get(string $id): mixed
    {
        // Try to find the entry in the singleton map
        if (isset($this->resolvedEntries[$id]) || array_key_exists($id, $this->resolvedEntries)) {
            return $this->resolvedEntries[$id];
        }
        /** @psalm-suppress UndefinedConstant */
        $method = static::METHOD_MAPPING[$id] ?? null;
        // If it's a compiled entry, then there is a method in this class
        if ($method !== null) {
            // Check if we are already getting this entry -> circular dependency
            if (isset($this->entriesBeingResolved[$id])) {
                $idList = implode(' -> ', [...array_keys($this->entriesBeingResolved), $id]);
                throw new DependencyException("Circular dependency detected while trying to resolve entry '{$id}': Dependencies: " . $idList);
            }
            $this->entriesBeingResolved[$id] = \true;
            try {
                $value = $this->{$method}();
            } finally {
                unset($this->entriesBeingResolved[$id]);
            }
            // Store the entry to always return it without recomputing it
            $this->resolvedEntries[$id] = $value;
            return $value;
        }
        return parent::get($id);
    }
    public function has(string $id): bool
    {
        // The parent method is overridden to check in our array, it avoids resolving definitions
        /** @psalm-suppress UndefinedConstant */
        if (isset(static::METHOD_MAPPING[$id])) {
            return \true;
        }
        return parent::has($id);
    }
    protected function setDefinition(string $name, Definition $definition): void
    {
        // It needs to be forbidden because that would mean get() must go through the definitions
        // every time, which kinds of defeats the performance gains of the compiled container
        throw new \LogicException('You cannot set a definition at runtime on a compiled container. You can either put your definitions in a file, disable compilation or ->set() a raw value directly (PHP object, string, int, ...) instead of a PHP-DI definition.');
    }
    /**
     * Invoke the given callable.
     */
    protected function resolveFactory($callable, $entryName, array $extraParameters = []): mixed
    {
        // Initialize the factory resolver
        if (!$this->factoryInvoker) {
            $parameterResolver = new ResolverChain([new AssociativeArrayResolver(), new FactoryParameterResolver($this->delegateContainer), new NumericArrayResolver(), new DefaultValueResolver()]);
            $this->factoryInvoker = new Invoker($parameterResolver, $this->delegateContainer);
        }
        $parameters = [$this->delegateContainer, new RequestedEntryHolder($entryName)];
        $parameters = array_merge($parameters, $extraParameters);
        try {
            return $this->factoryInvoker->call($callable, $parameters);
        } catch (NotCallableException $e) {
            throw new InvalidDefinition("Entry \"{$entryName}\" cannot be resolved: factory " . $e->getMessage());
        } catch (NotEnoughParametersException $e) {
            throw new InvalidDefinition("Entry \"{$entryName}\" cannot be resolved: " . $e->getMessage());
        }
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * This replaces all ChildDefinition instances with their equivalent fully
 * merged Definition instance.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ResolveDefinitionTemplatesPass extends AbstractRecursivePass
{
    protected function processValue($value, $isRoot = false)
    {
        if (!$value instanceof Definition) {
            return parent::processValue($value, $isRoot);
        }
        if ($isRoot) {
            // yes, we are specifically fetching the definition from the
            // container to ensure we are not operating on stale data
            $value = $this->container->getDefinition($this->currentId);
        }
        if ($value instanceof ChildDefinition) {
            $value = $this->resolveDefinition($value);
            if ($isRoot) {
                $this->container->setDefinition($this->currentId, $value);
            }
        }

        return parent::processValue($value, $isRoot);
    }

    /**
     * Resolves the definition.
     *
     * @return Definition
     *
     * @throws RuntimeException When the definition is invalid
     */
    private function resolveDefinition(ChildDefinition $definition)
    {
        try {
            return $this->doResolveDefinition($definition);
        } catch (ExceptionInterface $e) {
            $r = new \ReflectionProperty($e, 'message');
            $r->setAccessible(true);
            $r->setValue($e, sprintf('Service "%s": %s', $this->currentId, $e->getMessage()));

            throw $e;
        }
    }

    private function doResolveDefinition(ChildDefinition $definition)
    {
        if (!$this->container->has($parent = $definition->getParent())) {
            throw new RuntimeException(sprintf('Parent definition "%s" does not exist.', $parent));
        }

        $parentDef = $this->container->findDefinition($parent);
        if ($parentDef instanceof ChildDefinition) {
            $id = $this->currentId;
            $this->currentId = $parent;
            $parentDef = $this->resolveDefinition($parentDef);
            $this->container->setDefinition($parent, $parentDef);
            $this->currentId = $id;
        }

        $this->container->log($this, sprintf('Resolving inheritance for "%s" (parent: %s).', $this->currentId, $parent));
        $def = new Definition();

        // merge in parent definition
        // purposely ignored attributes: abstract, tags
        $def->setClass($parentDef->getClass());
        $def->setArguments($parentDef->getArguments());
        $def->setMethodCalls($parentDef->getMethodCalls());
        $def->setOverriddenGetters($parentDef->getOverriddenGetters());
        $def->setProperties($parentDef->getProperties());
        if ($parentDef->getAutowiringTypes(false)) {
            $def->setAutowiringTypes($parentDef->getAutowiringTypes(false));
        }
        if ($parentDef->isDeprecated()) {
            $def->setDeprecated(true, $parentDef->getDeprecationMessage('%service_id%'));
        }
        $def->setFactory($parentDef->getFactory());
        $def->setConfigurator($parentDef->getConfigurator());
        $def->setFile($parentDef->getFile());
        $def->setPublic($parentDef->isPublic());
        $def->setLazy($parentDef->isLazy());
        $def->setAutowiredMethods($parentDef->getAutowiredMethods());

        // overwrite with values specified in the decorator
        $changes = $definition->getChanges();
        if (isset($changes['class'])) {
            $def->setClass($definition->getClass());
        }
        if (isset($changes['factory'])) {
            $def->setFactory($definition->getFactory());
        }
        if (isset($changes['configurator'])) {
            $def->setConfigurator($definition->getConfigurator());
        }
        if (isset($changes['file'])) {
            $def->setFile($definition->getFile());
        }
        if (isset($changes['public'])) {
            $def->setPublic($definition->isPublic());
        }
        if (isset($changes['lazy'])) {
            $def->setLazy($definition->isLazy());
        }
        if (isset($changes['deprecated'])) {
            $def->setDeprecated($definition->isDeprecated(), $definition->getDeprecationMessage('%service_id%'));
        }
        if (isset($changes['autowired_methods'])) {
            $def->setAutowiredMethods($definition->getAutowiredMethods());
        }
        if (isset($changes['decorated_service'])) {
            $decoratedService = $definition->getDecoratedService();
            if (null === $decoratedService) {
                $def->setDecoratedService($decoratedService);
            } else {
                $def->setDecoratedService($decoratedService[0], $decoratedService[1], $decoratedService[2]);
            }
        }

        // merge arguments
        foreach ($definition->getArguments() as $k => $v) {
            if (is_numeric($k)) {
                $def->addArgument($v);
                continue;
            }

            if (0 !== strpos($k, 'index_')) {
                throw new RuntimeException(sprintf('Invalid argument key "%s" found.', $k));
            }

            $index = (int) substr($k, strlen('index_'));
            $def->replaceArgument($index, $v);
        }

        // merge properties
        foreach ($definition->getProperties() as $k => $v) {
            $def->setProperty($k, $v);
        }

        // append method calls
        if ($calls = $definition->getMethodCalls()) {
            $def->setMethodCalls(array_merge($def->getMethodCalls(), $calls));
        }

        // merge overridden getters
        foreach ($definition->getOverriddenGetters() as $k => $v) {
            $def->setOverriddenGetter($k, $v);
        }

        // merge autowiring types
        foreach ($definition->getAutowiringTypes(false) as $autowiringType) {
            $def->addAutowiringType($autowiringType);
        }

        // these attributes are always taken from the child
        $def->setAbstract($definition->isAbstract());
        $def->setShared($definition->isShared());
        $def->setTags($definition->getTags());

        // append parent tags when inheriting is enabled
        if ($definition->getInheritTags()) {
            foreach ($parentDef->getTags() as $k => $v) {
                foreach ($v as $v) {
                    $def->addTag($k, $v);
                }
            }
        }

        return $def;
    }
}

<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds the classes discovery would register, for a module not installed.
 *
 * Discovery registers every alter, deriver, storage class and access
 * class it finds as an autowired service (ResourceCollectorPass), so the
 * class resolver hands each back from the container. A module that is not
 * installed has none in it: the config schema generator reads such a
 * module's resources before it is installed, and this builds each of its
 * classes the way the container would have, once per class. A class the
 * container holds, or one that builds itself from it, is the inner
 * resolver's.
 *
 * Each constructor argument is what its #[Autowire] attribute names, or
 * the service its type names (core's services are aliased by interface
 * for autowiring), or its default, or NULL where it may be NULL.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Storage\ConfigSchemaGenerator::including()
 */
final class AutowiringClassResolver implements ClassResolverInterface {

  /**
   * The instances built so far, by class.
   *
   * @var array<class-string, object>
   */
  protected array $built = [];

  /**
   * Constructs an AutowiringClassResolver.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $inner
   *   The class resolver, for what the container holds.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container the arguments are taken from.
   */
  public function __construct(
    protected readonly ClassResolverInterface $inner,
    protected readonly ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getInstanceFromDefinition($definition) {
    if ($this->container->has($definition) || !class_exists($definition) || is_subclass_of($definition, ContainerInjectionInterface::class)) {
      return $this->inner->getInstanceFromDefinition($definition);
    }
    return $this->built[$definition] ??= $this->autowired($definition);
  }

  /**
   * Builds a class with its constructor's arguments taken from the container.
   *
   * @param class-string $class
   *   The class.
   *
   * @return object
   *   The instance.
   *
   * @throws \LogicException
   *   When an argument names nothing the container holds and has no
   *   default.
   */
  protected function autowired(string $class): object {
    $constructor = (new \ReflectionClass($class))->getConstructor();
    $arguments = [];
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
      $arguments[] = $this->argument($class, $parameter);
    }
    return new $class(...$arguments);
  }

  /**
   * Gives one constructor argument.
   *
   * @param class-string $class
   *   The class being built, for the message.
   * @param \ReflectionParameter $parameter
   *   The parameter.
   *
   * @return mixed
   *   The argument.
   *
   * @throws \LogicException
   *   When nothing gives it.
   */
  protected function argument(string $class, \ReflectionParameter $parameter): mixed {
    foreach ($parameter->getAttributes(Autowire::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
      $value = $attribute->newInstance()->value;
      if ($value instanceof Reference) {
        return $this->container->get((string) $value);
      }
      if (is_string($value) && preg_match('/^%([^%]+)%$/', $value, $match) === 1) {
        return $this->container->getParameter($match[1]);
      }
      return $value;
    }
    $type = $parameter->getType();
    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->container->has($type->getName())) {
      return $this->container->get($type->getName());
    }
    if ($parameter->isDefaultValueAvailable()) {
      return $parameter->getDefaultValue();
    }
    if ($parameter->allowsNull()) {
      return NULL;
    }
    throw new \LogicException(sprintf('%s cannot be built while its module is not installed: nothing the container holds is its constructor\'s $%s. Give the argument an #[Autowire] attribute naming its service, or install the module first.', $class, $parameter->getName()));
  }

}

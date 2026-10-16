<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

use Drupal\Core\DependencyInjection\ClassResolverInterface;

/**
 * A method a derived surface is built through, with what it is bound to.
 *
 * A class-based surface is its class: its shape and its situations are
 * methods the build step finds by attribute. A derived surface has no
 * class of its own, so its deriver names each method instead, with the
 * arguments that say which derivative it is (an entity type and a
 * bundle, say). It is a class name, a method name and plain values, so a
 * derived definition survives the discovery cache and a cached form, as
 * a closure would not.
 *
 * What the caller supplies comes first and the bound arguments after:
 * a shape method is `defineInputs(ShapeInterface $inputs, string
 * $entity_type, string $bundle)`, a situation `add(array $arguments,
 * string $entity_type, string $bundle)`. A static method is called on the
 * class; an instance method on the class resolver's instance of it, so
 * a deriver's own methods may use the services it holds.
 *
 * @see \Drupal\data_surface\Surface\DerivedSurface
 */
final class SurfaceCallable {

  /**
   * Constructs a SurfaceCallable.
   *
   * @param class-string $class
   *   The class the method is on.
   * @param string $method
   *   The method.
   * @param list<mixed> $arguments
   *   The bound arguments, after what the caller supplies: scalars and
   *   arrays of them, which is what the discovery cache can hold.
   */
  public function __construct(
    public readonly string $class,
    public readonly string $method,
    public readonly array $arguments = [],
  ) {}

  /**
   * Calls the method.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $resolver
   *   What makes the instance of an instance method's class.
   * @param mixed ...$supplied
   *   What the caller supplies, before the bound arguments.
   *
   * @return mixed
   *   What the method returned.
   */
  public function call(ClassResolverInterface $resolver, mixed ...$supplied): mixed {
    $method = new \ReflectionMethod($this->class, $this->method);
    return $method->invokeArgs(
      $method->isStatic() ? NULL : $resolver->getInstanceFromDefinition($this->class),
      [...array_values($supplied), ...$this->arguments],
    );
  }

  /**
   * Names the method the way a message about it should.
   *
   * @return string
   *   Class::method().
   */
  public function describe(): string {
    return $this->class . '::' . $this->method . '()';
  }

}

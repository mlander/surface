<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Turns what a route or a tool was given into a situation's arguments.
 *
 * A situation's needs are its own signature. A route supplies them as
 * parameters of the same name, a tool as inputs of the same name, and
 * both arrive at the same method with the same values: a value for a
 * scalar parameter, and for a parameter typed with an entity class or
 * interface, the entity — handed over as it is when a route already
 * upcast it, loaded by its id when it arrives as one.
 *
 * Which entity type a parameter takes is read off its type the way the
 * Tool API reads a typed method's: the one entity type whose class
 * satisfies it.
 */
final class SituationArguments {

  /**
   * Entity type ids by parameter class, once worked out.
   *
   * @var array<string, string|null>
   */
  protected array $entityTypes = [];

  /**
   * Constructs a SituationArguments.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which names the entity type of a parameter
   *   and loads the entity an id names.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Names the entity type a parameter takes, if it takes an entity.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationParameter $parameter
   *   The parameter.
   *
   * @return string|null
   *   The entity type id, or NULL for a parameter that takes a value, or
   *   an object no single entity type is.
   */
  public function entityTypeOf(SituationParameter $parameter): ?string {
    if (!$parameter->takesObject()) {
      return NULL;
    }
    $class = (string) $parameter->type;
    if (!array_key_exists($class, $this->entityTypes)) {
      $matches = [];
      if (is_a($class, EntityInterface::class, TRUE)) {
        foreach ($this->entityTypeManager->getDefinitions() as $id => $entity_type) {
          if (is_a($entity_type->getClass(), $class, TRUE)) {
            $matches[] = $id;
          }
        }
      }
      $this->entityTypes[$class] = count($matches) === 1 ? $matches[0] : NULL;
    }
    return $this->entityTypes[$class];
  }

  /**
   * Resolves the arguments for one situation.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param array $arguments
   *   The arguments, by parameter name or by position. An entity
   *   parameter takes the entity, or its id.
   *
   * @return array
   *   The arguments, by parameter name, ready for the method.
   *
   * @throws \InvalidArgumentException
   *   When a parameter that has to be given is not, or an id names no
   *   entity.
   */
  public function resolve(SituationDefinition $situation, array $arguments): array {
    $resolved = [];
    foreach ($situation->parameters as $position => $parameter) {
      $given = array_key_exists($parameter->name, $arguments)
        ? [$arguments[$parameter->name]]
        : (array_key_exists($position, $arguments) ? [$arguments[$position]] : []);
      if ($given === []) {
        if (!$parameter->optional) {
          throw new \InvalidArgumentException(sprintf(
            'The "%s" situation, %s, needs %s, and it was not given.',
            $situation->id,
            $situation->describe(),
            $parameter->describe(),
          ));
        }
        continue;
      }
      $resolved[$parameter->name] = $this->resolveOne($situation, $parameter, $given[0]);
    }
    return $resolved;
  }

  /**
   * Reads a situation's arguments off a route match.
   *
   * Each parameter is the route parameter of the same name, upcast when
   * the route upcasts it; a parameter the route does not carry is left
   * out, which is refused by resolve() unless it is optional.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return array
   *   The arguments the route supplies, by parameter name.
   */
  public function fromRoute(SituationDefinition $situation, RouteMatchInterface $route_match): array {
    $arguments = [];
    foreach ($situation->parameters as $parameter) {
      $value = $route_match->getParameter($parameter->name) ?? $route_match->getRawParameter($parameter->name);
      if ($value !== NULL) {
        $arguments[$parameter->name] = $value;
      }
    }
    return $arguments;
  }

  /**
   * Resolves one argument.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   The situation, for messages.
   * @param \Drupal\data_surface\SurfaceBuild\SituationParameter $parameter
   *   The parameter.
   * @param mixed $value
   *   What was given.
   *
   * @return mixed
   *   The argument.
   *
   * @throws \InvalidArgumentException
   *   When an id names no entity.
   */
  protected function resolveOne(SituationDefinition $situation, SituationParameter $parameter, mixed $value): mixed {
    $entity_type_id = $this->entityTypeOf($parameter);
    if ($entity_type_id === NULL || !(is_string($value) || is_int($value))) {
      return $value;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($value);
    if ($entity === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" situation needs a %s as $%s, and there is none with the id "%s".',
        $situation->id,
        $entity_type_id,
        $parameter->name,
        $value,
      ));
    }
    return $entity;
  }

}

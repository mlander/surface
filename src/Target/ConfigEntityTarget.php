<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values on one config entity.
 *
 * The target for every config entity form: a node type, an image style,
 * a view mode, a date format. Surface keys map to entity properties, or
 * to a method the entity already offers for the cases where a property
 * needs a real setter rather than a plain assignment.
 *
 * Preparing works on a clone, and the clone is the artifact. That is the
 * whole of the dry run contract for this target and it is not a detail:
 * the entity a caller hands over is usually the one its form, its route
 * and the rest of the request are holding, so shaping values onto it
 * would make a rehearsal and a schema refusal both visible to everybody,
 * and a later unrelated save would then persist a submission that was
 * never accepted. Committing saves the artifact, so nothing the caller
 * holds is touched until the write happens, and after the write this
 * target reads the entity it actually saved.
 *
 * The unsaved clone is the same preview artifact core's own
 * EntityForm::buildEntity() produces for a node preview, and it is as
 * far as a dry run can go: config entity storage writes through the
 * container's config factory, so unlike a config object an entity cannot
 * be pointed at another bin for one call.
 *
 * The surface's third party mount lands where core already keeps it. A
 * surface built with DataSurfaceBuilder::setThirdPartyDefinition() grows
 * a 'third_party_settings' map holding one map per provider, which is
 * the exact shape setThirdPartySetting() stores, so the values a module
 * added at build time are written through the same interface core has
 * always offered and nothing has to know they came from a surface.
 *
 * A provider that asks for its settings in one shape and stores them in
 * another says so at build time with setThirdPartyShape(), and this
 * target applies that shape to that provider's namespace and nothing
 * else: toStorage() before the settings are set on the entity, so the
 * schema check below judges what will actually be stored, and
 * fromStorage() when they are read back. The owner who built this target
 * never has to know the contributor exists.
 *
 * @see docs/targets.md
 */
final class ConfigEntityTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The surface key holding the third party settings mount.
   */
  protected const THIRD_PARTY = 'third_party_settings';

  /**
   * The map key naming an entity method rather than a property.
   */
  public const METHOD = 'method';

  /**
   * Surface key => entity property name, or a method to call.
   *
   * @var array<string, string|array{method: string}>
   */
  protected readonly array $properties;

  /**
   * The entity the surface describes, replaced by what commit() saved.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityInterface
   */
  protected ConfigEntityInterface $entity;

  /**
   * Constructs a ConfigEntityTarget.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity the surface describes, saved or not. It is read from
   *   and cloned, never written to.
   * @param array $map
   *   Surface key => entity property name, or ['method' => 'setX'] to
   *   call a setter the entity already offers with the accepted value.
   *   A plain list entry names a key whose property is its own name, and
   *   a surface key the map leaves out is set under its own name too.
   *   Only mapped keys are read back by load(), so list every key the
   *   surface owns.
   *
   *   The method form is a string rather than a callable on purpose: a
   *   target has to survive being serialized with a form, and it is the
   *   same spelling core's config actions use, so a key that can be set
   *   through this target is a key that can be set by a recipe.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed configuration manager, which validates the built entity
   *   against the config schema.
   * @param bool $validateSchema
   *   TRUE to validate the built entity against its config schema and
   *   refuse the values when the schema complains. Only violations under
   *   a path this target writes are reported; a pre-existing violation
   *   elsewhere in the entity is somebody else's to fix and does not
   *   block an unrelated write.
   *
   * @see \Drupal\Core\Config\Action\Attribute\ActionMethod
   */
  public function __construct(
    ConfigEntityInterface $entity,
    array $map,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly bool $validateSchema = TRUE,
  ) {
    $this->entity = $entity;
    $properties = [];
    foreach ($map as $key => $property) {
      if (is_int($key)) {
        $properties[$property] = $property;
        continue;
      }
      if (is_array($property)) {
        $method = $property[self::METHOD] ?? NULL;
        if (!is_string($method) || $method === '') {
          throw new \InvalidArgumentException(sprintf('The "%s" entry must name an entity property or an entity method.', $key));
        }
        $properties[$key] = [self::METHOD => $method];
        continue;
      }
      if (!is_string($property)) {
        throw new \InvalidArgumentException(sprintf('The "%s" entry must name an entity property or an entity method.', $key));
      }
      $properties[$key] = $property;
    }
    $this->properties = $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = [];
    foreach ($this->properties as $key => $property) {
      if ($key === static::THIRD_PARTY) {
        $values[$key] = $this->loadThirdParty($surface);
        continue;
      }
      $values[$key] = $this->entity->get(is_string($property) ? $property : $key);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    // The caller's entity is read, never written: everything below
    // happens to a copy, which is what a dry run hands back and what a
    // commit saves.
    $entity = clone $this->entity;
    $properties = $this->properties;
    foreach ($surface->getDefinitions()->names() as $key) {
      $properties[$key] ??= $key;
    }
    $paths = [];
    foreach ($properties as $key => $property) {
      $paths[is_string($property) ? $property : $key] = $key;
      if (!array_key_exists($key, $values)) {
        continue;
      }
      if ($key === static::THIRD_PARTY) {
        $this->applyThirdParty($surface, $entity, $values[$key]);
        continue;
      }
      if (is_string($property)) {
        $entity->set($property, $values[$key]);
        continue;
      }
      $method = $property[self::METHOD];
      if (!method_exists($entity, $method)) {
        throw new \InvalidArgumentException(sprintf(
          'The "%s" surface key is stored through %s::%s(), which does not exist.',
          $key,
          $entity->getEntityTypeId(),
          $method,
        ));
      }
      $entity->{$method}($values[$key]);
    }
    $dependencies = $entity->calculateDependencies()->getDependencies();
    if ($this->validateSchema) {
      SchemaViolations::check(
        $this->typedConfig,
        $entity->getConfigDependencyName(),
        $entity->toArray(),
        $paths,
      );
    }
    return new PreparedValues($values, $entity, $dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    $entity = $prepared->artifact;
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('The prepared values did not come from a config entity target.');
    }
    $entity->save();
    // What was saved is now what this target describes, so a load() after
    // a commit reads the values that were written rather than the ones
    // the caller started from.
    $this->entity = $entity;
  }

  /**
   * Reads every provider's third party settings off the entity.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, which carries each provider's storage shape.
   *
   * @return array<string, array>
   *   Provider => setting key => value, in the shape the surface
   *   describes; empty when the entity carries none.
   */
  protected function loadThirdParty(DataSurfaceInterface $surface): array {
    $settings = [];
    foreach ($this->entity->getThirdPartyProviders() as $provider) {
      $stored = $this->entity->getThirdPartySettings($provider);
      $shape = $surface->getThirdPartyShape($provider);
      $settings[$provider] = $shape === NULL ? $stored : $shape->fromStorage($stored);
    }
    return $settings;
  }

  /**
   * Writes the third party mount onto an entity, provider by provider.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, which carries each provider's storage shape.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The entity being shaped, which is the clone, never the original.
   * @param mixed $value
   *   The mount's value: provider => setting key => value. Anything
   *   else means the surface had nothing mounted, and nothing is
   *   written.
   */
  protected function applyThirdParty(DataSurfaceInterface $surface, ConfigEntityInterface $entity, mixed $value): void {
    if (!is_array($value)) {
      return;
    }
    foreach ($value as $provider => $settings) {
      if (!is_array($settings)) {
        continue;
      }
      $shape = $surface->getThirdPartyShape((string) $provider);
      if ($shape !== NULL) {
        $settings = $shape->toStorage($settings);
      }
      foreach ($settings as $key => $setting) {
        $entity->setThirdPartySetting((string) $provider, (string) $key, $setting);
      }
    }
  }

}

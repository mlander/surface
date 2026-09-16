<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values as per bundle overrides of base fields.
 *
 * Several things a site builder thinks of as bundle settings are not
 * stored on the bundle at all. A content type's "published by default"
 * is the default value of the node entity's status base field, narrowed
 * to that one bundle, and the label of the title field on the content
 * form is that base field's label, narrowed the same way. Core keeps
 * both in base field override config entities, one per field and bundle,
 * and the only place that translation is written down today is the
 * middle of a form's save method.
 *
 * This target is that translation, given a name. A surface key names a
 * base field and which of the two properties it means, and
 * the target reads the current value from the bundle's field
 * definitions, shapes the overrides that would have to change, and
 * writes only those. Comparing before writing is deliberate and matches
 * what core's own NodeTypeForm does: an override that merely restates
 * the base field's value is config nobody asked for.
 *
 * Only base fields have per bundle overrides. A configurable field is a
 * field config entity in its own right, and its label and default value
 * are edited there rather than overridden, so a map naming one is a
 * mistake that would otherwise rewrite that field instance under the
 * caller's feet. It is refused by name instead.
 *
 * Clearing, and why NULL is not "say nothing". A key the caller did not
 * send has said nothing and is skipped. A key sent as NULL has said
 * something: it asks for the bundle to stop overriding that field and go
 * back to what the base field says, which is exactly deleting the
 * override entity. Skipping NULL, as this target used to, made an
 * override impossible to undo through a surface at all.
 *
 * Ordering rule, and the reason this target usually has a sibling. A
 * bundle's overrides belong to a bundle, so on an add operation the
 * bundle does not exist while the values are being prepared. That is
 * legal here: preparing builds unsaved override entities from the entity
 * type's base field definitions, which is what a brand new bundle starts
 * from anyway. Committing is the part that needs the bundle to be real,
 * because an override declares a config dependency on it. So inside a
 * CompositeTarget this target must come AFTER the target that creates
 * the bundle, and it resolves its field definitions lazily and drops
 * that resolution on commit, so the next read sees the bundle that now
 * exists.
 *
 * @see \Drupal\data_surface\Target\CompositeTarget
 * @see docs/targets.md
 */
final class BaseFieldOverrideTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The map property naming a field's per bundle label.
   */
  public const LABEL = 'label';

  /**
   * The map property naming a field's per bundle default value.
   */
  public const DEFAULT_VALUE = 'default_value';

  /**
   * The artifact key holding the overrides to write.
   */
  public const SAVE = 'save';

  /**
   * The artifact key holding the overrides to remove.
   */
  public const DELETE = 'delete';

  /**
   * The bundle's field definitions, resolved on first use.
   *
   * @var array<string, \Drupal\Core\Field\FieldDefinitionInterface>|null
   */
  protected ?array $fields = NULL;

  /**
   * Constructs a BaseFieldOverrideTarget.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   The entity field manager, which answers what a bundle's fields
   *   currently say and whose cache the commit clears.
   * @param string $entityTypeId
   *   The entity type whose base fields are overridden, such as 'node'.
   * @param string $bundle
   *   The bundle the overrides belong to. It need not exist yet.
   * @param array<string, array{field: string, property?: string}> $map
   *   Surface key => the field it overrides and which property of it:
   *   'label' for the field's per bundle label, 'default_value' for the
   *   value new content starts from. The property defaults to the label.
   */
  public function __construct(
    protected readonly EntityFieldManagerInterface $fieldManager,
    protected readonly string $entityTypeId,
    protected readonly string $bundle,
    protected readonly array $map,
  ) {
    foreach ($map as $key => $target) {
      $property = $target['property'] ?? self::LABEL;
      if ($target['field'] === '' || !in_array($property, [self::LABEL, self::DEFAULT_VALUE], TRUE)) {
        throw new \InvalidArgumentException(sprintf('The "%s" override must name a field and either a label or a default value.', $key));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = [];
    foreach ($this->map as $key => $target) {
      $field = $this->field((string) $key, $target['field']);
      $values[$key] = $field === NULL ? NULL : $this->read($field, $target['property'] ?? self::LABEL);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $overrides = [];
    $deletions = [];
    foreach ($this->map as $key => $target) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $field = $this->field((string) $key, $target['field']) ?? throw new \LogicException(sprintf(
        'The %s entity type has no %s field to override for the %s bundle.',
        $this->entityTypeId,
        $target['field'],
        $this->bundle,
      ));
      if ($values[$key] === NULL) {
        // Stop overriding this field: the stored override goes, and the
        // bundle reads what the base field says again. Nothing to remove
        // means nothing to do.
        $stored = $field->getConfig($this->bundle);
        if (!$stored->isNew()) {
          $deletions[$target['field']] = $stored;
        }
        continue;
      }
      $property = $target['property'] ?? self::LABEL;
      $value = $this->shape($field, $property, $values[$key]);
      // The same compare before write core's own bundle forms do: an
      // override restating the base field's value is config nobody
      // asked for.
      if ($this->read($field, $property) === $value) {
        continue;
      }
      // Cloned because an existing override IS the definition the field
      // manager handed over, and preparing may not change what the rest
      // of the request reads. The clone is the artifact; commit saves
      // it and drops the stale resolution.
      $override = $overrides[$target['field']] ??= clone $field->getConfig($this->bundle);
      if ($property === self::LABEL) {
        $override->setLabel($value);
        continue;
      }
      $override->setDefaultValue($value);
    }
    $conflicts = array_keys(array_intersect_key($deletions, $overrides));
    if ($conflicts !== []) {
      throw new \InvalidArgumentException(sprintf(
        'The %s %s of the %s bundle cannot be cleared and set in one submission: clearing removes the whole override.',
        implode(', ', $conflicts),
        count($conflicts) === 1 ? 'override' : 'overrides',
        $this->bundle,
      ));
    }
    // The overrides depend on the bundle, not the bundle on them, so
    // there is nothing here for a host's calculateDependencies() to
    // collect: each override declares its own when it is saved.
    return new PreparedValues($values, [
      self::SAVE => array_values($overrides),
      self::DELETE => array_values($deletions),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    if (!is_array($prepared->artifact) || !isset($prepared->artifact[self::SAVE], $prepared->artifact[self::DELETE])) {
      throw new \InvalidArgumentException('The prepared values did not come from a base field override target.');
    }
    foreach ($prepared->artifact[self::SAVE] as $override) {
      $this->assertOverride($override)->save();
    }
    foreach ($prepared->artifact[self::DELETE] as $override) {
      $this->assertOverride($override)->delete();
    }
    // The bundle's definitions have moved, and on an add operation the
    // bundle itself only came into being a moment ago, so whatever this
    // target resolved earlier describes a world that is gone.
    $this->fieldManager->clearCachedFieldDefinitions();
    $this->fields = NULL;
  }

  /**
   * Refuses anything in an artifact that is not an override entity.
   *
   * @param mixed $override
   *   The artifact entry.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface
   *   The same entry, typed.
   */
  protected function assertOverride(mixed $override): FieldConfigInterface {
    if (!$override instanceof FieldConfigInterface) {
      throw new \InvalidArgumentException('The prepared values did not come from a base field override target.');
    }
    return $override;
  }

  /**
   * Resolves one mapped field, refusing anything without an override.
   *
   * @param string $key
   *   The surface key, for the refusal message.
   * @param string $field_name
   *   The field the key overrides.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The field definition, or NULL when the bundle has no such field.
   *
   * @throws \InvalidArgumentException
   *   When the field is a configurable field, which has no per bundle
   *   override to write: its label and default value are edited on the
   *   field itself.
   */
  protected function field(string $key, string $field_name): ?FieldDefinitionInterface {
    $field = $this->fields()[$field_name] ?? NULL;
    if ($field === NULL) {
      return NULL;
    }
    // A base field answers with a BaseFieldOverride, which is the thing
    // this target writes. A configurable field answers with itself.
    if ($field instanceof FieldConfigInterface && !$field instanceof BaseFieldOverride) {
      throw new \InvalidArgumentException(sprintf(
        'The "%s" override names the %s field of the %s bundle of %s, which is a configurable field rather than a base field: only base fields have per bundle overrides, so there is nothing for this target to write.',
        $key,
        $field_name,
        $this->bundle,
        $this->entityTypeId,
      ));
    }
    return $field;
  }

  /**
   * Reads one of the two overridden properties off a field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field definition, already narrowed to the bundle when the
   *   bundle exists.
   * @param string $property
   *   Either the label or the default value.
   *
   * @return mixed
   *   The current value, in the shape the surface uses.
   */
  protected function read(FieldDefinitionInterface $field, string $property): mixed {
    if ($property === self::LABEL) {
      return (string) $field->getLabel();
    }
    $literal = $field->getDefaultValueLiteral()[0]['value'] ?? NULL;
    return $field->getType() === 'boolean' ? (bool) $literal : $literal;
  }

  /**
   * Shapes a surface value the way the field stores it.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field definition.
   * @param string $property
   *   Either the label or the default value.
   * @param mixed $value
   *   The accepted surface value.
   *
   * @return mixed
   *   The value in storage shape, comparable with read().
   */
  protected function shape(FieldDefinitionInterface $field, string $property, mixed $value): mixed {
    if ($property === self::LABEL) {
      return (string) $value;
    }
    return $field->getType() === 'boolean' ? (bool) $value : $value;
  }

  /**
   * Gets the field definitions the overrides are measured against.
   *
   * A bundle that does not exist yet has nothing of its own to say, and
   * the entity field manager answers for it with the entity type's base
   * fields, which is exactly what a new bundle inherits. Resolution is
   * lazy and dropped on commit so an add operation reads the bundle it
   * has just created rather than the emptiness it started from.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The field definitions, keyed by field name.
   */
  protected function fields(): array {
    if ($this->fields === NULL) {
      $definitions = $this->bundle === ''
        ? []
        : $this->fieldManager->getFieldDefinitions($this->entityTypeId, $this->bundle);
      $this->fields = $definitions === []
        ? $this->fieldManager->getBaseFieldDefinitions($this->entityTypeId)
        : $definitions;
    }
    return $this->fields;
  }

}

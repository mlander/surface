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
 * Ordering rule, and the reason this target is usually written beside
 * another. A bundle's overrides belong to a bundle, so on an add
 * operation the bundle does not exist while the values are being
 * prepared. That is legal here: preparing rehearses each override on an
 * unsaved copy built from the entity type's base field definitions,
 * which is what a brand new bundle starts from anyway, and plans it as
 * the override's exported array, which is what config storage would be
 * handed. Committing is the part that needs the bundle to be real,
 * because an override declares a config dependency on it. So it is
 * written AFTER the bundle, and it resolves the bundle's field
 * definitions afresh to write the plan onto, so it writes onto the
 * bundle that now exists.
 *
 * A surface target with a bundle of its own to write delegates here
 * through plan() and write(), the three verbs' bodies without the
 * pipeline's wrapping: NodeTypeTarget writes a content type's title
 * label and workflow defaults this way.
 *
 * @see \Drupal\data_surface_demo_node_type\Target\NodeTypeTarget
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
   * The plan key holding the overrides to write, as exported arrays.
   */
  public const SAVE = 'save';

  /**
   * The plan key holding the base field names whose override goes.
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
    return $this->values();
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    return new PreparedValues($values, $this->plan($values));
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    if (!is_array($prepared->artifact)) {
      throw new \InvalidArgumentException('The prepared values did not come from a base field override target.');
    }
    $this->write($prepared->artifact);
  }

  /**
   * Reads what each mapped key says for the bundle now.
   *
   * @return array<string, mixed>
   *   The current value of every key in the map, in the surface's shape;
   *   NULL for a field the bundle does not have.
   */
  public function values(): array {
    $values = [];
    foreach ($this->map as $key => $target) {
      $field = $this->field((string) $key, $target['field']);
      $values[$key] = $field === NULL ? NULL : $this->read($field, $target['property'] ?? self::LABEL);
    }
    return $values;
  }

  /**
   * Plans the overrides some values would move, writing nothing.
   *
   * @param array $values
   *   Accepted values; a mapped key that is absent says nothing, and one
   *   that is NULL clears its field's override.
   *
   * @return array{save: array<string, array>, delete: list<string>}
   *   Under SAVE, each override that would be written, as its exported
   *   array, keyed by base field name; under DELETE, the base field
   *   names whose stored override would be removed.
   *
   * @throws \LogicException
   *   When a mapped field is not one the entity type has.
   * @throws \InvalidArgumentException
   *   When one field is both cleared and set.
   */
  public function plan(array $values): array {
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
        if (!$field->getConfig($this->bundle)->isNew()) {
          $deletions[$target['field']] = $target['field'];
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
      // manager handed over, and planning may not change what the rest
      // of the request reads.
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
    return [
      self::SAVE => array_map(static fn (FieldConfigInterface $override): array => $override->toArray(), $overrides),
      self::DELETE => array_values($deletions),
    ];
  }

  /**
   * Writes a plan onto the bundle as it now exists.
   *
   * The bundle's field definitions are resolved afresh, because on an
   * add operation the bundle only came into being a moment ago, and each
   * planned override's label and default value are set on the override
   * the bundle answers with.
   *
   * @param array $plan
   *   What plan() returned.
   *
   * @throws \InvalidArgumentException
   *   When the plan did not come from plan().
   */
  public function write(array $plan): void {
    if (!is_array($plan[self::SAVE] ?? NULL) || !is_array($plan[self::DELETE] ?? NULL)) {
      throw new \InvalidArgumentException('The prepared values did not come from a base field override target.');
    }
    $this->fieldManager->clearCachedFieldDefinitions();
    $this->fields = NULL;
    foreach ($plan[self::SAVE] as $name => $record) {
      $override = $this->assertPlanned($this->fields()[$name] ?? NULL)->getConfig($this->bundle);
      if (array_key_exists('label', $record)) {
        $override->setLabel((string) $record['label']);
      }
      if (array_key_exists('default_value', $record)) {
        $override->set('default_value', $record['default_value']);
      }
      $override->save();
    }
    foreach ($plan[self::DELETE] as $name) {
      $override = $this->assertPlanned($this->fields()[$name] ?? NULL)->getConfig($this->bundle);
      if (!$override->isNew()) {
        $override->delete();
      }
    }
    // The bundle's definitions have moved, so whatever was resolved to
    // write them describes a world that is gone.
    $this->fieldManager->clearCachedFieldDefinitions();
    $this->fields = NULL;
  }

  /**
   * Refuses a planned field the bundle does not have.
   *
   * @param mixed $field
   *   The bundle's definition of a planned field.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The same definition, typed.
   */
  protected function assertPlanned(mixed $field): FieldDefinitionInterface {
    if (!$field instanceof FieldDefinitionInterface) {
      throw new \InvalidArgumentException('The prepared values did not come from a base field override target.');
    }
    return $field;
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

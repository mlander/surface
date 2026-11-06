<?php

declare(strict_types=1);

namespace Drupal\surface\Reader;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceRegistry;

/**
 * Reads a typed data definition straight into a blueprint.
 *
 * The definition's own structure, read through the same rules as a config
 * schema (LeafRules):
 *
 * - a complex definition's properties are the frame's keys; a property
 *   that is complex itself is addMap(), or attach() of a derived
 *   structure where its data type has a name of its own to rebuild it by
 *   (`field_item:text`), `typed_data:<type>`;
 * - a list is addList() of its item, or attachList() of a derived
 *   structure for an item that is complex and named;
 * - a reference is the reference key the module spells: the id with
 *   SurfaceValidReference (References::definition()), for a reference
 *   property, and for the id property a computed reference stands
 *   beside (a field item's `target_id` beside its `entity`);
 * - a value is the blueprint's type its class is, its constraints (and
 *   those its complex parent lays on it, ComplexData, in its definition
 *   or when it is made empty, as a field item lays its Length) carried
 *   verbatim, its label and description;
 * - required where the definition is (isRequired()); in a list, where the
 *   list is required, and then its item's main property too, since an
 *   empty list holds no item; read only locked; computed left out;
 *   `default_value` a setting's default, and a field's literal default.
 *
 * A field definition is a list of field items, read as its item.
 *
 * Decision: see docs/decisions.md#a-class-may-declare-its-keys-as-properties.
 */
final class TypedDataReader implements DeclarationReaderInterface {

  /**
   * The id a structure read from a typed data type starts with.
   */
  public const PREFIX = 'typed_data:';

  /**
   * Constructs a TypedDataReader.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which rebuilds a named definition.
   * @param \Drupal\surface\Reference\References $references
   *   What a reference key is.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   The registry each derived structure is registered with.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly References $references,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param mixed $source
   *   A complex definition, whose properties are read as keys, or a list
   *   of them (a field definition), read as its item.
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   */
  public function read(mixed $source, BlueprintInterface $inputs): Reading {
    if (!$source instanceof DataDefinitionInterface) {
      throw new \InvalidArgumentException('TypedDataReader reads a typed data definition.');
    }
    $reading = new Reading();
    $list = NULL;
    $frame = $source;
    if ($source instanceof ListDataDefinitionInterface) {
      $list = $source;
      $frame = $source->getItemDefinition();
    }
    if (!$frame instanceof ComplexDataDefinitionInterface) {
      $reading->leave('', sprintf('Of the %s type, one value: a structure is a group of keys.', $frame->getDataType()));
      return $reading;
    }
    $defaults = $source instanceof FieldDefinitionInterface ? ($source->getDefaultValueLiteral()[0] ?? []) : [];
    foreach ($frame->getConstraints() as $name => $options) {
      if ($name !== 'ComplexData') {
        $reading->leave('', sprintf('%s: said of the whole item, and no verb lays a constraint on a frame.', $name));
      }
    }
    if ($list !== NULL) {
      foreach ($list->getConstraints() as $name => $options) {
        $reading->leave('', sprintf('%s: said of the list of items, which a structure for one item does not hold.', $name));
      }
    }
    $this->properties($inputs, $frame, $list, is_array($defaults) ? $defaults : [], $reading);
    return $reading;
  }

  /**
   * Fills a derived structure's blueprint from a named data type.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param string $type
   *   The data type, which the manager rebuilds.
   */
  public function fill(BlueprintInterface $inputs, string $type): void {
    $this->read($this->typedDataManager->createDataDefinition($type), $inputs);
  }

  /**
   * Registers the structure a named data type is.
   *
   * @param string $type
   *   The data type: `field_item:text`.
   *
   * @return string
   *   The name it is attached and listed by.
   */
  public function structure(string $type): string {
    return $this->registry->defineRead(self::PREFIX . $type, 'surface', new ResourceCallable(self::class, 'fill', [$type]), hash('xxh128', $type));
  }

  /**
   * Declares a complex definition's properties.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The frame's blueprint.
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $frame
   *   The complex definition.
   * @param \Drupal\Core\TypedData\ListDataDefinitionInterface|null $list
   *   The list it is the item of, or NULL.
   * @param array $defaults
   *   A literal default per property.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $at
   *   The frame's path from where reading began.
   */
  protected function properties(BlueprintInterface $inputs, ComplexDataDefinitionInterface $frame, ?ListDataDefinitionInterface $list, array $defaults, Reading $reading, string $at = ''): void {
    $properties = $frame->getPropertyDefinitions();
    $main = $frame->getMainPropertyName();
    // What the item lays on each property: ComplexData's properties.
    $laid = [];
    foreach ($frame->getConstraints() as $name => $options) {
      if ($name === 'ComplexData') {
        foreach ((array) $options as $property => $constraints) {
          $laid[(string) $property] = (array) $constraints;
        }
      }
    }
    // What the data lays on its properties when it is made, empty: a
    // field item's Length from its max_length. Made, never given a value.
    try {
      foreach ($this->typedDataManager->create($frame)->getConstraints() as $constraint) {
        if ($constraint instanceof ComplexDataConstraint) {
          foreach ($constraint->properties as $property => $constraints) {
            $laid[(string) $property] = (array) $constraints + ($laid[(string) $property] ?? []);
          }
        }
        elseif ($at === '' && !array_key_exists($short = (string) preg_replace('/Constraint$/', '', (new \ReflectionClass($constraint))->getShortName()), $frame->getConstraints())) {
          $reading->leave('', sprintf('%s: laid on the whole item when it is made, and no verb lays a constraint on a frame.', $short));
        }
      }
    }
    catch (\Throwable) {
      // A definition that cannot be made alone lays nothing more.
    }
    // The id each computed reference stands beside, by the id's name.
    $targets = [];
    foreach ($properties as $property) {
      if ($property instanceof DataReferenceDefinitionInterface && $property->isComputed()) {
        foreach ($properties as $name => $id) {
          if ($id instanceof DataReferenceTargetDefinition) {
            $targets[(string) $name] = $property;
          }
        }
      }
    }
    foreach ($properties as $key => $property) {
      $key = (string) $key;
      $here = $at === '' ? $key : $at . '.' . $key;
      if ($property->isComputed()) {
        $reading->leave($here, $property instanceof DataReferenceDefinitionInterface && in_array($property, $targets, TRUE)
          ? 'Computed: the entity its id refers to, which the id key states.'
          : 'Computed: no value of its own is stored.');
        continue;
      }
      $required = $list === NULL ? $property->isRequired() : $list->isRequired() && ($property->isRequired() || $key === $main);
      if ($property instanceof ListDataDefinitionInterface) {
        $item = $property->getItemDefinition();
        if ($item instanceof ComplexDataDefinitionInterface) {
          if (!$this->isNamed($item)) {
            $reading->leave($here, 'A list of groups of keys with no data type of its own to derive a structure by: attachList() takes a structure, so the key is declared open, holding any value.');
            $declared = $this->open($inputs, $key, $property);
          }
          else {
            $declared = $inputs->attachList($key, $this->structure($item->getDataType()));
            $reading->structure($here . '.*', self::PREFIX . $item->getDataType());
          }
        }
        else {
          // Noted by value() where it reads no type.
          $value = $this->value($item, $laid[$key] ?? [], $reading, $here . '.*', NULL);
          $declared = $value === NULL ? $this->open($inputs, $key, $property) : $inputs->addList($key, $value, $property->getLabel() ?? $key);
        }
      }
      elseif ($property instanceof ComplexDataDefinitionInterface && !$property instanceof DataReferenceDefinitionInterface) {
        if ($this->isNamed($property)) {
          $declared = $inputs->attach($key, $this->structure($property->getDataType()));
          $reading->structure($here, self::PREFIX . $property->getDataType());
        }
        else {
          $inner = new Reading();
          $declared = $inputs->addMap($key, $property->getLabel() ?? $key, function (BlueprintInterface $map) use ($property, $inner): void {
            $this->properties($map, $property, NULL, [], $inner);
          });
          $reading->nest($here, $inner);
        }
      }
      else {
        $declared = $this->value($property, $laid[$key] ?? [], $reading, $here, $targets[$key] ?? NULL, $key);
        if ($declared === NULL) {
          // Noted by value().
          $declared = $this->open($inputs, $key, $property);
        }
        else {
          $inputs->addDefinition($key, $declared, $defaults[$key] ?? $property->getSetting('default_value'));
        }
      }
      $this->word($declared, $property);
      if ($required) {
        $declared->setRequired(TRUE);
      }
      if ($property->isReadOnly()) {
        $this->lock($inputs, $key, $reading, $here);
      }
    }
  }

  /**
   * Reads one value through the shared rules, or a reference key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property
   *   The value.
   * @param array $laid
   *   The constraints its parent lays on it, by name.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   Its path.
   * @param \Drupal\Core\TypedData\DataReferenceDefinitionInterface|null $target
   *   The computed reference it is the id of, if any.
   * @param string $key
   *   Its key, for a reference key's messages.
   *
   * @return \Drupal\Core\TypedData\DataDefinition|null
   *   The value, or NULL when it is of no type a key holds.
   */
  protected function value(DataDefinitionInterface $property, array $laid, Reading $reading, string $here, ?DataReferenceDefinitionInterface $target, string $key = ''): ?DataDefinition {
    $reference = $property instanceof DataReferenceDefinitionInterface ? $property : $target;
    if ($reference !== NULL) {
      $entity = $reference->getTargetDefinition();
      if (!$entity instanceof EntityDataDefinitionInterface || $entity->getEntityTypeId() === NULL) {
        $reading->leave($here, sprintf('A reference to %s, which is no entity type: a reference key names one, so the key is declared open, holding any value.', $entity->getDataType()));
        return NULL;
      }
      $definition = $this->references->definition($key === '' ? $here : $key, (string) $entity->getEntityTypeId(), array_values((array) ($entity->getBundles() ?? [])));
      $reading->leaf($here, 'reference to ' . $entity->getEntityTypeId());
    }
    else {
      $type = $this->typeOf($property->getDataType());
      if ($type === NULL) {
        $reading->leave($here, sprintf('Of the %s type, which is none of the types a key holds, so the key is declared open, holding any value.', $property->getDataType()));
        return NULL;
      }
      if ($type !== $property->getDataType()) {
        $reading->leaf($here, $property->getDataType());
      }
      $definition = LeafRules::value($type, NULL);
    }
    $constraints = $property->getConstraints() + $laid;
    // The data type's own, which the type states.
    unset($constraints['PrimitiveType'], $constraints['ComplexData']);
    LeafRules::carry($definition, $constraints, $reading, $here);
    return $definition;
  }

  /**
   * Names the blueprint's type a data type is.
   *
   * @param string $type
   *   The data type id.
   *
   * @return string|null
   *   The type, or NULL.
   */
  protected function typeOf(string $type): ?string {
    if (LeafTypes::has($type)) {
      return $type;
    }
    $definition = $this->typedDataManager->getDefinition($type, FALSE);
    return is_array($definition) ? LeafRules::type((string) ($definition['class'] ?? '')) : NULL;
  }

  /**
   * Says whether a complex definition is rebuilt by its data type alone.
   *
   * @param \Drupal\Core\TypedData\ComplexDataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE when its data type names it and is not a plain map, whose
   *   properties are its own.
   */
  protected function isNamed(ComplexDataDefinitionInterface $definition): bool {
    $type = $definition->getDataType();
    if ($type === 'map' || !$this->typedDataManager->hasDefinition($type)) {
      return FALSE;
    }
    $rebuilt = $this->typedDataManager->createDataDefinition($type);
    return $rebuilt instanceof ComplexDataDefinitionInterface && array_keys($rebuilt->getPropertyDefinitions()) === array_keys($definition->getPropertyDefinitions());
  }

  /**
   * Sets the label and description a property gives.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $declared
   *   What a verb declared.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property
   *   The property read.
   */
  protected function word(DataDefinitionInterface $declared, DataDefinitionInterface $property): void {
    if (!$declared instanceof DataDefinition) {
      return;
    }
    if ($property->getLabel() !== NULL && (string) $property->getLabel() !== '') {
      $declared->setLabel(LeafRules::label($property->getLabel()));
    }
    if ($property->getDescription() !== NULL && (string) $property->getDescription() !== '') {
      $declared->setDescription(LeafRules::label($property->getDescription()));
    }
  }

  /**
   * Declares a property the reader reads no shape for, open.
   *
   * A property the definition declares is always declared: a derived
   * structure is closed, so one left out would refuse the stored value
   * that holds it (LeafRules::open()).
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The frame's blueprint.
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property
   *   The property, for its label.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   */
  protected function open(BlueprintInterface $inputs, string $key, DataDefinitionInterface $property): DataDefinition {
    $definition = LeafRules::open($property->getLabel());
    $inputs->addDefinition($key, $definition);
    return $definition;
  }

  /**
   * Locks a read-only key, where the blueprint can.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint.
   * @param string $key
   *   The key.
   * @param \Drupal\surface\Reader\Reading $reading
   *   The notes.
   * @param string $here
   *   Its path.
   */
  protected function lock(BlueprintInterface $inputs, string $key, Reading $reading, string $here): void {
    if ($inputs instanceof ResourceBlueprint) {
      $inputs->lockRead($key);
      return;
    }
    $reading->leave($here, 'Read only: no verb an owner calls locks a key, so it is locked only in a build (ResourceBlueprint::lockRead()).');
  }

}

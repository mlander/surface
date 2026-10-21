<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Drupal\data_surface\Contract\ContractEmitter;

/**
 * What a resource can carry of a bundle's fields, read from typed data.
 *
 * One place says it, for the three that need it: the field item deriver
 * (each covered field's item structure), the content entity deriver
 * (which fields a bundle's resource attaches, and which it leaves out and
 * why), and the content entity storage (which properties it reads and
 * writes).
 *
 * A field item's structure is derived from the item's property definitions:
 * one key per property the field type stores and a caller sets, typed as
 * the casting table can carry it (docs/semantics.md), labeled from the
 * field (one property) or the property (several), required on the main
 * property when the field is, defaulting to the field's literal default.
 * What narrows is carried where a definition has the same word for it:
 * the Length, Range, Regex and Choice constraints the field type and the
 * property declare, a list field's allowed values, a reference's
 * targets when they can be listed, a text field's formats. What has no
 * equivalent in a definition stays with the entity and is checked when the
 * storage validates it, at prepare.
 *
 * Decision: see docs/decisions.md#derived-then-layered.
 */
final class FieldItemDerivation {

  use StringTranslationTrait;

  /**
   * The field types derived, each with its keys and their types.
   *
   * A reference's target id is typed when the field is read: an integer
   * for an entity type whose ids are integers, a string otherwise.
   */
  public const COVERED = [
    'string' => ['value' => 'string'],
    'string_long' => ['value' => 'string'],
    'telephone' => ['value' => 'string'],
    'email' => ['value' => 'email'],
    'text' => ['value' => 'string', 'format' => 'string'],
    'text_long' => ['value' => 'string', 'format' => 'string'],
    'text_with_summary' => ['value' => 'string', 'summary' => 'string', 'format' => 'string'],
    'boolean' => ['value' => 'boolean'],
    'integer' => ['value' => 'integer'],
    'decimal' => ['value' => 'float'],
    'float' => ['value' => 'float'],
    'list_string' => ['value' => 'string'],
    'list_integer' => ['value' => 'integer'],
    // An allowed value is an option, keyed by its value, and a PHP key
    // cannot be a float: a list_float's value travels as its spelling.
    'list_float' => ['value' => 'string'],
    'datetime' => ['value' => 'string'],
    'timestamp' => ['value' => 'integer'],
    'created' => ['value' => 'integer'],
    'changed' => ['value' => 'integer'],
    'link' => ['uri' => 'uri', 'title' => 'string'],
    'entity_reference' => ['target_id' => NULL],
  ];

  /**
   * The constraints carried onto a key, and their options.
   *
   * By plugin id: the ones a definition says the same way, which the
   * narrowing check, the served contract and the form read.
   */
  public const CARRIED = [
    'Length' => ['min', 'max', 'minMessage', 'maxMessage'],
    // A field type says each bound in a Range of its own, with its own
    // message; merged into one, only Range's own wording fits both.
    'Range' => ['min', 'max'],
    'Regex' => ['pattern', 'message'],
    'Choice' => ['choices'],
  ];

  /**
   * The pattern a datetime value is written in, by its datetime_type.
   */
  protected const DATE_PATTERNS = [
    'date' => '/^\d{4}-\d{2}-\d{2}$/D',
    'datetime' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D',
  ];

  /**
   * Constructs a FieldItemDerivation.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which makes the definitions and the item
   *   a field type's own constraints are asked of.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly TypedDataManagerInterface $typedDataManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Lists the bundles a content entity resource is derived for.
   *
   * A translatable label is kept as the object core hands over, so a
   * sentence that names it renders it in the language that reads it.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>>
   *   Bundle labels, keyed by entity type id and bundle, for every
   *   content entity type with a bundle key whose bundle has a stored
   *   default form display.
   */
  public function bundles(): array {
    $bundles = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(ContentEntityInterface::class) || !$entity_type->hasKey('bundle')) {
        continue;
      }
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        if ($this->formDisplay($entity_type_id, (string) $bundle) !== NULL) {
          $label = $info['label'] ?? $bundle;
          $bundles[$entity_type_id][(string) $bundle] = $label instanceof TranslatableMarkup ? $label : (string) $label;
        }
      }
    }
    return $bundles;
  }

  /**
   * Loads a bundle's stored default form display.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\Core\Entity\Display\EntityFormDisplayInterface|null
   *   The display, or NULL when none is stored.
   */
  public function formDisplay(string $entity_type_id, string $bundle): ?EntityFormDisplayInterface {
    $display = $this->entityTypeManager->getStorage('entity_form_display')->load($entity_type_id . '.' . $bundle . '.default');
    return $display instanceof EntityFormDisplayInterface ? $display : NULL;
  }

  /**
   * Sorts a bundle's form display into fields carried and left out.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param array<string, class-string> $overridden
   *   The override class of each field type someone wrote one for: a
   *   field of such a type is carried whatever COVERED says.
   *
   * @return array{fields: array<string, \Drupal\Core\Field\FieldDefinitionInterface>, skipped: array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>}
   *   The fields carried, in the display's order, and why each other
   *   component of the display is not.
   */
  public function fields(string $entity_type_id, string $bundle, array $overridden = []): array {
    $display = $this->formDisplay($entity_type_id, $bundle);
    $components = $display?->getComponents() ?? [];
    $weight = static fn (string $name): array => [$components[$name]['weight'] ?? 0, $name];
    uksort($components, static fn (string $a, string $b): int => $weight($a) <=> $weight($b));
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $fields = [];
    $skipped = [];
    foreach (array_keys($components) as $name) {
      $field = $definitions[$name] ?? NULL;
      $reason = $field === NULL ? $this->t('not a field: a component another module adds to the form') : $this->leftOut($field, $overridden);
      if ($reason !== NULL) {
        $skipped[$name] = $reason;
        continue;
      }
      assert($field instanceof FieldDefinitionInterface);
      $fields[$name] = $field;
    }
    return ['fields' => $fields, 'skipped' => $skipped];
  }

  /**
   * Says why a field is left out, if it is.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param array<string, class-string> $overridden
   *   The override class of each field type someone wrote one for.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason, or NULL for a field that is carried.
   */
  protected function leftOut(FieldDefinitionInterface $field, array $overridden): ?TranslatableMarkup {
    $cardinality = $field->getFieldStorageDefinition()->getCardinality();
    return match (TRUE) {
      $field->isComputed() => $this->t('computed: its value is worked out, not stored'),
      $field->isReadOnly() => $this->t('read-only'),
      $field->isInternal() => $this->t('internal'),
      $cardinality !== 1 => $this->t('takes @count values; a field of more than one value is a collection, which is not built yet', [
        '@count' => $cardinality < 0 ? $this->t('any number of') : $cardinality,
      ]),
      !isset(self::COVERED[$field->getType()]) && !isset($overridden[$field->getType()]) => $this->t('the @type field type is not covered yet', ['@type' => $field->getType()]),
      default => NULL,
    };
  }

  /**
   * Gets the keys a covered field's item structure has, and their types.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field, of a covered type.
   *
   * @return array<string, string>
   *   The Data Surface type of each key, keyed by item property name.
   */
  public function properties(FieldDefinitionInterface $field): array {
    $properties = self::COVERED[$field->getType()] ?? [];
    if (array_key_exists('target_id', $properties)) {
      $properties['target_id'] = $field->getFieldStorageDefinition()->getPropertyDefinition('target_id')?->getDataType() === 'integer' ? 'integer' : 'string';
    }
    // A link whose title is switched off has none to ask for.
    if ($field->getType() === 'link' && (int) $field->getSetting('title') === 0) {
      unset($properties['title']);
    }
    return $properties;
  }

  /**
   * Derives a covered field's item structure: one definition per key.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field, of a covered type.
   * @param string $bundle
   *   The bundle it is read for: a base field's definition names none.
   *
   * @return array<string, array{definition: \Drupal\Core\TypedData\DataDefinition, default: mixed}>
   *   Each key's definition and its default (NULL for none), keyed by
   *   item property name, in the order the field type declares them.
   */
  public function describe(FieldDefinitionInterface $field, string $bundle): array {
    $properties = $this->properties($field);
    $storage = $field->getFieldStorageDefinition();
    $main = $storage->getMainPropertyName();
    [$carried] = $this->itemConstraints($field);
    $literal = $field->getDefaultValueLiteral()[0] ?? [];
    $described = [];
    foreach ($properties as $name => $type) {
      $definition = $this->typedDataManager->createDataDefinition($type);
      assert($definition instanceof DataDefinition);
      if (count($properties) === 1) {
        $definition->setLabel($field->getLabel());
        if ((string) $field->getDescription() !== '') {
          $definition->setDescription($field->getDescription());
        }
      }
      else {
        $definition->setLabel($storage->getPropertyDefinition($name)?->getLabel() ?? $name);
      }
      if ($name === $main && $field->isRequired()) {
        $definition->setRequired(TRUE);
      }
      $constraints = $carried[$name] ?? [];
      foreach (array_intersect_key($storage->getPropertyDefinition($name)?->getConstraints() ?? [], self::CARRIED) as $constraint => $options) {
        $constraints[$constraint] = static::options($options, self::CARRIED[$constraint]) + ($constraints[$constraint] ?? []);
      }
      foreach ($constraints as $constraint => $options) {
        if ($options !== []) {
          $definition->addConstraint($constraint, $options);
        }
      }
      $this->narrow($field, $bundle, $name, $definition);
      $described[$name] = ['definition' => $definition, 'default' => $this->defaultOf($field, $name, $type, $literal)];
    }
    return $described;
  }

  /**
   * Says what a field's item structure could not carry.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field, of a covered type.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Notes, keyed by what they are about.
   */
  public function notes(FieldDefinitionInterface $field): array {
    $notes = [];
    if ((string) $field->getDefaultValueCallback() !== '') {
      $notes['default_value'] = $this->t('Its default comes from @callback, which needs the entity being made, so it is not stated here; the entity applies it when it is created.', ['@callback' => $field->getDefaultValueCallback()]);
    }
    $literal = $field->getDefaultValueLiteral()[0] ?? [];
    $worked_out = array_diff_key($literal, $this->properties($field), ['target_uuid' => TRUE]);
    if ($worked_out !== []) {
      $notes['default_value'] = $this->t('Its default (@keys) is worked out by the field type when an entity is created, so it is not stated here.', ['@keys' => implode(', ', array_keys($worked_out))]);
    }
    [, $left] = $this->itemConstraints($field);
    if ($left !== []) {
      $notes['constraints'] = $this->t('Checked when the entity is validated, which has no equivalent in the structure: @constraints.', ['@constraints' => implode(', ', $left)]);
    }
    if ($field->getType() === 'decimal') {
      $notes['decimal'] = $this->t('A decimal travels as a float, so a value with more digits than a float holds loses them; its precision and scale are checked when the entity is validated.');
    }
    if ($field->getType() === 'entity_reference') {
      $notes['target_id'] = $this->t("Its targets are its selection handler's, for the account asking: listed when there are at most @cap, otherwise any id is taken and the handler checks it.", [
        '@cap' => ContractEmitter::ENUMERATION_CAP,
      ]);
    }
    return $notes;
  }

  /**
   * Gets the cache tags of what decides which bundles and fields there are.
   *
   * What the registry's index is kept under: a bundle created or deleted,
   * any form display saved, any field's definitions changed. Reading the
   * list again keeps every derivative whose own tags did not fire.
   *
   * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
   *
   * @return string[]
   *   The bundle list, the form display list and the field definitions.
   */
  public function listCacheTags(): array {
    return ['entity_field_info', 'entity_bundles', 'config:entity_form_display_list'];
  }

  /**
   * Gets the cache tags of what a bundle's resource is read from.
   *
   * Its form display, its bundle's configuration entity (its label), and
   * each field the display shows, carried or left out: no other bundle's,
   * and none of the site-wide tags, so a field saved on another bundle
   * leaves it be.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return string[]
   *   The tags.
   */
  public function bundleCacheTags(string $entity_type_id, string $bundle): array {
    $tags = ['config:core.entity_form_display.' . $entity_type_id . '.' . $bundle . '.default'];
    $bundle_type = $this->entityTypeManager->getDefinition($entity_type_id)->getBundleEntityType();
    if ($bundle_type !== NULL) {
      $entity = $this->entityTypeManager->getStorage($bundle_type)->load($bundle);
      if ($entity !== NULL) {
        $tags = Cache::mergeTags($tags, $entity->getCacheTags());
      }
    }
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    foreach (array_keys($this->formDisplay($entity_type_id, $bundle)?->getComponents() ?? []) as $name) {
      if (isset($definitions[$name])) {
        $tags = Cache::mergeTags($tags, $this->fieldCacheTags($definitions[$name], $entity_type_id, $bundle));
      }
    }
    return $tags;
  }

  /**
   * Gets the cache tags of what one field's item structure is read from.
   *
   * The field's configuration and its storage's, or, for a base field, the
   * override a site may save for it on this bundle, named even before one
   * exists so that saving the first one clears it.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle it is read for.
   *
   * @return string[]
   *   The tags.
   */
  public function fieldCacheTags(FieldDefinitionInterface $field, string $entity_type_id, string $bundle): array {
    // A configured field and its storage name their configuration; a base
    // field names nothing, being code.
    $tags = Cache::mergeTags($field->getCacheTags(), $field->getFieldStorageDefinition()->getCacheTags());
    if (!$field instanceof FieldConfigInterface) {
      $tags[] = 'config:core.base_field_override.' . $entity_type_id . '.' . $bundle . '.' . $field->getName();
    }
    return $tags;
  }

  /**
   * Gets the Data Surface type of an entity type's id.
   *
   * @param string $entity_type_id
   *   The entity type id.
   *
   * @return string
   *   'integer' or 'string'.
   */
  public function idType(string $entity_type_id): string {
    $key = (string) $this->entityTypeManager->getDefinition($entity_type_id)->getKey('id');
    $definitions = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
    return isset($definitions[$key]) && $definitions[$key]->getType() === 'integer' ? 'integer' : 'string';
  }

  /**
   * Adds what a field type's settings narrow a key to.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param string $bundle
   *   The bundle it is read for.
   * @param string $name
   *   The property name.
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The key's definition, narrowed in place.
   */
  protected function narrow(FieldDefinitionInterface $field, string $bundle, string $name, DataDefinition $definition): void {
    $type = $field->getType();
    if (str_starts_with($type, 'list_') && function_exists('options_allowed_values')) {
      $allowed = options_allowed_values($field->getFieldStorageDefinition());
      $choices = array_map(static fn (int|string $value): int|string => match ($type) {
        'list_integer' => (int) $value,
        default => (string) $value,
      }, array_keys($allowed));
      $definition->addConstraint('LabeledChoice', [
        'choices' => $choices,
        'labels' => array_combine(array_map('strval', $choices), array_map('strval', $allowed)),
      ]);
    }
    if ($type === 'datetime') {
      $date_type = $field->getFieldStorageDefinition()->getSetting('datetime_type') === 'date' ? 'date' : 'datetime';
      $definition->addConstraint('Regex', [
        'pattern' => self::DATE_PATTERNS[$date_type],
        'message' => $date_type === 'date'
          ? $this->t('Write the date as YYYY-MM-DD.')
          : $this->t('Write the date and time as YYYY-MM-DDTHH:MM:SS, in UTC.'),
      ]);
      $definition->setDescription($date_type === 'date' ? $this->t('A date, YYYY-MM-DD.') : $this->t('A date and time in UTC, YYYY-MM-DDTHH:MM:SS.'));
    }
    if ($name === 'format') {
      $formats = $this->formats($field);
      $definition->addConstraint('LabeledChoice', ['choices' => array_keys($formats), 'labels' => $formats]);
    }
    if ($name === 'target_id') {
      // Which targets is the selection handler's answer, for whoever
      // asks: a constraint points at it, and its resolver lists them.
      $definition->addConstraint('DataSurfaceReferenceable', [
        'entityTypeId' => $field->getTargetEntityTypeId(),
        'bundle' => $bundle,
        'fieldName' => $field->getName(),
      ]);
    }
  }

  /**
   * Lists the text formats a text field allows, whoever is asking.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The text field.
   *
   * @return array<string, string>
   *   Each enabled format's label, keyed by id, narrowed to the field's
   *   allowed formats when it names any. Which of them an account may
   *   use is a runtime refiner's answer.
   */
  public function formats(FieldDefinitionInterface $field): array {
    $allowed = array_filter((array) ($field->getSetting('allowed_formats') ?? []));
    $formats = [];
    foreach ($this->entityTypeManager->getStorage('filter_format')->loadByProperties(['status' => TRUE]) as $id => $format) {
      if ($allowed === [] || in_array($id, $allowed, TRUE)) {
        $formats[(string) $id] = (string) $format->label();
      }
    }
    uksort($formats, static fn (string $a, string $b): int => strcmp($a, $b));
    return $formats;
  }

  /**
   * Reads one key's default off the field's literal default.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param string $name
   *   The property name.
   * @param string $type
   *   The key's Data Surface type.
   * @param array $literal
   *   The field's first literal default item.
   *
   * @return mixed
   *   The default, cast to the key's type, or NULL for none.
   */
  protected function defaultOf(FieldDefinitionInterface $field, string $name, string $type, array $literal): mixed {
    $value = $literal[$name] ?? NULL;
    // A reference's default is stored by uuid; core turns it into an id in
    // processDefaultValue(), which takes an entity. Hole: see
    // docs/entities.md#holes.
    if ($value === NULL && $name === 'target_id' && isset($literal['target_uuid'])) {
      $target_type = (string) $field->getSetting('target_type');
      $uuid_key = (string) $this->entityTypeManager->getDefinition($target_type)->getKey('uuid');
      $found = $this->entityTypeManager->getStorage($target_type)->loadByProperties([$uuid_key => (string) $literal['target_uuid']]);
      $value = $found === [] ? NULL : reset($found)->id();
    }
    if ($value === NULL) {
      return NULL;
    }
    return match ($type) {
      'boolean' => (bool) $value,
      'integer' => (int) $value,
      'float' => (float) $value,
      default => (string) $value,
    };
  }

  /**
   * Reads the options a carried constraint is given again with.
   *
   * @param mixed $constraint
   *   The constraint's options, or the constraint itself, as a field
   *   type's ComplexData constraint holds it once made.
   * @param string[] $names
   *   The options carried.
   *
   * @return array
   *   The options set, by name.
   */
  protected static function options(mixed $constraint, array $names): array {
    $options = is_object($constraint) ? get_object_vars($constraint) : (is_array($constraint) ? $constraint : []);
    return array_filter(array_intersect_key($options, array_flip($names)), static fn (mixed $value): bool => $value !== NULL);
  }

  /**
   * Reads the constraints a field type puts on its own item.
   *
   * Asked of an item made from the field's item definition, with no
   * entity around it, which is all a field type's getConstraints() needs
   * for the ones a definition can say; one that needs more is left to the
   * entity's validation.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return array{0: array<string, array<string, array>>, 1: string[]}
   *   The carried constraints on each property, by plugin id, with the
   *   options carried (two of one kind merged), and the names of the
   *   item's other constraints, which only the entity checks.
   */
  protected function itemConstraints(FieldDefinitionInterface $field): array {
    try {
      $constraints = $this->typedDataManager->create($field->getItemDefinition())->getConstraints();
    }
    catch (\Throwable) {
      return [[], []];
    }
    $carried = [];
    $left = [];
    foreach ($constraints as $constraint) {
      if ($constraint instanceof ComplexDataConstraint) {
        foreach ($constraint->properties as $property => $list) {
          foreach (array_intersect_key((array) $list, self::CARRIED) as $name => $options) {
            $carried[$property][$name] = static::options($options, self::CARRIED[$name]) + ($carried[$property][$name] ?? []);
          }
        }
        continue;
      }
      $short = substr((string) strrchr(get_class($constraint), '\\'), 1);
      $left[] = preg_replace('/Constraint$/', '', $short);
    }
    $left = array_values(array_diff(array_unique($left), ['AllowedValues']));
    return [$carried, $left];
  }

}

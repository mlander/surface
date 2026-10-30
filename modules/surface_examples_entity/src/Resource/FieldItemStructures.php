<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Resource;

use Drupal\surface_examples_entity\Structure\FieldItemStructureInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Resource\Attribute\DerivesResources;
use Drupal\surface\Resource\DerivedOperation;
use Drupal\surface\Resource\DerivedResource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\KnowsDerivativeListTagsInterface;
use Drupal\surface\Resource\ResourceDeriverInterface;
use Drupal\surface_examples_entity\Access\FieldItemAccess;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\surface_examples_entity\Refinement\TextFormatRefiner;
use Drupal\surface_examples_entity\Structure\Attribute\FieldItemStructure;

/**
 * One structure per field a bundle's form shows.
 *
 * `field.item:<entity type>.<bundle>.<field>`.
 *
 * Derived from the field item's typed data (FieldItemDerivation), or,
 * for a field type someone wrote one for, from the class marked
 * #[FieldItemStructure(type:)], whose static #[RefinesInput] methods refine
 * it. Either way the field's own facts stay derived: its label, and its
 * two operations, `create` and `update`, which the content entity resource
 * invokes it through by name. Each knows the entity type, the bundle
 * and the field, and `update` the entity's id, which is what its access
 * class asks field access with: open, locked, or omitted.
 *
 * Such a structure has no storage: its parent, the content entity
 * resource, stores it under the field's name. So it is never a tool on
 * its own.
 *
 * @see \Drupal\surface_examples_entity\Resource\ContentEntityResources
 */
#[DerivesResources('field.item', overriddenBy: FieldItemStructure::class)]
final class FieldItemStructures implements ResourceDeriverInterface, KnowsDerivativeListTagsInterface {

  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   What a structure can carry of a field.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly FieldItemDerivation $derivation,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function derivatives(array $overrides): array {
    $by_type = static::overridesByType($overrides);
    $derivatives = [];
    foreach ($this->derivation->bundles() as $entity_type_id => $bundles) {
      foreach (array_keys($bundles) as $bundle) {
        foreach ($this->derivation->fields($entity_type_id, $bundle, $by_type)['fields'] as $name => $field) {
          $override = $by_type[$field->getType()] ?? NULL;
          $bound = [$entity_type_id, $bundle, $name];
          $refiners = $override === NULL ? [] : [$override];
          if ($override === NULL && isset($this->derivation->properties($field)['format'])) {
            $refiners[] = TextFormatRefiner::class;
          }
          $notes = $override === NULL ? $this->derivation->notes($field) : ['override' => $this->t('Written by hand in @class.', ['@class' => $override])];
          $derivatives[$entity_type_id . '.' . $bundle . '.' . $name] = new DerivedResource(
            inputs: new ResourceCallable(self::class, 'defineInputs', [...$bound, $override]),
            operations: [
              new DerivedOperation(
                'create',
                $this->t('Enter @field on a new item', ['@field' => $field->getLabel()]),
                new ResourceCallable(self::class, 'create', $bound),
                creates: TRUE,
                description: $this->t('Enters the @field field of a new @bundle item.', [
                  '@field' => $field->getLabel(),
                  '@bundle' => $bundle,
                ]),
              ),
              new DerivedOperation(
                'update',
                $this->t('Change @field', ['@field' => $field->getLabel()]),
                new ResourceCallable(self::class, 'update', $bound),
                ['id' => 'string'],
                description: $this->t('Changes the @field field of a @bundle item, named by its id.', [
                  '@field' => $field->getLabel(),
                  '@bundle' => $bundle,
                ]),
              ),
            ],
            refiners: $refiners,
            access: FieldItemAccess::class,
            label: $field->getLabel(),
            description: $this->t('The @field field (@type) of @bundle, as one item.', [
              '@field' => $field->getLabel(),
              '@type' => $field->getType(),
              '@bundle' => $bundle,
            ]),
            notes: $notes,
            cacheTags: $this->derivation->fieldCacheTags($field, $entity_type_id, $bundle),
          );
        }
      }
    }
    return $derivatives;
  }

  /**
   * {@inheritdoc}
   */
  public function listCacheTags(): array {
    return $this->derivation->listCacheTags();
  }

  /**
   * Fills one field item's blueprint.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field.
   * @param class-string<\Drupal\surface_examples_entity\Structure\FieldItemStructureInterface>|null $override
   *   The class written by hand for the field's type, or NULL.
   */
  public function defineInputs(BlueprintInterface $inputs, string $entity_type_id, string $bundle, string $field_name, ?string $override = NULL): void {
    $field = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name] ?? NULL;
    if ($field === NULL) {
      return;
    }
    // What it is read from, and nothing site-wide: another field saved
    // leaves what was built from this one be.
    // Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
    $inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags($this->derivation->fieldCacheTags($field, $entity_type_id, $bundle)));

    if ($override !== NULL) {
      $override::defineItem($inputs, $field);
      return;
    }
    foreach ($this->derivation->describe($field, $bundle) as $name => $described) {
      $inputs->addDefinition($name, $described['definition'], $described['default']);
    }
  }

  /**
   * The item on an entity being created: a throwaway one, as core's form.
   *
   * @param array $arguments
   *   None.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context that creates, knowing which field.
   */
  public static function create(array $arguments, string $entity_type_id, string $bundle, string $field_name): OperationContext {
    return new OperationContext('create', creates: TRUE, known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
    ]);
  }

  /**
   * The item on an entity that exists.
   *
   * @param array{id: int|string} $arguments
   *   The entity's id, which the parent's update operation knows.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context knowing which field of which entity.
   */
  public static function update(array $arguments, string $entity_type_id, string $bundle, string $field_name): OperationContext {
    return new OperationContext('update', known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
      'id' => $arguments['id'],
    ]);
  }

  /**
   * Indexes the override classes by the field type each is for.
   *
   * @param array<class-string, string> $overrides
   *   The classes carrying #[FieldItemStructure], valued with their module.
   *
   * @return array<string, class-string>
   *   The class for each field type.
   *
   * @throws \LogicException
   *   When two classes are for one field type, or one does not implement
   *   FieldItemStructureInterface.
   */
  public static function overridesByType(array $overrides): array {
    $by_type = [];
    foreach (array_keys($overrides) as $class) {
      if (!is_subclass_of($class, FieldItemStructureInterface::class)) {
        throw new \LogicException(sprintf('%s carries #[FieldItemStructure] but does not implement %s.', $class, FieldItemStructureInterface::class));
      }
      $type = (new \ReflectionClass($class))->getAttributes(FieldItemStructure::class)[0]->newInstance()->type;
      if (isset($by_type[$type])) {
        throw new \LogicException(sprintf('Both %s and %s write the item structure of the %s field type; one field type has one.', $by_type[$type], $class, $type));
      }
      $by_type[$type] = $class;
    }
    return $by_type;
  }

}

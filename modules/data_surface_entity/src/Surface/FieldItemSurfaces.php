<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Surface;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Surface\Attribute\DerivesSurfaces;
use Drupal\data_surface\Surface\DerivedSituation;
use Drupal\data_surface\Surface\DerivedSurface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceCallable;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceDeriverInterface;
use Drupal\data_surface_entity\Access\FieldItemAccess;
use Drupal\data_surface_entity\FieldItemDerivation;
use Drupal\data_surface_entity\Refinement\TextFormatRefiner;
use Drupal\data_surface_entity\Surface\Attribute\FieldItemSurface;

/**
 * One surface per field a bundle's form shows.
 *
 * `field.item:<entity type>.<bundle>.<field>`.
 *
 * Derived from the field item's typed data (FieldItemDerivation), or,
 * for a field type someone wrote one for, from the class marked
 * #[FieldItemSurface(type:)], whose static #[RefinesInput] methods refine
 * it. Either way the field's own facts stay derived: its label, and its
 * two situations, `add` and `edit`, which the content entity surface
 * invokes it through by operation. Each knows the entity type, the bundle
 * and the field, and `edit` the entity's id, which is what its access
 * class asks field access with: open, locked, or omitted.
 *
 * Such a surface has no target: its parent, the content entity surface,
 * stores it under the field's name. So it is never a tool on its own.
 *
 * @see \Drupal\data_surface_entity\Surface\ContentEntitySurfaces
 */
#[DerivesSurfaces('field.item', overrides: FieldItemSurface::class)]
final class FieldItemSurfaces implements SurfaceDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\data_surface_entity\FieldItemDerivation $derivation
   *   What a surface can carry of a field.
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
          $derivatives[$entity_type_id . '.' . $bundle . '.' . $name] = new DerivedSurface(
            inputs: new SurfaceCallable(self::class, 'defineInputs', [...$bound, $override]),
            situations: [
              new DerivedSituation('add', $this->t('Enter @field on a new item', ['@field' => $field->getLabel()]), new SurfaceCallable(self::class, 'add', $bound)),
              new DerivedSituation('edit', $this->t('Change @field', ['@field' => $field->getLabel()]), new SurfaceCallable(self::class, 'edit', $bound), ['id' => 'string']),
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
            cacheTags: $this->derivation->cacheTags($entity_type_id, $bundle),
          );
        }
      }
    }
    return $derivatives;
  }

  /**
   * Fills one field item's shape.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $inputs
   *   The shape.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field.
   * @param class-string<\Drupal\data_surface_entity\Surface\FieldItemSurfaceInterface>|null $override
   *   The class written by hand for the field's type, or NULL.
   */
  public function defineInputs(ShapeInterface $inputs, string $entity_type_id, string $bundle, string $field_name, ?string $override = NULL): void {
    $field = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$field_name] ?? NULL;
    if ($field === NULL) {
      return;
    }
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
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   A context that creates, knowing which field.
   */
  public static function add(array $arguments, string $entity_type_id, string $bundle, string $field_name): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE, known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'field_name' => $field_name,
    ]);
  }

  /**
   * The item on an entity that exists.
   *
   * @param array{id: int|string} $arguments
   *   The entity's id, which the parent's edit situation knows.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $field_name
   *   The field.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   A context knowing which field of which entity.
   */
  public static function edit(array $arguments, string $entity_type_id, string $bundle, string $field_name): SurfaceContext {
    return new SurfaceContext('edit', known: [
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
   *   The classes carrying #[FieldItemSurface], valued with their module.
   *
   * @return array<string, class-string>
   *   The class for each field type.
   *
   * @throws \LogicException
   *   When two classes are for one field type, or one does not implement
   *   FieldItemSurfaceInterface.
   */
  public static function overridesByType(array $overrides): array {
    $by_type = [];
    foreach (array_keys($overrides) as $class) {
      if (!is_subclass_of($class, FieldItemSurfaceInterface::class)) {
        throw new \LogicException(sprintf('%s carries #[FieldItemSurface] but does not implement %s.', $class, FieldItemSurfaceInterface::class));
      }
      $type = (new \ReflectionClass($class))->getAttributes(FieldItemSurface::class)[0]->newInstance()->type;
      if (isset($by_type[$type])) {
        throw new \LogicException(sprintf('Both %s and %s write the item surface of the %s field type; one field type has one.', $by_type[$type], $class, $type));
      }
      $by_type[$type] = $class;
    }
    return $by_type;
  }

}

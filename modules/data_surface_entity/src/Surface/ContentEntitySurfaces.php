<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Surface;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Surface\Attribute\DerivesSurfaces;
use Drupal\data_surface\Surface\DerivedSituation;
use Drupal\data_surface\Surface\DerivedSurface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceCallable;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceDeriverInterface;
use Drupal\data_surface_entity\Access\ContentEntityAccess;
use Drupal\data_surface_entity\FieldItemDerivation;
use Drupal\data_surface_entity\Surface\Attribute\FieldItemSurface;
use Drupal\data_surface_entity\Target\ContentEntityTarget;

/**
 * One surface per content entity bundle with a form display.
 *
 * `entity.content:<entity type>.<bundle>`, `entity.content:node.page`: a
 * content entity's create and update form, said as a surface. Its
 * identity is the entity type, the bundle and the entity's id; its two
 * situations are `add`, which knows the entity type and the bundle (and
 * that there is no id yet), and `edit`, which takes the entity as `id`.
 * Its shape is one child per field the bundle's default form display
 * shows, in the display's order, each the field's item surface
 * (FieldItemSurfaces), invoked through the item's situation of the same
 * name, so each field answers for itself whether this account may change
 * it, only see it, or not see it at all. Fields a surface cannot carry
 * yet are left out, each with the reason, in the surface's notes.
 *
 * The target reads and writes the entity (ContentEntityTarget), and the
 * access class is the entity's: create access on add, update access on
 * edit.
 *
 * Decision: see docs/decisions.md#derived-definitions-at-discovery.
 */
#[DerivesSurfaces('entity.content', overrides: FieldItemSurface::class)]
final class ContentEntitySurfaces implements SurfaceDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\data_surface_entity\FieldItemDerivation $derivation
   *   What a surface can carry of a bundle's fields.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    protected readonly FieldItemDerivation $derivation,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function derivatives(array $overrides): array {
    $by_type = FieldItemSurfaces::overridesByType($overrides);
    $derivatives = [];
    foreach ($this->derivation->bundles() as $entity_type_id => $bundles) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      foreach ($bundles as $bundle => $bundle_label) {
        $bound = [$entity_type_id, $bundle];
        $fields = $this->derivation->fields($entity_type_id, $bundle, $by_type);
        $derivatives[$entity_type_id . '.' . $bundle] = new DerivedSurface(
          inputs: new SurfaceCallable(self::class, 'defineInputs', [...$bound, $by_type]),
          identity: ['entity_type', 'bundle', 'id'],
          situations: [
            new DerivedSituation('add', $this->t('Add @bundle', ['@bundle' => $bundle_label]), new SurfaceCallable(self::class, 'add', $bound)),
            new DerivedSituation(
              'edit',
              $this->t('Edit @bundle', ['@bundle' => $bundle_label]),
              new SurfaceCallable(self::class, 'edit', [...$bound, $this->derivation->idType($entity_type_id)]),
              ['id' => 'entity:' . $entity_type_id],
            ),
          ],
          target: ContentEntityTarget::class,
          access: ContentEntityAccess::class,
          outputs: new SurfaceCallable(self::class, 'defineOutputs', $bound),
          label: $this->t('@bundle (@entity_type)', [
            '@bundle' => $bundle_label,
            '@entity_type' => $entity_type->getLabel(),
          ]),
          description: $this->t('The @bundle form, as a surface: one part per field its form display shows, in that order; @count left out, each with the reason.', [
            '@bundle' => $bundle_label,
            '@count' => count($fields['skipped']),
          ]),
          notes: $fields['skipped'],
          cacheTags: $this->derivation->cacheTags($entity_type_id, $bundle),
        );
      }
    }
    return $derivatives;
  }

  /**
   * Fills one bundle's shape: its identity, then one child per field.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $inputs
   *   The shape.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param array<string, class-string> $overridden
   *   The field types someone wrote an item surface for, as discovery
   *   found them, so the parent attaches exactly the fields its children
   *   were derived for.
   */
  public function defineInputs(ShapeInterface $inputs, string $entity_type_id, string $bundle, array $overridden = []): void {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $inputs->add('entity_type', 'string', $this->t('Entity type'))->setRequired(TRUE);
    $inputs->add('bundle', 'string', $entity_type->getBundleLabel() ?: $this->t('Bundle'))->setRequired(TRUE);
    $inputs->add('id', $this->derivation->idType($entity_type_id), $this->t('ID'))
      ->setDescription($this->t('Given by storage when the @entity_type is saved.', ['@entity_type' => $entity_type->getSingularLabel()]));
    foreach ($this->derivation->fields($entity_type_id, $bundle, $overridden)['fields'] as $name => $field) {
      $child = $inputs->attach($name, 'field.item:' . $entity_type_id . '.' . $bundle . '.' . $name)
        ->setLabel($field->getLabel());
      if ((string) $field->getDescription() !== '') {
        $child->setDescription($field->getDescription());
      }
    }
    $inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags($this->derivation->cacheTags($entity_type_id, $bundle)));
  }

  /**
   * Says what a write answers with: the entity's id, once it has one.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $outputs
   *   The output shape.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   */
  public function defineOutputs(ShapeInterface $outputs, string $entity_type_id, string $bundle): void {
    $outputs->add('id', $this->derivation->idType($entity_type_id), $this->t('ID'))
      ->setDescription($this->t('The id of the @entity_type written, which a new one has only once it is saved.', [
        '@entity_type' => $this->entityTypeManager->getDefinition($entity_type_id)->getSingularLabel(),
      ]));
  }

  /**
   * A new entity of the bundle: it knows which bundle, and has no id.
   *
   * @param array $arguments
   *   None.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   A context that creates.
   */
  public static function add(array $arguments, string $entity_type_id, string $bundle): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE, known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      // Storage gives the id; knowing there is none locks it empty.
      'id' => NULL,
    ]);
  }

  /**
   * An entity of the bundle that exists.
   *
   * @param array{id: mixed} $arguments
   *   The entity, loaded from its id when given one.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param string $id_type
   *   The surface type of the entity's id, 'integer' or 'string'.
   *
   * @return \Drupal\data_surface\Surface\SurfaceContext
   *   A context knowing which entity.
   *
   * @throws \InvalidArgumentException
   *   When the entity is not one of the bundle.
   */
  public static function edit(array $arguments, string $entity_type_id, string $bundle, string $id_type = 'string'): SurfaceContext {
    $entity = $arguments['id'];
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type_id || $entity->bundle() !== $bundle) {
      throw new \InvalidArgumentException(sprintf(
        'The edit situation of entity.content:%s.%s takes a %s of the %s bundle.',
        $entity_type_id,
        $bundle,
        $entity_type_id,
        $bundle,
      ));
    }
    return new SurfaceContext('edit', known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $id_type === 'integer' ? (int) $entity->id() : (string) $entity->id(),
    ]);
  }

}

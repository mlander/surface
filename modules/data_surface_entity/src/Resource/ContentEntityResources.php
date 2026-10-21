<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Resource\Attribute\DerivesResources;
use Drupal\data_surface\Resource\DerivedOperation;
use Drupal\data_surface\Resource\DerivedResource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceCallable;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\KnowsDerivativeListTagsInterface;
use Drupal\data_surface\Resource\ResourceDeriverInterface;
use Drupal\data_surface_entity\Access\ContentEntityAccess;
use Drupal\data_surface_entity\FieldItemDerivation;
use Drupal\data_surface_entity\Structure\Attribute\FieldItemStructure;
use Drupal\data_surface_entity\Storage\ContentEntityStorage;

/**
 * One resource per content entity bundle with a form display.
 *
 * `entity.content:<entity type>.<bundle>`, `entity.content:node.page`: a
 * content entity's create and update form, said as a resource. Its
 * identity is the entity type, the bundle and the entity's id; its two
 * operations are `add`, which knows the entity type and the bundle (and
 * that there is no id yet), and `edit`, which takes the entity as `id`.
 * Its keys are one child per field the bundle's default form display
 * shows, in the display's order, each the field's item structure
 * (FieldItemStructures), invoked through the item's operation of the same
 * name, so each field answers for itself whether this account may change
 * it, only see it, or not see it at all. Fields a resource cannot carry
 * yet are left out, each with the reason, in the resource's notes.
 *
 * The storage reads and writes the entity (ContentEntityStorage), and the
 * access class is the entity's: create access on add, update access on
 * edit.
 *
 * Decision: see docs/decisions.md#derived-definitions-at-discovery.
 */
#[DerivesResources('entity.content', overriddenBy: FieldItemStructure::class)]
final class ContentEntityResources implements ResourceDeriverInterface, KnowsDerivativeListTagsInterface {

  use StringTranslationTrait;

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\data_surface_entity\FieldItemDerivation $derivation
   *   What a resource can carry of a bundle's fields.
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
    $by_type = FieldItemStructures::overridesByType($overrides);
    $derivatives = [];
    foreach ($this->derivation->bundles() as $entity_type_id => $bundles) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      foreach ($bundles as $bundle => $bundle_label) {
        $bound = [$entity_type_id, $bundle];
        $fields = $this->derivation->fields($entity_type_id, $bundle, $by_type);
        $derivatives[$entity_type_id . '.' . $bundle] = new DerivedResource(
          inputs: new ResourceCallable(self::class, 'defineInputs', [...$bound, $by_type]),
          identity: ['entity_type', 'bundle', 'id'],
          operations: [
            new DerivedOperation(
              'add',
              $this->t('Add @bundle', ['@bundle' => $bundle_label]),
              new ResourceCallable(self::class, 'add', $bound),
              creates: TRUE,
              description: $this->t('Creates a new @bundle item (@entity_type); the site gives it its id.', [
                '@bundle' => $bundle_label,
                '@entity_type' => $entity_type->getLabel(),
              ]),
            ),
            new DerivedOperation(
              'edit',
              $this->t('Edit @bundle', ['@bundle' => $bundle_label]),
              new ResourceCallable(self::class, 'edit', [...$bound, $this->derivation->idType($entity_type_id)]),
              ['id' => 'entity:' . $entity_type_id],
              description: $this->t('Changes an existing @bundle item (@entity_type), named by its id.', [
                '@bundle' => $bundle_label,
                '@entity_type' => $entity_type->getLabel(),
              ]),
            ),
          ],
          storage: ContentEntityStorage::class,
          access: ContentEntityAccess::class,
          outputs: new ResourceCallable(self::class, 'defineOutputs', $bound),
          label: $this->t('@bundle (@entity_type)', [
            '@bundle' => $bundle_label,
            '@entity_type' => $entity_type->getLabel(),
          ]),
          description: $this->t('The @bundle form, as a resource: one part per field its form display shows, in that order; @count left out, each with the reason.', [
            '@bundle' => $bundle_label,
            '@count' => count($fields['skipped']),
          ]),
          notes: $fields['skipped'],
          cacheTags: $this->derivation->bundleCacheTags($entity_type_id, $bundle),
        );
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
   * Fills one bundle's blueprint: its identity, then one child per field.
   *
   * @param \Drupal\data_surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param array<string, class-string> $overridden
   *   The field types someone wrote an item structure for, as discovery
   *   found them, so the parent attaches exactly the fields its children
   *   were derived for.
   */
  public function defineInputs(BlueprintInterface $inputs, string $entity_type_id, string $bundle, array $overridden = []): void {
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
    $inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags($this->derivation->bundleCacheTags($entity_type_id, $bundle)));
  }

  /**
   * Says what a write answers with: the entity's id, once it has one.
   *
   * @param \Drupal\data_surface\Resource\BlueprintInterface $outputs
   *   The output blueprint.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   */
  public function defineOutputs(BlueprintInterface $outputs, string $entity_type_id, string $bundle): void {
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
   * @return \Drupal\data_surface\Resource\OperationContext
   *   A context that creates.
   */
  public static function add(array $arguments, string $entity_type_id, string $bundle): OperationContext {
    return new OperationContext('add', creates: TRUE, known: [
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
   *   The Data Surface type of the entity's id, 'integer' or 'string'.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   A context knowing which entity.
   *
   * @throws \InvalidArgumentException
   *   When the entity is not one of the bundle.
   */
  public static function edit(array $arguments, string $entity_type_id, string $bundle, string $id_type = 'string'): OperationContext {
    $entity = $arguments['id'];
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type_id || $entity->bundle() !== $bundle) {
      throw new \InvalidArgumentException(sprintf(
        'The edit operation of entity.content:%s.%s takes a %s of the %s bundle.',
        $entity_type_id,
        $bundle,
        $entity_type_id,
        $bundle,
      ));
    }
    return new OperationContext('edit', known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $id_type === 'integer' ? (int) $entity->id() : (string) $entity->id(),
    ]);
  }

}

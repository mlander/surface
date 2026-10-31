<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface_examples_entity\Access\ContentEntityAccess;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\surface_examples_entity\Structure\Attribute\FieldItemStructure;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Drupal\surface_examples_entity\Variants\BundleFieldStructures;

/**
 * One resource per content entity bundle, and one per entity type.
 *
 * `entity.content:<entity type>.<bundle>`, `entity.content:node.page`: a
 * content entity's create and update form, said as a resource. Its
 * identity is the entity type, the bundle and the entity's id; its two
 * operations are `create`, which knows the entity type and the bundle (and
 * that there is no id yet), and `update`, which takes the entity as `id`.
 * Its keys are one child per field the bundle's default form display
 * shows, in the display's order, each the field's item structure
 * (FieldItemStructures), invoked through the item's operation of the same
 * name, so each field answers for itself whether this account may change
 * it, only see it, or not see it at all. Fields a resource cannot carry
 * yet are left out, each with the reason, in the resource's notes.
 *
 * `entity.content:<entity type>`, `entity.content:node`: the same forms
 * for every bundle of the entity type at once. Its identity is the entity
 * type and the id; `bundle` is a key, and chooses the variants at
 * `fields`, one per bundle, each the bundle's fields as its own resource
 * holds them: `entity.content:<entity type>.<bundle>.fields`, a structure
 * this deriver derives beside it, which attaches the same field item
 * structures, so nothing is derived twice. The variants come from the
 * site (BundleFieldStructures), so past the site list limit the full
 * schema names the bundles and leaves their fields to the schema call
 * that chooses one. On `create` the bundle is the caller's to choose, of
 * the bundles the account may create (SurfaceCreatableBundle); on
 * `update` it is the entity's, fixed.
 *
 * The storage reads and writes the entity (ContentEntityStorage), and the
 * access class is the entity's: create access on add, update access on
 * edit.
 *
 * Decision: see docs/decisions.md#derived-definitions-at-discovery.
 * Decision: see
 * docs/decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type.
 */
#[DerivesResources('entity.content', overriddenBy: FieldItemStructure::class)]
final class ContentEntityResources implements ResourceDeriverInterface, KnowsDerivativeListTagsInterface {

  use StringTranslationTrait;

  /**
   * The base id every resource and structure here is derived under.
   */
  public const BASE = 'entity.content';

  /**
   * The key an entity type's resource holds the chosen bundle's fields at.
   */
  public const FIELDS = 'fields';

  /**
   * What the derivative of a bundle's fields ends in, after the bundle.
   */
  public const FIELDS_SUFFIX = '.fields';

  /**
   * Constructs the deriver.
   *
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
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
      $derivatives[$entity_type_id] = $this->entityType($entity_type_id, $by_type);
      foreach ($bundles as $bundle => $bundle_label) {
        $bound = [$entity_type_id, $bundle];
        $fields = $this->derivation->fields($entity_type_id, $bundle, $by_type);
        $derivatives[$entity_type_id . '.' . $bundle] = new DerivedResource(
          inputs: new ResourceCallable(self::class, 'defineInputs', [...$bound, $by_type]),
          identity: ['entity_type', 'bundle', 'id'],
          operations: [
            new DerivedOperation(
              'create',
              $this->t('Add @bundle', ['@bundle' => $bundle_label]),
              new ResourceCallable(self::class, 'create', $bound),
              creates: TRUE,
              description: $this->t('Creates a new @bundle item (@entity_type); the site gives it its id.', [
                '@bundle' => $bundle_label,
                '@entity_type' => $entity_type->getLabel(),
              ]),
            ),
            new DerivedOperation(
              'update',
              $this->t('Edit @bundle', ['@bundle' => $bundle_label]),
              new ResourceCallable(self::class, 'update', [...$bound, $this->derivation->idType($entity_type_id)]),
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
        // The same fields, as a structure: the variant the entity type's
        // resource offers for this bundle.
        $derivatives[$entity_type_id . '.' . $bundle . self::FIELDS_SUFFIX] = new DerivedResource(
          inputs: new ResourceCallable(self::class, 'defineFields', [...$bound, $by_type]),
          label: $this->t('@bundle fields (@entity_type)', [
            '@bundle' => $bundle_label,
            '@entity_type' => $entity_type->getLabel(),
          ]),
          description: $this->t('The fields of the @bundle form, one part per field its form display shows, in that order: what entity.content:@id holds at its root.', [
            '@bundle' => $bundle_label,
            '@id' => $entity_type_id . '.' . $bundle,
          ]),
          cacheTags: $this->derivation->bundleCacheTags($entity_type_id, $bundle),
        );
      }
    }
    return $derivatives;
  }

  /**
   * Derives the resource of every bundle of one entity type at once.
   *
   * @param string $entity_type_id
   *   The entity type id.
   * @param array<string, class-string> $by_type
   *   The override class of each field type someone wrote one for.
   *
   * @return \Drupal\surface\Resource\DerivedResource
   *   `entity.content:<entity type>`.
   */
  protected function entityType(string $entity_type_id, array $by_type): DerivedResource {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $label = $entity_type->getSingularLabel();
    $bundle_label = $entity_type->getBundleLabel() ?: $this->t('bundle');
    return new DerivedResource(
      inputs: new ResourceCallable(self::class, 'defineEntityTypeInputs', [$entity_type_id]),
      identity: ['entity_type', 'id'],
      operations: [
        new DerivedOperation(
          'create',
          $this->t('Add @entity_type', ['@entity_type' => $label]),
          new ResourceCallable(self::class, 'createAny', [$entity_type_id]),
          creates: TRUE,
          description: $this->t("Creates a new @entity_type of the @bundle_label named by bundle, with that @bundle_label's fields; the site gives it its id.", [
            '@entity_type' => $label,
            '@bundle_label' => $bundle_label,
          ]),
        ),
        new DerivedOperation(
          'update',
          $this->t('Edit @entity_type', ['@entity_type' => $label]),
          new ResourceCallable(self::class, 'updateAny', [$entity_type_id, $this->derivation->idType($entity_type_id)]),
          ['id' => 'entity:' . $entity_type_id],
          description: $this->t('Changes an existing @entity_type, named by its id; its @bundle_label stays as it is.', [
            '@entity_type' => $label,
            '@bundle_label' => $bundle_label,
          ]),
        ),
      ],
      storage: ContentEntityStorage::class,
      access: ContentEntityAccess::class,
      outputs: new ResourceCallable(self::class, 'defineOutputs', [$entity_type_id, '']),
      label: $entity_type->getLabel(),
      description: $this->t("Any @entity_type form, as one resource: the @bundle_label chooses the fields, each @bundle_label's as its own resource holds them.", [
        '@entity_type' => $label,
        '@bundle_label' => $bundle_label,
      ]),
      // Read from the entity type alone: which bundles there are is the
      // provider's, asked at every build.
      cacheTags: ['entity_types'],
    );
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
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
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

    $this->defineFields($inputs, $entity_type_id, $bundle, $overridden);
  }

  /**
   * Fills a bundle's fields: one child per field its form display shows.
   *
   * What a bundle's resource holds at its root, and the structure the
   * entity type's resource holds at `fields` for the bundle: one place
   * attaches them, so the two cannot differ.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   * @param array<string, class-string> $overridden
   *   The field types someone wrote an item structure for.
   */
  public function defineFields(BlueprintInterface $inputs, string $entity_type_id, string $bundle, array $overridden = []): void {
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
   * Fills an entity type's blueprint: its identity, the bundle, its fields.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $inputs
   *   The blueprint to fill.
   * @param string $entity_type_id
   *   The entity type id.
   */
  public function defineEntityTypeInputs(BlueprintInterface $inputs, string $entity_type_id): void {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_label = $entity_type->getBundleLabel() ?: $this->t('Bundle');

    $inputs->add('entity_type', 'string', $this->t('Entity type'))->setRequired(TRUE);

    $inputs->add('bundle', 'string', $bundle_label)
      ->setDescription($this->t('Which @bundle_label the @entity_type is, which chooses its fields.', [
        '@bundle_label' => $bundle_label,
        '@entity_type' => $entity_type->getSingularLabel(),
      ]))
      ->setRequired(TRUE);

    $inputs->add('id', $this->derivation->idType($entity_type_id), $this->t('ID'))
      ->setDescription($this->t('Given by storage when the @entity_type is saved.', ['@entity_type' => $entity_type->getSingularLabel()]));

    $inputs->attachVariants(self::FIELDS, by: 'bundle', from: BundleFieldStructures::class, arguments: [$entity_type_id])
      ->setLabel($this->t('Fields'))
      ->setDescription($this->t("The fields of the @bundle_label chosen, as that @bundle_label's own resource holds them.", [
        '@bundle_label' => $bundle_label,
      ]));

    $inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags($this->derivation->listCacheTags()));
  }

  /**
   * Says what a write answers with: the entity's id, once it has one.
   *
   * @param \Drupal\surface\Resource\BlueprintInterface $outputs
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
   * @return \Drupal\surface\Resource\OperationContext
   *   A context that creates.
   */
  public static function create(array $arguments, string $entity_type_id, string $bundle): OperationContext {
    return new OperationContext('create', creates: TRUE, known: [
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
   *   The Surface API type of the entity's id, 'integer' or 'string'.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context knowing which entity.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the entity is of another type or bundle: it is no thing of this
   *   resource, so a URL naming it names nothing here.
   * @throws \InvalidArgumentException
   *   When what it was handed is no content entity.
   */
  public static function update(array $arguments, string $entity_type_id, string $bundle, string $id_type = 'string'): OperationContext {
    $entity = $arguments['id'];
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type_id || $entity->bundle() !== $bundle) {
      $message = sprintf(
        'The update operation of entity.content:%s.%s takes a %s of the %s bundle.',
        $entity_type_id,
        $bundle,
        $entity_type_id,
        $bundle,
      );
      throw $entity instanceof ContentEntityInterface ? new NamesNothingException($message, 'id') : new \InvalidArgumentException($message);
    }
    return new OperationContext('update', known: [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'id' => $id_type === 'integer' ? (int) $entity->id() : (string) $entity->id(),
    ]);
  }

  /**
   * A new entity of the entity type, its bundle the caller's to choose.
   *
   * The bundle is a value, not identity: the context knows the entity
   * type and that there is no id, and holds the bundle to those the
   * account may create, which the storage reads from the values. The
   * fields sit under `fields`, which the storage is told.
   *
   * @param array $arguments
   *   None.
   * @param string $entity_type_id
   *   The entity type id.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context that creates.
   */
  public static function createAny(array $arguments, string $entity_type_id): OperationContext {
    return (new OperationContext('create', creates: TRUE, known: [
      'entity_type' => $entity_type_id,
      // Storage gives the id; knowing there is none locks it empty.
      'id' => NULL,
      ContentEntityStorage::FIELDS_AT => self::FIELDS,
    ]))->withConstraint('bundle', 'SurfaceCreatableBundle', ['entityTypeId' => $entity_type_id]);
  }

  /**
   * An entity of the entity type that exists, its bundle fixed to its own.
   *
   * @param array{id: mixed} $arguments
   *   The entity, loaded from its id when given one.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $id_type
   *   The Surface API type of the entity's id, 'integer' or 'string'.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   A context knowing which entity and which bundle, the bundle held to
   *   the entity's.
   *
   * @throws \Drupal\surface\ResourceBuild\NamesNothingException
   *   When the entity is of another type, or of a bundle with no form
   *   display, which has no fields here: it is no thing of this resource,
   *   so a URL naming it names nothing here.
   * @throws \InvalidArgumentException
   *   When what it was handed is no content entity.
   */
  public function updateAny(array $arguments, string $entity_type_id, string $id_type = 'string'): OperationContext {
    $entity = $arguments['id'];
    if (!$entity instanceof ContentEntityInterface || $entity->getEntityTypeId() !== $entity_type_id || $this->derivation->formDisplay($entity_type_id, $entity->bundle()) === NULL) {
      $message = sprintf('The update operation of entity.content:%s takes a %s of a bundle with a form display.', $entity_type_id, $entity_type_id);
      throw $entity instanceof ContentEntityInterface ? new NamesNothingException($message, 'id') : new \InvalidArgumentException($message);
    }
    return (new OperationContext('update', known: [
      'entity_type' => $entity_type_id,
      'id' => $id_type === 'integer' ? (int) $entity->id() : (string) $entity->id(),
      'bundle' => $entity->bundle(),
      ContentEntityStorage::FIELDS_AT => self::FIELDS,
    ]))->withConstraint('bundle', 'Choice', ['choices' => [$entity->bundle()], 'surface_fixed' => TRUE]);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Structure;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A reference to an entity of any type: its entity type, then its id.
 *
 * Two keys. `entity_type` is a choice of the entity types the account may
 * reference one of, which the SurfaceReferenceableType constraint lists
 * and checks; `id` is a string, since entity types keep their ids as
 * integers or strings, and the refiner below narrows it to the entity type
 * chosen with the constraint any reference key carries, so it lists, looks
 * up and checks as one does. Being a static refiner, the full schema
 * states its answer for every entity type offered, as a conditional.
 *
 * Each structure the sponsorship attaches says which entity types it may
 * name in ENTITY_TYPES, the constraint's options; a config storage keeps
 * a content entity's uuid, unless the key it is attached at keeps the id
 * (References::STORED_AS on the map attach() returns).
 *
 * Decision: see docs/decisions.md#a-blueprint-takes-no-complex-data-types.
 */
abstract class AnyEntityStructure implements StructureInterface {

  /**
   * The entity types it may name: the SurfaceReferenceableType options.
   *
   * `interface`, ContentEntityInterface or ConfigEntityInterface for
   * entity types of that kind alone, and `targets`, the entity types
   * allowed, each with the bundles its id may be of (none for any).
   */
  protected const ENTITY_TYPES = [];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $targets = static::ENTITY_TYPES['targets'] ?? [];
    ksort($targets);

    $inputs->add('entity_type', 'string', t('Entity type'))
      ->addConstraint(References::TYPE_CONSTRAINT, array_filter([
        'interface' => static::ENTITY_TYPES['interface'] ?? NULL,
        'targets' => $targets,
      ]));

    $inputs->add('id', 'string', t('ID'))
      ->setDescription(t('The id of an entity of the entity type chosen.'))
      ->setSetting(References::TARGETS, $targets);

    // An id means nothing without its entity type, and an entity type
    // chosen names one.
    $inputs->narrow('id')
      ->when(Condition::absent('entity_type'))
      ->doesNotApply();
    $inputs->narrow('id')
      ->when(Condition::present('entity_type'))
      ->require();
  }

  /**
   * Narrows the id to an entity of the entity type chosen.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $id
   *   The id.
   * @param string $entity_type
   *   The entity type chosen.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The id, referring to that entity type and the bundles allowed of it.
   */
  #[RefinesInput('id')]
  public static function id(DataDefinitionInterface $id, string $entity_type): DataDefinitionInterface {
    $bundles = (array) ($id->getSetting(References::TARGETS)[$entity_type] ?? []);
    return $id->addConstraint(References::CONSTRAINT, array_filter([
      'entityTypeId' => $entity_type,
      'bundles' => array_values($bundles),
    ]));
  }

}

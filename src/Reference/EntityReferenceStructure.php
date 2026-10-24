<?php

declare(strict_types=1);

namespace Drupal\data_surface\Reference;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\BlueprintInterface;

/**
 * The two parts a bare `entity` key is: an entity type, then an id of it.
 *
 * `add('thing', 'entity', t('Thing'))` attaches this at the key, built
 * once per key with what its author narrowed the key by: the
 * DataSurfaceReferenceableType constraint written on the key, moved onto
 * `entity_type` (filter()). The id is a string, since entity types keep
 * their ids as integers or strings, and its refiner narrows it to the
 * chosen entity type the way `entity:<type>` builds a key: the same
 * constraint, so the same candidates, options, lookup and check. Being a
 * static refiner, the served contract states its answer for every entity
 * type the key offers, as a conditional, so a client narrows locally.
 *
 * Not discovered: the registry registers one structure per key
 * (ResourceRegistry::defineReference()), whose inputs are fill() with the
 * key's filter and whose refiner is id() below.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
final class EntityReferenceStructure {

  /**
   * The setting on the id holding the bundles each entity type allows.
   */
  public const TARGETS = 'data_surface_reference_targets';

  /**
   * Reads, and takes off the key, what its author narrowed it by.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map at the key, as its author left it.
   *
   * @return array{interface: string|null, targets: array<string, string[]>, required: bool}
   *   The interface its entity types implement, the entity types it
   *   allows with their bundles, and whether it must hold one.
   */
  public static function filter(MapDataDefinition $shell): array {
    $constraints = $shell->getConstraints();
    $options = $constraints[References::TYPE_CONSTRAINT] ?? [];
    unset($constraints[References::TYPE_CONSTRAINT]);
    $shell->setConstraints($constraints);
    $targets = [];
    foreach ((array) ($options['targets'] ?? []) as $type => $bundles) {
      $targets[(string) $type] = array_values(array_map('strval', (array) $bundles));
    }
    ksort($targets);
    return [
      'interface' => is_string($options['interface'] ?? NULL) ? $options['interface'] : NULL,
      'targets' => $targets,
      'required' => $shell->isRequired(),
    ];
  }

  /**
   * Declares the two parts, for one key's filter.
   *
   * @param \Drupal\data_surface\Resource\BlueprintInterface $inputs
   *   The structure's blueprint.
   * @param string|null $interface
   *   The interface its entity types implement, or NULL for any.
   * @param array<string, string[]> $targets
   *   The entity types it allows, each with its bundles; empty for all.
   * @param bool $required
   *   Whether the key must hold one.
   */
  public static function fill(BlueprintInterface $inputs, ?string $interface, array $targets, bool $required): void {
    $type = $inputs->add('entity_type', 'string', t('Entity type'))
      ->addConstraint(References::TYPE_CONSTRAINT, array_filter([
        'interface' => $interface,
        'targets' => $targets,
      ]));
    if ($required) {
      $type->setRequired(TRUE);
    }
    $inputs->add('id', 'string', t('ID'))
      ->setDescription(t('The id of an entity of the entity type chosen.'))
      ->setSetting(self::TARGETS, $targets);
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
   *   The id, referring to that entity type and the bundles the key allows
   *   of it.
   */
  #[RefinesInput('id', watches: ['entity_type'])]
  public static function id(DataDefinitionInterface $id, string $entity_type): DataDefinitionInterface {
    $bundles = (array) ($id->getSetting(self::TARGETS)[$entity_type] ?? []);
    return $id->addConstraint(References::CONSTRAINT, array_filter([
      'entityTypeId' => $entity_type,
      'bundles' => array_values($bundles),
    ]));
  }

}

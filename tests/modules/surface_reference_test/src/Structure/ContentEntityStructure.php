<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Structure;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Any content entity: its entity type, then its id.
 *
 * The reference to an entity of any type example 16 declares
 * (AnyEntityStructure in surface_examples_references), declared again
 * here: a test module depends on no example, and the class a module's
 * code extends must be loaded on the site a functional test requests,
 * where the base module's test classes are not.
 */
#[Structure('reference_test.thing')]
final class ContentEntityStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('entity_type', 'string', 'Entity type')
      ->addConstraint(References::TYPE_CONSTRAINT, ['interface' => ContentEntityInterface::class]);
    $inputs->add('id', 'string', 'ID')
      ->setDescription('The id of an entity of the entity type chosen.')
      ->setSetting(References::TARGETS, []);
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
   *   The id, referring to that entity type.
   */
  #[RefinesInput('id')]
  public static function id(DataDefinitionInterface $id, string $entity_type): DataDefinitionInterface {
    return $id->addConstraint(References::CONSTRAINT, ['entityTypeId' => $entity_type]);
  }

}

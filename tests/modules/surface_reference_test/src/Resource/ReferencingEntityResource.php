<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Resource;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_reference_test\Storage\ReferencingEntityStorage;

/**
 * A config_test entity that refers to a node and to a role.
 */
#[Resource('reference_test.entity',
  identity: ['id'],
  storage: ReferencingEntityStorage::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a referencing entity'),
  permission: 'administer site configuration',
  creates: TRUE,
  idempotent: TRUE,
)]
final class ReferencingEntityResource implements ResourceInterface {

  /**
   * Edits a referencing entity, named by its id.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a referencing entity'),
    permission: 'administer site configuration',
  )]
  public static function update(ConfigEntityInterface $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', 'Id')->setRequired(TRUE);
    $inputs->add('label', 'string', 'Label')
      ->setRequired(TRUE)
      ->addConstraint('Regex', ['pattern' => '/([^\PC])/u', 'match' => FALSE]);
    $inputs->add('page', 'entity:node', 'Page');
    $inputs->add('role', 'entity:user_role', 'Role');
  }

}

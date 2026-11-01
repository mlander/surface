<?php

declare(strict_types=1);

namespace Drupal\surface_string_id_test\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A content entity whose id is a string, which no storage assigns.
 *
 * As core's `entity_test_string_id`, whose module answers field access
 * from State and so cannot stand behind a static access answer: the id
 * field is a string with no default, so whoever creates one names it.
 */
#[ContentEntityType(
  id: 'surface_string_id',
  label: new TranslatableMarkup('Entity with a string id'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'bundle' => 'type',
    'label' => 'name',
  ],
  admin_permission: 'administer site configuration',
  base_table: 'surface_string_id',
)]
final class StringIdEntity extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('max_length', 64)
      ->setSetting('is_ascii', TRUE);
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setSetting('max_length', 64);
    return $fields;
  }

}

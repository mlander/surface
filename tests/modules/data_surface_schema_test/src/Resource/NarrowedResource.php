<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_schema_test\Storage\NarrowedStorage;

/**
 * A resource wider than the config schema it is stored to, written by hand.
 *
 * The code is any string where the schema says a machine name, the limit
 * reaches 100 where the schema stops at 10, the mode offers a third value
 * the schema's Choice does not, and the size is an integer where the
 * schema says a string. The note agrees. The build refuses it, naming
 * the four.
 */
#[Resource('schema_test.narrowed', storage: NarrowedStorage::class)]
#[Operation('configure', label: 'Configure the narrowed settings', permission: 'administer site configuration')]
final class NarrowedResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('code', 'string', t('Code'), default: 'main');
    $inputs->add('limit', 'integer', t('Limit'), default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 100]);
    $inputs->add('mode', 'string', t('Mode'), default: 'quiet')
      ->addConstraint('LabeledChoice', [
        'choices' => ['quiet' => t('Quiet'), 'loud' => t('Loud'), 'silent' => t('Silent')],
      ]);
    $inputs->add('size', 'integer', t('Size'), default: 3);
    $inputs->add('note', 'string', t('Note'));
  }

}

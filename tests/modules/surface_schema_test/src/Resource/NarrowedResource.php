<?php

declare(strict_types=1);

namespace Drupal\surface_schema_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_schema_test\Storage\NarrowedStorage;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('code', Schema::string()
      ->title(t('Code'))
      ->default('main'));
    $inputs->addSchema('limit', Schema::integer()
      ->title(t('Limit'))
      ->default(5)
      ->minimum(1)
      ->maximum(100));
    $inputs->addSchema('mode', Schema::options(['quiet' => t('Quiet'), 'loud' => t('Loud'), 'silent' => t('Silent')])
      ->title(t('Mode'))
      ->default('quiet'));
    $inputs->addSchema('size', Schema::integer()
      ->title(t('Size'))
      ->default(3));
    $inputs->addSchema('note', Schema::string()
      ->title(t('Note')));
  }

}

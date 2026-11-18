<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The access a site lists that holds a secret, which the other does not.
 */
#[Structure('values_test.access.keyed', label: new TranslatableMarkup('Keyed access'))]
final class KeyedAccessStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('holder', Schema::string()
      ->title(t('Holder'))
      ->default('me'));
    $inputs->add('token', Schema::string()
      ->title(t('Token'))
      ->writeOnly(TRUE));
  }

}

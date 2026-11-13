<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The access a site lists that holds no secret.
 */
#[Structure('values_test.access.open', label: new TranslatableMarkup('Open access'))]
final class OpenAccessStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'open');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

/**
 * A stock code: two capitals and digits, used at several keys.
 *
 * The depot uses it at two keys that narrow it differently, and the shelf
 * tag reads it through a port; SkuAlter tightens it everywhere.
 */
#[Shape('resource_test.sku')]
final class Sku implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^[A-Z]{2}\d+$/D', 'message' => 'A stock code is two capitals and digits, such as AB12.'];
    $sku = DataDefinition::create('string')
      ->setLabel(t('Stock code'))
      ->setDescription(t('Letters and digits.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($sku, ['AB12']);
    return $sku;
  }

}

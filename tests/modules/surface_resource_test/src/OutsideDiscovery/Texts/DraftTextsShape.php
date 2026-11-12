<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Texts;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says a bound in a draft's spelling, which preparing rewrites.
 */
#[Shape('resource_test.texts.draft')]
final class DraftTextsShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::number()
      ->with('minimum', 1)
      ->with('exclusiveMinimum', TRUE)
      ->title(TextsMade::t('Above one'))
      ->description(TextsMade::t('More than one, as a number.'));
  }

}

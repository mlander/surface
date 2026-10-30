<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Lists a deciding value no variant is for.
 *
 * Its kind lists `pot`, and no variant is for it: refused at build. The
 * `tin` variant, which the list leaves out, is not offered, which alone
 * would be a narrowing.
 */
#[Structure('resource_test.broken.unoffered_variant')]
final class UnofferedVariantStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['jar', 'pot']]);
    $inputs->attachVariants('settings', by: 'kind');
  }

}

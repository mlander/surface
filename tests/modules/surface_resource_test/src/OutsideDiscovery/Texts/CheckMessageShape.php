<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Texts;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says the message of a server check with t().
 */
#[Document('resource_test.texts.check')]
final class CheckMessageShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(TextsMade::t('Lowercase code'))
      ->check('drupal:Regex', ['pattern' => '/^[a-z]+$/'])
      ->message('check.drupal:Regex', TextsMade::t('Lowercase only.'));
  }

}

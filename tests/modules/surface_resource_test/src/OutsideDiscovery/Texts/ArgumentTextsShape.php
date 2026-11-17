<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery\Texts;

use PortableSchema\Builder\Attribute\Document;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Says its title and its pattern's message with a text as an argument.
 */
#[Document('resource_test.texts.arguments')]
final class ArgumentTextsShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(TextsMade::t('Phone @kind', ['@kind' => TextsMade::t('number')]))
      ->pattern('^a+$')
      ->message('pattern', TextsMade::t('Use @what only.', ['@what' => TextsMade::t('the letter a')]));
  }

}

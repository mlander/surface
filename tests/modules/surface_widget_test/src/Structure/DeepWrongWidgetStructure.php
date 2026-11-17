<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Asks, below a key, for a widget no widget answers to.
 *
 * Refused when built: each item of the list asks for it.
 */
#[Structure('widget_test.deep_wrong_widget')]
final class DeepWrongWidgetStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('note', Schema::string()
      ->title(t('Note')));
    $inputs->addSchema('tags', Schema::list(Schema::string()->ui(widget: 'no_such_widget'))
      ->title(t('Tags')));
  }

}

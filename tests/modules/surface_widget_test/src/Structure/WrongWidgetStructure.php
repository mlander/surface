<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Asks for widgets that do not draw what they are asked of.
 *
 * Refused when built: a word no widget answers to, and a structure asking
 * for a widget that draws one value.
 */
#[Structure('widget_test.wrong_widget')]
final class WrongWidgetStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('word', 'string', t('Word'))->setSetting('widget', 'no_such_widget');
  }

}

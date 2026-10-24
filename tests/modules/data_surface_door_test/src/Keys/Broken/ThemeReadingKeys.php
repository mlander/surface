<?php

declare(strict_types=1);

namespace Drupal\data_surface_door_test\Keys\Broken;

use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;

/**
 * Lists a key per region of the theme the page renders in: refused.
 *
 * A tool call renders no page, so the keys would be another list there.
 */
final class ThemeReadingKeys implements KeysFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return KeyedList::fromLabels([\Drupal::theme()->getActiveTheme()->getName() => 'Theme']);
  }

}

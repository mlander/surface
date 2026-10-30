<?php

declare(strict_types=1);

namespace Drupal\surface_door_test\Keys\Broken;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists a key per region of the theme the page renders in: refused.
 *
 * A tool call renders no page, so the keys would be another list there.
 */
final class ThemeReadingKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    return SiteList::fromLabels([\Drupal::theme()->getActiveTheme()->getName() => 'Theme']);
  }

}

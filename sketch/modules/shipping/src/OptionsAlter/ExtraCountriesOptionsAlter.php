<?php

declare(strict_types=1);

namespace Drupal\shipping\OptionsAlter;

use Drupal\address\Options\CountryOptions;
use Drupal\surface_sketch\Options\OptionsAlterInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

/**
 * Changes the country list EVERYWHERE: it targets the source, not a surface.
 */
final class ExtraCountriesOptionsAlter implements OptionsAlterInterface {

  public function applies(OptionsSourceInterface $source): bool {
    return $source instanceof CountryOptions;
  }

  public function alterOptions(array $options, array $arguments): array {
    return $options + ['XK' => 'Kosovo'];
  }

}

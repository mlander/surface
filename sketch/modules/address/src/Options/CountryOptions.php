<?php

declare(strict_types=1);

namespace Drupal\address\Options;

use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

/**
 * Every country the site knows. Holds the service a surface must not.
 */
final class CountryOptions implements OptionsSourceInterface {

  public function __construct(private readonly CountryManagerInterface $countries) {}

  public function getOptions(array $arguments = []): array {
    return $this->countries->getList();
  }

}

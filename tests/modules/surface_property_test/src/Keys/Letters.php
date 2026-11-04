<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Two entries, alpha and beta, which the children's keys from the site list.
 */
final class Letters implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('alpha', 'Alpha')
      ->add('beta', 'Beta');
  }

}

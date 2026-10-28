<?php

declare(strict_types=1);

namespace Drupal\data_surface_reference_test\Keys;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists two slots, each of which holds a reference: keys from the site.
 */
final class ReferenceSlots implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('lead', 'Lead')
      ->add('aside', 'Aside');
  }

}

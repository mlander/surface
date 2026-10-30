<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

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

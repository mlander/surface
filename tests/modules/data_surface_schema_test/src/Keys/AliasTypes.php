<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Keys;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists the types an alias may be made for, as pathauto lists entity types.
 *
 * Content starts checked and is locked, as a type whose path field another
 * module provides is in pathauto's form; the others start unchecked.
 */
final class AliasTypes implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('node', 'Content', default: TRUE, locked: TRUE)
      ->add('term', 'Taxonomy term')
      ->add('user', 'User');
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_schema_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;
use Drupal\surface_schema_test\Shape\MarkAction;

/**
 * Lists the punctuation marks an alias is cleaned of, each with its start.
 *
 * The hyphen, the separator, starts as "Replace by separator", the others
 * as "Remove", as pathauto's punctuation characters do.
 */
final class AliasMarks implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('hyphen', 'Hyphen (-)', default: MarkAction::REPLACE)
      ->add('period', 'Period (.)', default: MarkAction::REMOVE)
      ->add('underscore', 'Underscore (_)', default: MarkAction::REMOVE);
  }

}

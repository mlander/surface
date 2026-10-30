<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists keys that vary by the account: refused wherever they are asked for.
 */
final class AccountKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return SiteList::fromLabels(['mine' => 'Mine'])->addCacheContexts(['user.permissions']);
  }

}

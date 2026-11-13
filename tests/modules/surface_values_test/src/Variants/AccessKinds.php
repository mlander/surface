<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Variants;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\ClosedSiteListInterface;
use Drupal\surface\Resource\SiteList;
use Drupal\surface_values_test\Structure\KeyedAccessStructure;
use Drupal\surface_values_test\Structure\OpenAccessStructure;

/**
 * The kinds of access the site lists: one with a secret, one without.
 *
 * Variants a site lists are left out of the tool door's listing, so the
 * secrets the tool door finds differ from the pipeline's for a key that
 * takes them.
 */
final class AccessKinds implements ClosedSiteListInterface {

  /**
   * {@inheritdoc}
   */
  public static function structures(): array {
    return [
      'open' => OpenAccessStructure::class,
      'keyed' => KeyedAccessStructure::class,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('open', new TranslatableMarkup('Open'))
      ->add('keyed', new TranslatableMarkup('Keyed'));
  }

}

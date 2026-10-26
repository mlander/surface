<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Poster;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\PosterStorage;
use Drupal\data_surface_resource_test\Variants\PosterLayouts;

/**
 * A poster: variants from the site, one per layout a provider lists.
 *
 * Each layout says what panels it holds in a JSON Schema of its own, which
 * the provider hands over and the reader writes into a variant at
 * `panels`: the grid's in draft-04, the strip's with a keyword no
 * constraint says. The banner is a class as well, which wins.
 */
#[Resource('resource_test.poster', storage: PosterStorage::class)]
final class PosterResource implements ResourceInterface {

  /**
   * Configures the poster, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the poster', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('layout', 'string', t('Layout'), default: 'grid')
      ->setRequired(TRUE);
    $inputs->attachVariants('panels', by: 'layout', from: PosterLayouts::class)
      ->setLabel(t('Panels'));
  }

}

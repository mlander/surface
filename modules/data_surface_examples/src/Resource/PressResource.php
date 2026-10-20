<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Storage\PressStorage;
use Drupal\data_surface_examples\Structure\PersonStructure;
use Drupal\data_surface_examples\Structure\ReviewStructure;

/**
 * Example 8: the press page, one of two resources reusing a person.
 *
 * Its contact is the person structure, whose `listed` port reads this
 * page's `public`; the review is mixed in, so its keys are this page's.
 */
#[Resource('event.press', storage: PressStorage::class)]
final class PressResource implements ResourceInterface {

  /**
   * Configures the press page, which always exists.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the press page'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('public', 'boolean', t('Show on the press page'), default: TRUE);
    $inputs->attach('contact', PersonStructure::class, reads: ['listed' => 'public'])
      ->setLabel(t('Press contact'));
    $inputs->mixIn(ReviewStructure::class);
  }

}

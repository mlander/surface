<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Venue;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\HallStorage;
use Drupal\data_surface_resource_test\Structure\Seating\SeatingStructure;

/**
 * A hall: the same seating structure, reused under another name.
 *
 * Its chairs read the hall's arrangement as their layout, and leave the
 * optional guests port unwired. HallSeatingAlter attaches the structure a
 * second time, at its own mount, reading the hall's style and guests.
 */
#[Resource('resource_test.hall', storage: HallStorage::class)]
final class HallResource implements ResourceInterface {

  /**
   * Configures the hall, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the hall', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $layouts = [
      'theatre' => t('Theatre'),
      'banquet' => t('Banquet'),
      'standing' => t('Standing'),
    ];
    $inputs->add('arrangement', 'string', t('Arrangement'), default: 'banquet')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => $layouts]);
    $inputs->add('style', 'string', t('Overflow style'), default: 'theatre')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => $layouts]);
    $inputs->add('guests', 'integer', t('Guests'), default: 200)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 2000]);
    $inputs->attach('chairs', SeatingStructure::class, reads: ['layout' => 'arrangement'])
      ->setLabel(t('Chairs'));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Example 9: how one ticket type is sold, held at every type the site has.
 *
 * Whether the type is on sale is the container's to say, so the structure
 * reads it through a port, `selling`, and its limits do not apply while
 * the type is off sale.
 */
#[Structure('event.ticket_limit')]
final class TicketLimitStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('per_order', 'integer', t('Per order'), default: 4)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);
    $inputs->add('waiting_list', 'boolean', t('Waiting list when sold out'), default: FALSE);
    $inputs->reads('selling', 'boolean');
    $inputs->narrow('per_order')->when(Condition::equals('selling', FALSE))->doesNotApply();
    $inputs->narrow('waiting_list')->when(Condition::equals('selling', FALSE))->doesNotApply();
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_stage_test\Flow;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Flow\ChecksBeforeApplyInterface;
use Drupal\surface\Flow\FlowInterface;
use Drupal\surface\Flow\StagedInterface;
use Drupal\surface\Flow\StepsInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\Attribute\Flow;
use Drupal\surface_stage_test\Resource\ItineraryResource;

/**
 * The itinerary's update as a flow class, with a ledger row after it.
 *
 * The first two steps list the trip's keys rather than its groups: the
 * region, then the day and the nights, named by the resource's class at
 * the second step, which is still a step of the route's operation. `id`
 * is in no group and listed by no step, so the first step draws it; the
 * guide is in a group no step draws, so no step does. The ledger row is
 * drawn only while the stage holds more than two nights, and a trip of
 * more than seven nights is refused at the confirm page until the row
 * has a note.
 */
#[Flow('stage_test.itinerary_flow', label: new TranslatableMarkup('Edit a trip in steps'))]
final class ItineraryFlow implements FlowInterface, ChecksBeforeApplyInterface {

  /**
   * {@inheritdoc}
   */
  public function defineSteps(StepsInterface $steps): void {
    $steps->step('where', 'stage_test.itinerary', 'update')
      ->keys('region')
      ->label(new TranslatableMarkup('Where'))
      ->description(new TranslatableMarkup('The region the trip goes to.'));
    $steps->step('when', ItineraryResource::class, 'update')
      ->keys('day', 'nights');
    $steps->step('ledger', 'stage_test.ledger', 'update', ['id' => 'row'])
      ->label(new TranslatableMarkup('The ledger row'))
      ->when(static fn (StagedInterface $staged): bool => (int) $staged->value('stage_test.itinerary', 'nights') > 2);
  }

  /**
   * {@inheritdoc}
   */
  public function beforeApply(StagedInterface $staged): array {
    if ((int) $staged->value('stage_test.itinerary', 'nights') > 7 && (string) $staged->value('stage_test.ledger', 'note') === '') {
      return [new Violation('', '', new TranslatableMarkup('A trip of more than seven nights needs a note on its ledger row.'), 'note_required')];
    }
    return [];
  }

}

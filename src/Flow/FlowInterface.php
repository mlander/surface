<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * A flow written as a class: the steps a person takes over one stage.
 *
 * A class in a module's src/Flow directory carrying #[Flow],
 * built through the class resolver, so it may take services in its
 * constructor. defineSteps() lists the steps in the order they are drawn.
 * The first step is the route's own operation: its submit opens the
 * stage, and a route that names the flow and no resource takes its
 * resource and operation from it.
 *
 * defineSteps() runs when the router is rebuilt, to write the route's
 * resource and operation, and again on every page of the flow. So it
 * declares and does nothing else: it reads no request, no stage and no
 * stored value, and the callables it hands `when()` are stored, not
 * called. They are called on each page, with what the stage holds.
 *
 * @code
 * #[Flow('example.trip_flow', label: new TranslatableMarkup('Plan a trip'))]
 * final class TripFlow implements FlowInterface, ChecksBeforeApplyInterface {
 *
 *   public function defineSteps(StepsInterface $steps): void {
 *     $steps->step('where', 'example.trip', 'update')
 *       ->keys('region')
 *       ->label(new TranslatableMarkup('Where'))
 *       ->description(new TranslatableMarkup('The region the trip goes to.'));
 *     $steps->step('when', 'example.trip', 'update')
 *       ->keys('day', 'nights')
 *       ->label(new TranslatableMarkup('When'));
 *     $steps->step('ledger', 'example.ledger', 'update', ['id' => 'row'])
 *       ->label(new TranslatableMarkup('The ledger row'))
 *       ->when(static fn (StagedInterface $staged): bool => (int) $staged->value('example.trip', 'nights') > 2);
 *   }
 *
 *   public function beforeApply(StagedInterface $staged): array {
 *     if ((int) $staged->value('example.trip', 'nights') > 7 && (string) $staged->value('example.ledger', 'note') === '') {
 *       return [new Violation('', '', new TranslatableMarkup('A trip of more than seven nights needs a note on its ledger row.'), 'note_required')];
 *     }
 *     return [];
 *   }
 *
 * }
 * @endcode
 *
 * Experimental, as every flow is.
 *
 * Decision: see
 * docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
 *
 * @see \Drupal\surface\Resource\Attribute\Flow
 * @see docs/staging.md#flows
 */
interface FlowInterface {

  /**
   * Lists the flow's steps, in the order they are drawn.
   *
   * @param \Drupal\surface\Flow\StepsInterface $steps
   *   What the steps are declared into.
   */
  public function defineSteps(StepsInterface $steps): void;

}

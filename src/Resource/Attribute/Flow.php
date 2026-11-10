<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

use Drupal\surface\Staging\StageRecord;

/**
 * Names a flow: steps a person takes over one stage, ending in its apply.
 *
 * On a class in a module's src/Flow directory that implements
 * FlowInterface, whose defineSteps() lists the steps. A route names the
 * flow by its id in `_surface_flow`, and the operation form draws it one
 * step at a time. A flow has no path of its own: a route names it, as
 * every form door in the module is declared by a route.
 *
 * Experimental, as every flow is.
 *
 * @code
 * #[Flow('example.trip_flow', label: new TranslatableMarkup('Plan a trip'), keep: 'short', redirect: 'example.trip')]
 * final class TripFlow implements FlowInterface {
 * @endcode
 *
 * Decision: see
 * docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form.
 *
 * @see \Drupal\surface\Flow\FlowInterface
 * @see docs/staging.md#flows
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Flow {

  /**
   * Constructs a Flow attribute.
   *
   * @param string $id
   *   Machine name, dotted as a resource's is: `event.setup_flow`. A
   *   route names the flow by it.
   * @param string|\Stringable $label
   *   The label of the stage the first step opens.
   * @param string $keep
   *   How long that stage lives after its last write: `short`, the form
   *   cache's lifetime, or `long`, the temp store's, the default.
   * @param string|null $redirect
   *   The route the page goes to once the stage is applied, handed the
   *   flow route's own parameters that its path takes; NULL to stay on
   *   the confirm page.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $keep = StageRecord::LONG,
    public readonly ?string $redirect = NULL,
  ) {}

}

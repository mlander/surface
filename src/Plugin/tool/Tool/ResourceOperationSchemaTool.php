<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\tool\Tool;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ToolDoors;
use Drupal\tool\ExecutableResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers the schema of one operation for the values sent; writes nothing.
 *
 * Beside the write tool of every operation whose listing leaves a key's
 * entries out (InputDefinitions::deferred()): variants the site lists,
 * such as a component's props, whatever their count, and a map of keys
 * from the site past the budget,
 * `surface:<resource>:<operation>:schema`: the tool door's
 * `POST /surface-api/{resource}/schema`, which surface_http serves. An
 * agent chooses the deciding key, sends what it has so far, and reads
 * the chosen variant's keys.
 * It carries no #[Tool] of its own: ResourceOperationToolDeriver derives
 * it under the write tool's base plugin id and names this class on the
 * derivative.
 *
 * - **Inputs**: the operation's parameters, exactly as the write tool
 *   declares them, `values`, optional and open: what the caller has so
 *   far, and `stage` where a stage applies to the operation.
 * - **Access**: the write tool's, for the same context, through the
 *   runner as the door `schema tool` (ToolDoors::SCHEMA_TOOL).
 * - **Execution**: as the HTTP narrowing runs it. The runner prepares
 *   the operation for the calling account, building the resource and
 *   composing its storage with the stage; the values sent, narrowed to
 *   its keys, are laid over what it opens with (its defaults, and what
 *   is stored, or what the stage named holds); an
 *   answer the new choices orphan is dropped as the form drops it
 *   (refinementOverlay()); and the schema for these answers is emitted
 *   from that, with no widget hints.
 * - **Outputs**: `schema`, the schema for these answers, and `values`,
 *   the values settled so far, computed keys and defaults included.
 *
 * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
 *
 * @see \Drupal\surface\Plugin\Derivative\ResourceOperationToolDeriver
 * @see docs/served-contract.md#asking-again-at-the-tool-door
 */
final class ResourceOperationSchemaTool extends ResourceOperationToolBase {

  /**
   * {@inheritdoc}
   */
  public const SUFFIX = ':' . OperationInputs::SCHEMA;

  /**
   * {@inheritdoc}
   */
  public const DOOR = ToolDoors::SCHEMA_TOOL;

  /**
   * The emitter of both documents.
   */
  protected ContractEmitter $emitter;

  /**
   * The form builder, whose overlay a narrowing is emitted from.
   */
  protected SurfaceFormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->emitter = $container->get('surface.contract_emitter');
    $instance->formBuilder = $container->get('surface.form_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $run = $this->run($values);
    if (!$run instanceof OperationRun) {
      return $run;
    }
    $resource = $run->definition();
    $operation = $run->operation();
    $built = $run->surface();
    $keys = $built->getDefinitions()->toArray();
    // A resource its host or its parent stores is prepared with no storage.
    $stored = $run->hasStorage() ? array_intersect_key($run->storage()->load($built), $keys) : [];
    $current = $built->withDefaults($stored);
    $sent = $values[OperationInputs::VALUES] ?? NULL;
    // Keys the resource does not declare are no edit of it, left out as
    // the HTTP narrowing and a rebuild leave them out; a write refuses
    // them.
    $input = array_intersect_key(is_array($sent) ? $sent : [], $keys);
    $contract = $this->emitter->emit(
      $built,
      $this->formBuilder->refinementOverlay($built, $current, $input),
      $resource->id,
      $operation->id,
      $operation->label,
      operation: $operation->facts(),
    );
    return ExecutableResult::success(
      $this->t('@label: the schema for these values, and nothing was written.', ['@label' => (string) $operation->label]),
      [
        OperationInputs::SCHEMA => $contract->document['schema'],
        OperationInputs::VALUES => (array) $contract->document['values'],
      ],
    );
  }

}

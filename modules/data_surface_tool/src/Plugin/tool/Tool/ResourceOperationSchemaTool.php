<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\ExecutableResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers the schema of one operation for the values sent; writes nothing.
 *
 * Beside the write tool of every operation whose listing states a key
 * open because the site's list for it is past the budget (a component's
 * props when a theme has hundreds of components),
 * `data_surface:<resource>:<operation>:schema`: the tool door's
 * `POST /surface-api/{resource}/schema`. An agent chooses the deciding
 * key, sends what it has so far, and reads the chosen variant's keys.
 * It carries no #[Tool] of its own: ResourceOperationToolDeriver derives
 * it under the write tool's base plugin id and names this class on the
 * derivative.
 *
 * - **Inputs**: the operation's parameters, exactly as the write tool
 *   declares them, and `values`, optional and open: what the caller has
 *   so far.
 * - **Access**: the write tool's, for the same context.
 * - **Execution**: as the HTTP narrowing runs it. The resource is built
 *   for the calling account; the values sent, narrowed to its keys, are
 *   laid over what it opens with (its defaults, and what is stored); an
 *   answer the new choices orphan is dropped as the form drops it
 *   (refinementOverlay()); and the schema for these answers is emitted
 *   from that, with no widget hints.
 * - **Outputs**: `schema`, the schema for these answers, and `values`,
 *   the values settled so far, computed keys and defaults included.
 *
 * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
 *
 * @see \Drupal\data_surface_tool\Plugin\Derivative\ResourceOperationToolDeriver
 * @see docs/served-contract.md#asking-again-at-the-tool-door
 */
final class ResourceOperationSchemaTool extends ResourceOperationToolBase {

  /**
   * {@inheritdoc}
   */
  public const SUFFIX = ':' . OperationInputs::SCHEMA;

  /**
   * The emitter of both documents.
   */
  protected ContractEmitter $emitter;

  /**
   * The form builder, whose overlay a narrowing is emitted from.
   */
  protected DataSurfaceFormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->emitter = $container->get('data_surface.contract_emitter');
    $instance->formBuilder = $container->get('data_surface.form_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    try {
      [$resource, $operation, $context] = $this->operation($values);
    }
    catch (\InvalidArgumentException $e) {
      return $this->notFound($e);
    }
    // Asked again here, as the write tool does: execute() is callable from
    // PHP without an access check.
    if (!$this->operationAccess($resource, $context, $this->currentUser)->isAllowed()) {
      return $this->accessRefused($resource, $operation, $context);
    }
    $built = $this->resources->build($resource->name(), $context);
    $keys = $built->getDefinitions()->toArray();
    $stored = $resource->storage === NULL ? [] : array_intersect_key($this->resources->storage($resource->name(), $context, $built)->load($built), $keys);
    $current = array_replace($built->getDefaultValues(), $stored);
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

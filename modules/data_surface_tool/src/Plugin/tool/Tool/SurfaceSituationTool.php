<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\SurfaceBuild\SurfaceTargetAdapter;
use Drupal\data_surface_tool\Plugin\Derivative\SurfaceSituationToolDeriver;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\data_surface_tool\SurfaceResultReportingTrait;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\InputDefinitionRefinerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A tool for one situation of one surface, generated from the situation.
 *
 * One class, one tool per (surface, situation) the deriver finds:
 * `data_surface:node.type:add`, `data_surface:field.instance:edit`.
 * Nothing here names a surface, a key or a situation; all of it is read
 * from the derivative id and the static layer.
 *
 * - **Inputs**: the situation method's parameters, by name (an entity
 *   parameter is the entity's id); `values`, the surface's keys the
 *   situation does not already know; `dry_run`. The definition is
 *   static, which situations are what make possible: a situation that
 *   needs nothing is the surface's exact contract before anyone calls,
 *   and one that needs a subject is refined to it as soon as the subject
 *   arrives, through the Tool API's own input_definition_refiners.
 * - **Access**: the situation's permission, then the surface's access
 *   class, for the context the parameters build — the answer a route
 *   served by the same situation gives. A situation owns its operation,
 *   so an answer with no opinion is a refusal.
 * - **Execution**: the situation builds the context, the surface is
 *   built in it, and the pipeline submits `values` to the surface's
 *   composed target, or stops after prepare for a dry run. Prepare is
 *   where storage's own checks run, so a dry run is refused for what a
 *   write would be refused for.
 * - **Outputs**: the accepted values, whether they were written, and the
 *   surface's own outputs as its target reads them back; for a dry run,
 *   instead of outputs, what prepare rehearsed.
 *
 * The same deriver gives a situation that changes a thing that exists a
 * read tool beside this one, SurfaceSituationReadTool, under this base
 * plugin id.
 *
 * @see \Drupal\data_surface_tool\Plugin\Derivative\SurfaceSituationToolDeriver
 * @see \Drupal\data_surface_tool\SituationInputs
 */
#[Tool(
  id: 'data_surface',
  label: new TranslatableMarkup('Surface situation'),
  description: new TranslatableMarkup('Submits values to one situation of one surface.'),
  operation: ToolOperation::Write,
  deriver: SurfaceSituationToolDeriver::class,
)]
final class SurfaceSituationTool extends SurfaceSituationToolBase implements InputDefinitionRefinerInterface {

  use SurfaceResultReportingTrait;

  /**
   * The pipeline that accepts, validates, prepares and commits values.
   */
  protected DataSurfacePipelineInterface $pipeline;

  /**
   * The tool plugin manager, whose cached definitions a write can outdate.
   */
  protected CachedDiscoveryInterface $toolManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pipeline = $container->get('data_surface.pipeline');
    $instance->toolManager = $container->get('plugin.manager.tool');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The values input, once the parameters are known: the surface built
   * in the situation's real context, so a settings slot the subject's
   * type chooses is that variant, and exactly the identity keys the
   * situation knows are left out, and, for a situation that changes a
   * thing that exists, each property defaults to what is stored for it
   * now. Parameters that name nothing leave the declared definition
   * standing; executing refuses them.
   */
  public function refineInputDefinition(string $name, InputDefinitionInterface $definition, array $values): InputDefinitionInterface {
    if ($name !== SituationInputs::VALUES) {
      return $definition;
    }
    try {
      [$surface, , $context] = $this->situation($values);
      return $this->situationInputs->values($surface, $this->surfaces->build($surface->class, $context), $context);
    }
    catch (\InvalidArgumentException) {
      return $definition;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    [$surface, $situation, $context] = $this->situation($values);
    // Asked again here, not only in checkAccess(): execute() is callable
    // from PHP without an access check, and this is the method that
    // writes. The same answer travels into the pipeline, which stops
    // before reading storage when it is a refusal.
    $access = $this->situationAccess($surface, $context, $this->currentUser);
    if (!$access->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to do this.'));
    }
    $built = $this->surfaces->build($surface->class, $context);
    $target = $this->surfaces->target($surface->class, $context, $built);
    $input = $values[SituationInputs::VALUES] ?? [];
    $result = $this->pipeline->submit(
      $built,
      is_array($input) ? $input : [],
      $target,
      dry_run: (bool) ($values[SituationInputs::DRY_RUN] ?? FALSE),
      access: $access,
    );
    if (!$result->isValid()) {
      return ExecutableResult::failure($this->t('The values were refused: @violations', [
        '@violations' => $this->violationSummary($result->violations),
      ]));
    }
    if ($result->committed && $situation->needsNothing() && !$context->creates) {
      // This tool's own definition defaults its values to what was stored
      // when it was derived, and that has just changed.
      $this->toolManager->clearCachedDefinitions();
    }
    $outputs = $result->committed && $target instanceof SurfaceTargetAdapter
      ? $target->outputs($built, $result->values)
      : [];
    // A dry run stops after prepare, and what prepare rehearsed is the
    // preview: what storage would have been handed.
    if (!$result->committed && $result->prepared !== NULL && $target instanceof SurfaceTargetAdapter) {
      $outputs = [SituationInputs::PREPARED => $target->preview($result->prepared)];
    }
    $stale = $this->staleReferences($result->violations);
    return ExecutableResult::success(
      $result->committed
        ? $this->t('@label: the values were accepted and written.', ['@label' => (string) $situation->label])
        : $this->t('Dry run of @label: the values were accepted and prepared, and nothing was written.', ['@label' => (string) $situation->label]),
      [
        SituationInputs::VALUES => $result->values,
        SituationInputs::COMMITTED => $result->committed,
      ] + $outputs + ($stale === [] ? [] : ['stale' => $stale]),
    );
  }

}

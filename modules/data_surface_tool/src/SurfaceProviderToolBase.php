<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\DataSurfaceProviderInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A tool whose whole input is one provider's surface.
 *
 * The tool counterpart of the generic provider form: a provider answers
 * surface, access and destination for one coordinate, and this class
 * turns those three answers into a tool without knowing anything about
 * what the provider configures. A concrete tool names a provider service
 * and an operation, and declares two inputs and two outputs on its
 * attribute:
 *
 * - `values` (static::VALUES), a map. Declared on the attribute with a
 *   label and a description and nothing else; on every instance it is
 *   replaced by the provider's surface converted through
 *   SurfaceInputDefinitions, so what an invoker is handed is every key
 *   the surface holds — including the ones other modules mounted on it at
 *   build time — with its label, meaning, bounds and vocabulary.
 * - `dry_run` (static::DRY_RUN), an optional boolean. Stops the pipeline
 *   after prepare: the values are accepted, validated and shaped for
 *   storage, and nothing is written.
 * - Outputs `values` (the accepted values, a map) and `committed` (a
 *   boolean).
 *
 * Why one map rather than one input per surface key. The Tool API treats
 * top level input names as fixed by the plugin definition — the
 * serializer closes the root schema with additionalProperties: FALSE on
 * exactly that assumption, and refiners and normalize events may replace
 * a named input's definition but never add a name — while a map input's
 * definition may be replaced freely. Several consumers also read the
 * static plugin definition rather than an instance: drush tool:info's
 * input rows, and the AI connector's function call deriver. With one
 * input per key those would advertise a tool taking nothing but a dry
 * run flag; with one map they advertise a map, which is true and merely
 * less specific. And a surface key can then never collide with a name
 * the tool itself uses.
 *
 * Why the replacement happens here rather than through a refiner. An
 * input definition refiner runs only when one of its declared dependency
 * inputs is set, and a subject-less coordinate has no input to depend
 * on: the surface for adding a content type is known before the caller
 * says anything. So the instance answers getInputDefinitions() itself,
 * which is what the definition serializer, and through it the MCP bridge
 * and the AI connector's runtime schema, read.
 *
 * Only subject-less coordinates are served. A tool addressing one
 * existing thing would take its subject as an input and refine `values`
 * from it, which is what input_definition_refiners are for.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceProviderForm
 * @see \Drupal\data_surface_tool\SurfaceInputDefinitions
 */
abstract class SurfaceProviderToolBase extends ToolBase {

  use SurfaceResultReportingTrait;

  /**
   * The input carrying the surface's values.
   */
  public const VALUES = 'values';

  /**
   * The input asking for a dry run.
   */
  public const DRY_RUN = 'dry_run';

  /**
   * The output saying whether anything was written.
   */
  public const COMMITTED = 'committed';

  /**
   * The provider answering surface, access and destination.
   *
   * @var \Drupal\data_surface\DataSurfaceProviderInterface
   */
  protected DataSurfaceProviderInterface $provider;

  /**
   * The service that converts a surface into input definitions.
   *
   * @var \Drupal\data_surface_tool\SurfaceInputDefinitions
   */
  protected SurfaceInputDefinitions $surfaceInputDefinitions;

  /**
   * The pipeline that accepts, validates, prepares and commits values.
   *
   * @var \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface
   */
  protected DataSurfacePipelineInterface $pipeline;

  /**
   * Whether the values input has been replaced by the surface yet.
   *
   * @var bool
   */
  protected bool $surfaceInputResolved = FALSE;

  /**
   * Names the service of the provider this tool serves.
   *
   * @return string
   *   A service id whose service implements DataSurfaceProviderInterface.
   */
  abstract protected static function providerService(): string;

  /**
   * Names the operation this tool performs.
   *
   * @return string
   *   An operation from the provider's vocabulary. Never carries
   *   identity; this base only serves coordinates without a subject.
   */
  abstract protected function operation(): string;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $provider = $container->get(static::providerService());
    if (!$provider instanceof DataSurfaceProviderInterface) {
      throw new \LogicException(sprintf('The "%s" service does not provide a data surface.', static::providerService()));
    }
    $instance->provider = $provider;
    $instance->surfaceInputDefinitions = $container->get('data_surface_tool.input_definitions');
    $instance->pipeline = $container->get('data_surface.pipeline');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Replaces the declared values map with the provider's surface, once.
   * ToolBase's constructor asks for the input definitions before create()
   * has handed this instance its services, so a call that arrives before
   * the provider is set answers with the declared definitions and leaves
   * the replacement to the first call that can make it.
   */
  public function getInputDefinitions(bool $include_locked = FALSE): array {
    if (!$this->surfaceInputResolved && isset($this->provider)) {
      $this->surfaceInputResolved = TRUE;
      $definitions = parent::getInputDefinitions(TRUE);
      $declared = $definitions[static::VALUES];
      $definitions[static::VALUES] = $this->surfaceInputDefinitions->fromSurface(
        $this->provider->getDataSurface($this->operation()),
        $declared->getLabel() ?? '',
        $declared->getDescription() ?? '',
        $declared->isRequired(),
      );
      $this->setInputDefinitions($definitions);
    }
    return parent::getInputDefinitions($include_locked);
  }

  /**
   * {@inheritdoc}
   *
   * The provider's answer for the coordinate, which is the same answer
   * its routes and its generated form read.
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = $this->provider->surfaceAccess($this->operation(), NULL, $account);
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   *
   * One call does the whole of it. The surface says what the values may
   * be, the target says where they are stored, and the pipeline accepts,
   * validates, prepares and — unless this is a dry run — commits.
   */
  protected function doExecute(array $values): ExecutableResult {
    $operation = $this->operation();
    // Asked again here, not only in checkAccess(): execute() is callable
    // from PHP without an access check, and this is the method that
    // writes. The same answer travels into the pipeline, which stops
    // before reading storage when it is a refusal.
    $access = $this->provider->surfaceAccess($operation, NULL, $this->currentUser);
    if (!$access->isAllowed()) {
      return ExecutableResult::failure($this->t('You do not have permission to do this.'));
    }
    $input = $values[static::VALUES] ?? [];
    $result = $this->pipeline->submit(
      $this->provider->getDataSurface($operation),
      is_array($input) ? $input : [],
      $this->provider->getDataSurfaceTarget($operation),
      dry_run: (bool) ($values[static::DRY_RUN] ?? FALSE),
      access: $access,
    );
    if (!$result->isValid()) {
      return ExecutableResult::failure($this->t('The values were refused: @violations', [
        '@violations' => $this->violationSummary($result->violations),
      ]));
    }
    $stale = $this->staleReferences($result->violations);
    return ExecutableResult::success($this->resultMessage($result), [
      static::VALUES => $result->values,
      static::COMMITTED => $result->committed,
    ] + ($stale === [] ? [] : ['stale' => $stale]));
  }

  /**
   * Says what an accepted run did.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The pipeline's result, valid.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   */
  protected function resultMessage(DataSurfaceResult $result): TranslatableMarkup {
    return $result->committed
      ? $this->t('The values were accepted and written.')
      : $this->t('Dry run: the values were accepted and prepared, and nothing was written.');
  }

}

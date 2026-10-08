<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\SurfaceBuild\SurfaceCatalogue;
use Drupal\data_surface\SurfaceBuild\SurfacePlugins;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One tool per situation of every surface that names a target.
 *
 * The sketch's "one tool per situation, its inputs being the parameters
 * plus the surface's open keys", made from the static layer alone: the
 * surfaces discovery found, the situations on each, and what each
 * situation's signature says it needs. The derivative id is
 * `<surface id>:<situation id>`, so a plugin id reads
 * `data_surface:node.type:add` — the address the sketch gives a
 * situation.
 *
 * Two rules say which situations are not tools, and the catalogue
 * applies the same ones (SurfaceCatalogue::standalone()). A surface that
 * names no target, or that a plugin names with #[UsesSurface], has no
 * tool: its host supplies the target (a plugin's configuration), and
 * only the host holds it. And a situation whose permission has a `%key`
 * placeholder none of its parameters can supply is not a tool, because
 * nothing a caller sends could name the permission it asks for: the
 * field storage's add, which only a field adding its storage can ask. A
 * surface or situation that cannot be described — two situations with
 * one id, a situation returning another's operation, a parameter no tool
 * can send — is left out and logged, so one module's mistake does not
 * take every other tool down with it; building that surface reports it
 * by name.
 *
 * @see \Drupal\data_surface_tool\SituationInputs
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\SurfaceSituationTool
 */
final class SurfaceSituationToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs a SurfaceSituationToolDeriver.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface_tool\SituationInputs $situationInputs
   *   What a situation's tool takes and answers with.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where a surface left out is reported.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacePlugins $plugins
   *   Which plugins name which surface with #[UsesSurface].
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly SituationInputs $situationInputs,
    protected readonly LoggerInterface $logger,
    protected readonly SurfacePlugins $plugins,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('data_surface.surface_registry'),
      $container->get('data_surface_tool.situation_inputs'),
      $container->get('logger.channel.data_surface'),
      $container->get('data_surface.surface_plugins'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof ToolDefinition);
    $this->derivatives = [];
    foreach ($this->registry->getDefinitions() as $surface) {
      // A surface with no target of its own is stored by its parent or by
      // its host; one a plugin names with #[UsesSurface] is configured
      // through that plugin's host, which holds the instance and supplies
      // the target. Neither is a tool of its own.
      // phpcs:ignore Drupal.Files.LineLength.TooLong
      // SKETCH GAP: the sketch generates one tool per situation without saying what of a plugin's surface; a surface any plugin names with #[UsesSurface] gets no tool, whatever it declares, because only the plugin's host holds the instance it configures.
      $plugins = $this->plugins->usedBy($surface->class);
      if ($surface->target === NULL || $plugins !== []) {
        continue;
      }
      try {
        $situations = $this->registry->getSituations($surface->class);
      }
      catch (\LogicException $e) {
        $this->skip($surface->id, $e);
        continue;
      }
      foreach ($situations as $situation) {
        // phpcs:ignore Drupal.Files.LineLength.TooLong
        // SKETCH GAP: the sketch generates one tool per situation without saying what of one that is only ever a child's (a field's storage, added by the field); a situation whose permission names a %key placeholder none of its parameters can supply could never be allowed, so it is not a tool.
        if (!SurfaceCatalogue::standalone($surface, $situation, $plugins)) {
          continue;
        }
        $id = $surface->id . ':' . $situation->id;
        try {
          $definition = (clone $base_plugin_definition)
            ->setLabel(new TranslatableMarkup('@label', ['@label' => (string) $situation->label]))
            ->setDescription($this->situationInputs->description($surface, $situation));
          foreach (array_keys($definition->getInputDefinitions(TRUE)) as $name) {
            $definition->removeInputDefinition($name);
          }
          foreach ($this->situationInputs->inputs($surface, $situation) as $name => $input) {
            $definition->addInputDefinition($name, $input);
          }
          foreach (array_keys($definition->getOutputDefinitions()) as $name) {
            $definition->removeOutputDefinition($name);
          }
          foreach ($this->situationInputs->outputs($surface, $situation) as $name => $output) {
            $definition->addOutputDefinition($name, $output);
          }
          // Once the situation's parameters arrive, the values input is
          // the surface built in the situation's real context.
          $parameters = array_map(static fn ($parameter): string => $parameter->name, $situation->parameters);
          $definition->setInputDefinitionRefiners($parameters === [] ? [] : [SituationInputs::VALUES => $parameters]);
        }
        catch (\LogicException | \InvalidArgumentException $e) {
          $this->skip($id, $e);
          continue;
        }
        $this->derivatives[$id] = $definition;
      }
    }
    return $this->derivatives;
  }

  /**
   * Logs a surface or situation left without a tool.
   *
   * @param string $id
   *   The surface id, or the derivative id.
   * @param \Throwable $e
   *   Why.
   */
  protected function skip(string $id, \Throwable $e): void {
    $this->logger->warning('No tool for @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
  }

}

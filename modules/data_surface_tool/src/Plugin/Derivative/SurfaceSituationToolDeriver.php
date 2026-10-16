<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\SurfaceBuild\SituationDefinition;
use Drupal\data_surface\SurfaceBuild\SurfaceCatalogue;
use Drupal\data_surface\SurfaceBuild\SurfaceDefinition;
use Drupal\data_surface\SurfaceBuild\SurfacePlugins;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface_tool\Plugin\tool\Tool\SurfaceSituationReadTool;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One tool per situation of every surface that names a target.
 *
 * The pattern's "one tool per situation, its inputs being the parameters
 * plus the surface's open keys", made from the static layer alone: the
 * surfaces discovery found, the situations on each, and what each
 * situation's signature says it needs. The derivative id is
 * `<surface id>:<situation id>`, so a plugin id reads
 * `data_surface:node.type:add` — the address the pattern gives a
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
 * A situation that needs nothing and changes a thing that exists is
 * described in its real context, its values defaulting to what is stored
 * when the definitions are derived; they are cached, so the tool clears
 * them after it writes.
 *
 * A situation that changes a thing that exists has a read tool beside
 * its write tool, `<surface id>:<situation id>:read`, of the class
 * SurfaceSituationReadTool: its parameters as inputs, the stored values
 * as its output. Which situations those are is the catalogue's to say
 * (SurfaceCatalogue::reads()), as which can be asked at all is.
 *
 * @see \Drupal\data_surface_tool\SituationInputs
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\SurfaceSituationTool
 */
final class SurfaceSituationToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

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
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceCatalogue $catalogue
   *   Which situations change a thing that exists, and so can be read.
   */
  public function __construct(
    protected readonly SurfaceRegistry $registry,
    protected readonly SituationInputs $situationInputs,
    protected readonly LoggerInterface $logger,
    protected readonly SurfacePlugins $plugins,
    TranslationInterface $string_translation,
    protected readonly SurfaceCatalogue $catalogue,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('data_surface.surface_registry'),
      $container->get('data_surface_tool.situation_inputs'),
      $container->get('logger.channel.data_surface'),
      $container->get('data_surface.surface_plugins'),
      $container->get('string_translation'),
      $container->get('data_surface.surface_catalogue'),
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
      $plugins = $this->plugins->usedBy($surface->name());
      if ($surface->target === NULL || $plugins !== []) {
        continue;
      }
      try {
        $situations = $this->registry->getSituations($surface->name());
      }
      catch (\LogicException $e) {
        $this->skip($surface->id, $e);
        continue;
      }
      foreach ($situations as $situation) {
        if (!SurfaceCatalogue::standalone($surface, $situation, $plugins)) {
          continue;
        }
        $id = $surface->id . ':' . $situation->id;
        try {
          $definition = $this->definition(
            $base_plugin_definition,
            $this->t('@label', ['@label' => (string) $situation->label]),
            $this->situationInputs->description($surface, $situation),
            $this->situationInputs->inputs($surface, $situation),
            $this->situationInputs->outputs($surface, $situation),
          );
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
        if ($this->catalogue->reads($surface, $situation, $plugins)) {
          $this->read($base_plugin_definition, $surface, $situation);
        }
      }
    }
    return $this->derivatives;
  }

  /**
   * Derives the read tool beside a situation's write tool.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The write tool's base definition.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\SurfaceBuild\SituationDefinition $situation
   *   A situation that changes a thing that exists.
   */
  protected function read(ToolDefinition $base_plugin_definition, SurfaceDefinition $surface, SituationDefinition $situation): void {
    $id = $surface->id . ':' . $situation->id . SurfaceSituationReadTool::SUFFIX;
    try {
      $definition = $this->definition(
        $base_plugin_definition,
        $this->t('@label, read only', ['@label' => (string) $situation->label]),
        $this->situationInputs->readDescription($surface, $situation),
        $this->situationInputs->parameters($surface, $situation),
        $this->situationInputs->readOutputs($surface, $situation),
      )
        ->setOperation(ToolOperation::Read)
        ->setDestructive(FALSE)
        ->setInputDefinitionRefiners([]);
      $definition->setClass(SurfaceSituationReadTool::class);
    }
    catch (\LogicException | \InvalidArgumentException $e) {
      $this->skip($id, $e);
      return;
    }
    $this->derivatives[$id] = $definition;
  }

  /**
   * Writes one derived tool's definition over a copy of the base one.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The base definition.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The tool's label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The tool's description.
   * @param array<string, \Drupal\tool\TypedData\InputDefinitionInterface> $inputs
   *   Its inputs, which replace the base definition's.
   * @param array<string, \Drupal\tool\TypedData\OutputDefinitionInterface> $outputs
   *   Its outputs, which replace the base definition's.
   *
   * @return \Drupal\tool\Tool\ToolDefinition
   *   The definition.
   */
  protected function definition(ToolDefinition $base_plugin_definition, TranslatableMarkup $label, TranslatableMarkup $description, array $inputs, array $outputs): ToolDefinition {
    $definition = (clone $base_plugin_definition)
      ->setLabel($label)
      ->setDescription($description);
    foreach (array_keys($definition->getInputDefinitions(TRUE)) as $name) {
      $definition->removeInputDefinition($name);
    }
    foreach ($inputs as $name => $input) {
      $definition->addInputDefinition($name, $input);
    }
    foreach (array_keys($definition->getOutputDefinitions()) as $name) {
      $definition->removeOutputDefinition($name);
    }
    foreach ($outputs as $name => $output) {
      $definition->addOutputDefinition($name, $output);
    }
    return $definition;
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

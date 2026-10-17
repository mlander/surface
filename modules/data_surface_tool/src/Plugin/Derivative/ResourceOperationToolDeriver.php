<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceCatalogue;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourcePlugins;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationReadTool;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One tool per operation of every resource that names its storage.
 *
 * The pattern's "one tool per operation, its inputs being the parameters
 * plus the resource's open keys", made from the static layer alone: the
 * resources discovery found, the operations on each, and what each
 * operation's signature says it needs. The derivative id is
 * `<resource id>:<operation id>`, so a plugin id reads
 * `data_surface:node.type:add`: the address the pattern gives an
 * operation.
 *
 * Two rules say which operations are not tools, and the catalogue
 * applies the same ones (ResourceCatalogue::standalone()). A resource or
 * structure that names no storage, or that a plugin names with
 * #[UsesStructure], has no tool: its host supplies the storage (a
 * parent, or a plugin's configuration), and only the host holds it. And
 * an operation whose permission has a `%key` placeholder none of its
 * parameters can supply is not a tool, because nothing a caller sends
 * could name the permission it asks for: the field storage's add, which
 * only a field adding its storage can ask. A
 * resource or operation that cannot be described (two operations with
 * one id, an operation returning a context with another's id, a
 * parameter no tool can send) is left out and logged, so one module's
 * mistake does not take every other tool down with it; building that
 * resource reports it by name.
 *
 * An operation that needs nothing and changes a thing that exists is
 * described in its real context, its values defaulting to what is stored
 * when the definitions are derived; they are cached, so the tool clears
 * them after it writes.
 *
 * An operation that changes a thing that exists has a read tool beside
 * its write tool, `<resource id>:<operation id>:read`, of the class
 * ResourceOperationReadTool: its parameters as inputs, the stored values
 * as its output. Which operations those are is the catalogue's to say
 * (ResourceCatalogue::reads()), as which can be asked at all is.
 *
 * @see \Drupal\data_surface_tool\OperationInputs
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationTool
 */
final class ResourceOperationToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ResourceOperationToolDeriver.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface_tool\OperationInputs $operationInputs
   *   What an operation's tool takes and answers with.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where a resource left out is reported.
   * @param \Drupal\data_surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure with #[UsesStructure].
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\data_surface\ResourceBuild\ResourceCatalogue $catalogue
   *   Which operations change a thing that exists, and so can be read.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly OperationInputs $operationInputs,
    protected readonly LoggerInterface $logger,
    protected readonly ResourcePlugins $plugins,
    TranslationInterface $string_translation,
    protected readonly ResourceCatalogue $catalogue,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('data_surface.resource_registry'),
      $container->get('data_surface_tool.operation_inputs'),
      $container->get('logger.channel.data_surface'),
      $container->get('data_surface.resource_plugins'),
      $container->get('string_translation'),
      $container->get('data_surface.resource_catalogue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof ToolDefinition);
    $this->derivatives = [];
    foreach ($this->registry->getDefinitions() as $surface) {
      // A resource or structure with no storage of its own is stored by
      // its parent or by its host; one a plugin names with
      // #[UsesStructure] is configured through that plugin's host, which
      // holds the instance and supplies the storage. Neither is a tool of
      // its own.
      $plugins = $this->plugins->usedBy($surface->name());
      if ($surface->storage === NULL || $plugins !== []) {
        continue;
      }
      try {
        $operations = $this->registry->getOperations($surface->name());
      }
      catch (\LogicException $e) {
        $this->skip($surface->id, $e);
        continue;
      }
      foreach ($operations as $operation) {
        if (!ResourceCatalogue::standalone($surface, $operation, $plugins)) {
          continue;
        }
        $id = $surface->id . ':' . $operation->id;
        try {
          $definition = $this->definition(
            $base_plugin_definition,
            $this->t('@label', ['@label' => (string) $operation->label]),
            $this->operationInputs->description($surface, $operation),
            $this->operationInputs->inputs($surface, $operation),
            $this->operationInputs->outputs($surface, $operation),
          );
          // Once the operation's parameters arrive, the values input is
          // the resource built in the operation's real context.
          $parameters = array_map(static fn ($parameter): string => $parameter->name, $operation->parameters);
          $definition->setInputDefinitionRefiners($parameters === [] ? [] : [OperationInputs::VALUES => $parameters]);
        }
        catch (\LogicException | \InvalidArgumentException $e) {
          $this->skip($id, $e);
          continue;
        }
        $this->derivatives[$id] = $definition;
        if ($this->catalogue->reads($surface, $operation, $plugins)) {
          $this->read($base_plugin_definition, $surface, $operation);
        }
      }
    }
    return $this->derivatives;
  }

  /**
   * Derives the read tool beside an operation's write tool.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The write tool's base definition.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $surface
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   An operation that changes a thing that exists.
   */
  protected function read(ToolDefinition $base_plugin_definition, ResourceDefinition $surface, OperationDefinition $operation): void {
    $id = $surface->id . ':' . $operation->id . ResourceOperationReadTool::SUFFIX;
    try {
      $definition = $this->definition(
        $base_plugin_definition,
        $this->t('@label, read only', ['@label' => (string) $operation->label]),
        $this->operationInputs->readDescription($surface, $operation),
        $this->operationInputs->parameters($surface, $operation),
        $this->operationInputs->readOutputs($surface, $operation),
      )
        ->setOperation(ToolOperation::Read)
        ->setDestructive(FALSE)
        ->setInputDefinitionRefiners([]);
      $definition->setClass(ResourceOperationReadTool::class);
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
   * Logs a resource or operation left without a tool.
   *
   * @param string $id
   *   The resource id, or the derivative id.
   * @param \Throwable $e
   *   Why.
   */
  protected function skip(string $id, \Throwable $e): void {
    $this->logger->warning('No tool for @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
  }

}

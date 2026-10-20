<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
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
use Drupal\data_surface_tool\ToolDefinitionsInvalidator;
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
 * An operation that needs nothing is described in its real context. One
 * that changes a thing that exists lists no defaults: a key left out keeps
 * its current value, which its read tool returns, and the definitions are
 * cached, so a stored value listed here would go stale unseen.
 *
 * The definitions are cached for every account, so they are no account's:
 * each resource is built for no account (every access answer set aside
 * and its key marked) and derived as the anonymous account, so an option
 * list read from the site is never a privileged account's. A tool
 * instance lists `values` again for the account that asks.
 *
 * The operation's permission is the tool's declared permission, which
 * the Tool API checks before the tool's own access and prints in
 * `tool:info`, when it is one permission as written: one with a `%key`
 * placeholder names a different permission for each thing it is asked
 * of, so it is declared nowhere and the tool's own access, which fills
 * the placeholder from the parameters, is the only check.
 *
 * Each write tool says how its operation behaves (behaves()): the Read
 * kind for a read only operation, the Write kind for every other, and
 * whether it may destroy what is there.
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
   * @param \Drupal\data_surface_tool\ToolDefinitionsInvalidator|null $invalidator
   *   What clears the definitions when what they were read from changes;
   *   NULL remembers nothing.
   * @param \Drupal\Core\Session\AccountSwitcherInterface|null $accountSwitcher
   *   What derives as the anonymous account; NULL derives as the current.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly OperationInputs $operationInputs,
    protected readonly LoggerInterface $logger,
    protected readonly ResourcePlugins $plugins,
    TranslationInterface $string_translation,
    protected readonly ResourceCatalogue $catalogue,
    protected readonly ?ToolDefinitionsInvalidator $invalidator = NULL,
    protected readonly ?AccountSwitcherInterface $accountSwitcher = NULL,
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
      $container->get('data_surface_tool.tool_definitions_invalidator'),
      $container->get('account_switcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof ToolDefinition);
    // Cached for every account, so derived as none: no access answer is
    // applied (OperationInputs builds for no account), and what an option
    // list reads of the site is what the anonymous account is offered.
    // Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
    $this->accountSwitcher?->switchTo(new AnonymousUserSession());
    try {
      return $this->derive($base_plugin_definition);
    }
    finally {
      $this->accountSwitcher?->switchBack();
    }
  }

  /**
   * Derives every tool, as getDerivativeDefinitions() describes.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The base definition.
   *
   * @return array<string, \Drupal\tool\Tool\ToolDefinition>
   *   The derived definitions, by derivative id.
   */
  protected function derive(ToolDefinition $base_plugin_definition): array {
    $this->derivatives = [];
    // What the definitions are read from, beside what the Tool API keeps
    // them by: a derived resource's facts, and every built resource's
    // cacheability (the keys a provider lists from the site among it).
    // Decision: see docs/decisions.md#keys-that-come-from-the-site.
    $tags = [];
    foreach ($this->registry->getDefinitions() as $resource) {
      $tags = Cache::mergeTags($tags, $resource->cacheTags);
    }
    foreach ($this->registry->getDefinitions() as $resource) {
      // A resource or structure with no storage of its own is stored by
      // its parent or by its host; one a plugin names with
      // #[UsesStructure] is configured through that plugin's host, which
      // holds the instance and supplies the storage. Neither is a tool of
      // its own.
      $plugins = $this->plugins->usedBy($resource->name());
      if ($resource->storage === NULL || $plugins !== []) {
        continue;
      }
      try {
        $operations = $this->registry->getOperations($resource->name());
      }
      catch (\LogicException $e) {
        $this->skip($resource->id, $e);
        continue;
      }
      foreach ($operations as $operation) {
        if (!ResourceCatalogue::standalone($resource, $operation, $plugins)) {
          continue;
        }
        $id = $resource->id . ':' . $operation->id;
        try {
          $definition = $this->definition(
            $base_plugin_definition,
            $this->t('@label', ['@label' => (string) $operation->label]),
            $this->operationInputs->description($resource, $operation),
            $this->operationInputs->inputs($resource, $operation),
            $this->operationInputs->outputs($resource, $operation),
            $operation,
          );
          static::behaves($definition, $operation);
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
        if ($this->catalogue->reads($resource, $operation, $plugins)) {
          $this->read($base_plugin_definition, $resource, $operation);
        }
      }
    }
    $this->invalidator?->remember(Cache::mergeTags($tags, $this->operationInputs->listedCacheTags()));
    return $this->derivatives;
  }

  /**
   * Derives the read tool beside an operation's write tool.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The write tool's base definition.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   An operation that changes a thing that exists.
   */
  protected function read(ToolDefinition $base_plugin_definition, ResourceDefinition $resource, OperationDefinition $operation): void {
    $id = $resource->id . ':' . $operation->id . ResourceOperationReadTool::SUFFIX;
    try {
      $definition = $this->definition(
        $base_plugin_definition,
        $this->t('@label, read only', ['@label' => (string) $operation->label]),
        $this->operationInputs->readDescription($resource, $operation),
        $this->operationInputs->parameters($resource, $operation),
        $this->operationInputs->readOutputs($resource, $operation),
        $operation,
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
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation, whose permission the tool declares when it can.
   *
   * @return \Drupal\tool\Tool\ToolDefinition
   *   The definition.
   */
  protected function definition(ToolDefinition $base_plugin_definition, TranslatableMarkup $label, TranslatableMarkup $description, array $inputs, array $outputs, OperationDefinition $operation): ToolDefinition {
    $definition = (clone $base_plugin_definition)
      ->setLabel($label)
      ->setDescription($description)
      ->setPermission(static::declaredPermission($operation));
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
   * Says on a write tool how its operation behaves.
   *
   * The Tool API reads a tool's behavior from its operation kind and its
   * destructive flag, and the MCP bridge turns both into a tool's
   * annotations: read only from a kind that does not modify, idempotent
   * from the kind, destructive from the flag. A read only operation is the
   * Read kind, so it is read only and idempotent there; every other is the
   * Write kind, and says whether it may destroy what is there.
   *
   * Idempotence is the kind's, not the tool's: every kind that modifies
   * is not idempotent, so an operation that changes a thing that exists,
   * which is idempotent, is a Write all the same and reads as not
   * idempotent. The least wrong kind: every kind that is idempotent does
   * not modify, so it would also say the operation changes nothing, which
   * a client would act on. Both schema documents state the operation's
   * own answer.
   *
   * Decision: see docs/decisions.md#an-operation-states-how-it-behaves.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $definition
   *   The write tool's definition; changed in place.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   */
  public static function behaves(ToolDefinition $definition, OperationDefinition $operation): void {
    $definition
      ->setOperation($operation->readOnly ? ToolOperation::Read : ToolOperation::Write)
      ->setDestructive($operation->destructive);
  }

  /**
   * Answers the permission a tool can declare for an operation.
   *
   * The operation's static permission, the first static access answer
   * (OperationDefinition::staticPermission()), decided for the account
   * alone, which the Tool API checks before the tool's own access and
   * which a listing filters by, the way the schema route refuses an
   * account it is not open to. A `%key` placeholder names the subject's
   * permission, so it is runtime and declared nowhere; and the Tool API
   * reads a declared permission as a route's `_permission`, where a `,`
   * or a `+` would join names the operation means as one, so neither is
   * declared either.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return string|null
   *   The operation's permission as written, or NULL when it has none or
   *   it is not one fixed permission name.
   */
  public static function declaredPermission(OperationDefinition $operation): ?string {
    $permission = $operation->staticPermission();
    return $permission === NULL || strpbrk($permission, ',+') !== FALSE ? NULL : $permission;
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

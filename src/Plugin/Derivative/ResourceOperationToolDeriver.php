<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceCatalog;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\ResourceBuild\ResourcePlugins;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationReadTool;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationSchemaTool;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ToolDefinitionsInvalidator;
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
 * `surface:node.type:create`: the address the pattern gives an
 * operation.
 *
 * Two rules say which operations are not tools, and the catalog
 * applies the same ones (ResourceCatalog::standalone()). A resource or
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
 * Deriving is paid once per resource, not once per listing. Each
 * resource's tools are cached in an entry of their own in the discovery
 * bin, under the tags of what they were read from (the resource's
 * discovery entry and every build it was made from), each operation built
 * once for all its tools (OperationInputs::derivation()). The Tool API
 * keeps every tool as one item, which any of those tags clears
 * (ToolDefinitionsInvalidator); deriving again then reads every entry
 * whose tags did not fire and derives only the rest, inside core's lock,
 * so a burst of requests pays one rebuild and the others wait and read
 * what it wrote.
 *
 * A tool's title is its operation's label, and its description the
 * operation's description followed by one line of call rules for its
 * kind (OperationInputs::description()). No two tools share a title:
 * where operations of several resources share a label, each tool adds
 * its resource (title()); the read tool's is "Current values:" and the
 * write tool's.
 *
 * Each write tool says how its operation behaves (behaves()): the Read
 * kind for a read only operation, the Write kind for every other, and
 * whether it may destroy what is there.
 *
 * An operation that changes a thing that exists has a read tool beside
 * its write tool, `<resource id>:<operation id>:read`, of the class
 * ResourceOperationReadTool: its parameters as inputs, the stored values
 * as its output. Which operations those are is the catalog's to say
 * (ResourceCatalog::reads()), as which can be asked at all is.
 *
 * @see \Drupal\surface\Tool\OperationInputs
 * @see \Drupal\surface\Plugin\tool\Tool\ResourceOperationTool
 */
final class ResourceOperationToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ResourceOperationToolDeriver.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\Tool\OperationInputs $operationInputs
   *   What an operation's tool takes and answers with.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where a resource left out is reported.
   * @param \Drupal\surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure with #[UsesStructure].
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\surface\ResourceBuild\ResourceCatalog $catalog
   *   Which operations change a thing that exists, and so can be read.
   * @param \Drupal\surface\Tool\ToolDefinitionsInvalidator|null $invalidator
   *   What clears the definitions when what they were read from changes;
   *   NULL remembers nothing.
   * @param \Drupal\Core\Session\AccountSwitcherInterface|null $accountSwitcher
   *   What derives as the anonymous account; NULL derives as the current.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   Where each resource's tools are kept; NULL keeps none.
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock
   *   What makes concurrent derivations wait for one; NULL locks nothing.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly OperationInputs $operationInputs,
    protected readonly LoggerInterface $logger,
    protected readonly ResourcePlugins $plugins,
    TranslationInterface $string_translation,
    protected readonly ResourceCatalog $catalog,
    protected readonly ?ToolDefinitionsInvalidator $invalidator = NULL,
    protected readonly ?AccountSwitcherInterface $accountSwitcher = NULL,
    protected readonly ?CacheBackendInterface $cache = NULL,
    protected readonly ?LockBackendInterface $lock = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * The cache id prefix of one resource's tools in the discovery bin.
   */
  public const CACHE_PREFIX = 'surface:tool_definitions:';

  /**
   * The lock a derivation holds while it derives what was not cached.
   */
  public const LOCK = 'surface:derive_tools';

  /**
   * How long a derivation may hold the lock, in seconds.
   */
  protected const LOCK_TIMEOUT = 30.0;

  /**
   * Whether a resource being derived left an operation out.
   */
  protected bool $skipped = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('surface.resource_registry'),
      $container->get('surface.operation_inputs'),
      $container->get('logger.channel.surface'),
      $container->get('surface.resource_plugins'),
      $container->get('string_translation'),
      $container->get('surface.resource_catalog'),
      $container->get('surface.tool_definitions_invalidator'),
      $container->get('account_switcher'),
      $container->get('cache.discovery'),
      $container->get('lock'),
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
   * Each resource's tools come from its entry in the discovery bin when
   * the entry is there; the rest are derived under the lock, and written.
   * A request that finds the lock taken waits for it, reads what the
   * holder wrote, and derives only what is still missing.
   *
   * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The base definition.
   *
   * @return array<string, \Drupal\tool\Tool\ToolDefinition>
   *   The derived definitions, by derivative id.
   */
  protected function derive(ToolDefinition $base_plugin_definition): array {
    $this->derivatives = [];
    // A resource or structure with no storage of its own is stored by
    // its parent or by its host; one a plugin names with
    // #[UsesStructure] is configured through that plugin's host, which
    // holds the instance and supplies the storage. Neither is a tool of
    // its own, and neither is read from discovery's bin here.
    $standalone = [];
    foreach ($this->registry->getIndex() as $name => $indexed) {
      if ($indexed['stored'] && $this->plugins->usedBy($name) === []) {
        $standalone[$name] = $this->registry->getDefinition($name);
      }
    }
    $shared = $this->sharedLabels($standalone);
    $cids = [];
    foreach ($standalone as $name => $resource) {
      $cids[$name] = $this->cid($resource, $shared);
    }
    $entries = $this->cached($cids);
    $missing = array_diff_key($standalone, $entries);
    if ($missing !== []) {
      $locked = $this->lock?->acquire(self::LOCK, self::LOCK_TIMEOUT) ?? FALSE;
      if ($this->lock !== NULL && !$locked) {
        // Another request is deriving: wait for it, and read what it wrote.
        $this->lock->wait(self::LOCK, (int) self::LOCK_TIMEOUT);
        $entries += $this->cached(array_intersect_key($cids, $missing));
        $missing = array_diff_key($standalone, $entries);
      }
      try {
        $entries += $this->operationInputs->derivation(fn (): array => $this->deriveEach($base_plugin_definition, $missing, $shared, $cids));
      }
      finally {
        if ($locked) {
          $this->lock->release(self::LOCK);
        }
      }
    }
    // What the definitions are read from, beside what the Tool API keeps
    // them by: what decides which resources there are, then each
    // resource's own (its discovery entry and every build it was made
    // from, the keys a provider lists from the site among them).
    // Decision: see docs/decisions.md#keys-that-come-from-the-site.
    $tags = $this->registry->getIndexCacheTags();
    foreach (array_keys($standalone) as $name) {
      $this->derivatives += $entries[$name]['derivatives'];
      $tags = Cache::mergeTags($tags, $entries[$name]['tags']);
    }
    $this->invalidator?->remember($tags);
    return $this->derivatives;
  }

  /**
   * Reads the tools of some resources from the discovery bin.
   *
   * @param array<string, string> $cids
   *   The cache id of each resource's entry, by name.
   *
   * @return array<string, array{derivatives: array<string, \Drupal\tool\Tool\ToolDefinition>, tags: string[]}>
   *   The entries found, by name.
   */
  protected function cached(array $cids): array {
    if ($this->cache === NULL || $cids === []) {
      return [];
    }
    $asked = array_values($cids);
    $hits = $this->cache->getMultiple($asked);
    $found = [];
    foreach ($cids as $name => $cid) {
      if (isset($hits[$cid]) && is_array($hits[$cid]->data) && isset($hits[$cid]->data['derivatives'])) {
        $found[$name] = ['derivatives' => $hits[$cid]->data['derivatives'], 'tags' => $hits[$cid]->tags];
      }
    }
    return $found;
  }

  /**
   * Derives the tools of some resources, and writes each one's entry.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The base definition.
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   The resources, by name.
   * @param array<string, true> $shared
   *   The labels several tools would carry (sharedLabels()).
   * @param array<string, string> $cids
   *   The cache id of each resource's entry, by name.
   *
   * @return array<string, array{derivatives: array<string, \Drupal\tool\Tool\ToolDefinition>, tags: string[]}>
   *   Each resource's tools and the tags they were read from, by name.
   */
  protected function deriveEach(ToolDefinition $base_plugin_definition, array $resources, array $shared, array $cids): array {
    $derived = [];
    foreach ($resources as $name => $resource) {
      $this->operationInputs->takeListedCacheTags();
      [$derivatives, $skipped] = $this->deriveResource($base_plugin_definition, $resource, $shared);
      $tags = Cache::mergeTags($resource->cacheTags, $this->operationInputs->takeListedCacheTags());
      $derived[$name] = ['derivatives' => $derivatives, 'tags' => $tags];
      // An operation left out is logged each time it is derived, until the
      // mistake is fixed: not kept.
      if (!$skipped) {
        $this->cache?->set($cids[$name], ['derivatives' => $derivatives], Cache::PERMANENT, $tags);
      }
    }
    return $derived;
  }

  /**
   * Gets the cache id of one resource's tools.
   *
   * Its name, and which of its operations' labels other tools share, which
   * its titles say; what decides that is every other resource, so it is
   * part of the id rather than a tag.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param array<string, true> $shared
   *   The labels several tools would carry.
   *
   * @return string
   *   The cache id.
   */
  protected function cid(ResourceDefinition $resource, array $shared): string {
    $sharing = [];
    foreach ($resource->operations as $operation) {
      if (isset($shared[(string) $operation->label])) {
        $sharing[] = $operation->id;
      }
    }
    return self::CACHE_PREFIX . $resource->name() . ':' . hash('xxh3', serialize($sharing));
  }

  /**
   * Derives one resource's tools: a write tool per operation, and a read tool.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The base definition.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   A resource that names storage and no plugin uses.
   * @param array<string, true> $shared
   *   The labels several tools would carry (sharedLabels()).
   *
   * @return array{0: array<string, \Drupal\tool\Tool\ToolDefinition>, 1: bool}
   *   Its tools, by derivative id, and whether any operation was left out.
   */
  protected function deriveResource(ToolDefinition $base_plugin_definition, ResourceDefinition $resource, array $shared): array {
    $plugins = $this->plugins->usedBy($resource->name());
    $outer = $this->derivatives;
    $this->derivatives = [];
    $this->skipped = FALSE;
    try {
      try {
        $operations = $this->registry->getOperations($resource->name());
      }
      catch (\LogicException $e) {
        $this->skip($resource->id, $e);
        return [[], TRUE];
      }
      foreach ($operations as $operation) {
        if (!ResourceCatalog::standalone($resource, $operation, $plugins)) {
          continue;
        }
        $id = $resource->id . ':' . $operation->id;
        try {
          $title = $this->title($resource, $operation, $shared);
          $definition = $this->definition(
            $base_plugin_definition,
            $title,
            $this->operationInputs->description($resource, $operation),
            $this->operationInputs->inputs($resource, $operation),
            $this->operationInputs->outputs($resource, $operation),
            $operation,
          );
          static::behaves($definition, $operation);
          // Once the operation's parameters arrive, the values input is
          // the resource built in the operation's real context.
          $parameters = array_map(static fn ($parameter): string => $parameter->name, $operation->parameters);
          // A delete has no values input to refine.
          $values = $definition->getInputDefinitions(TRUE)[OperationInputs::VALUES] ?? NULL;
          $definition->setInputDefinitionRefiners($parameters === [] || $values === NULL ? [] : [OperationInputs::VALUES => $parameters]);
        }
        catch (\LogicException | \InvalidArgumentException $e) {
          $this->skip($id, $e);
          continue;
        }
        $this->derivatives[$id] = $definition;
        if ($this->catalog->reads($resource, $operation, $plugins)) {
          $this->read($base_plugin_definition, $resource, $operation, $title);
        }
        $this->narrowing($base_plugin_definition, $resource, $operation, $title);
      }
      return [$this->derivatives, $this->skipped];
    }
    finally {
      $this->derivatives = $outer;
    }
  }

  /**
   * Derives the read tool beside an operation's write tool.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The write tool's base definition.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   An operation that changes a thing that exists.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The write tool's title, which the read tool's names.
   */
  protected function read(ToolDefinition $base_plugin_definition, ResourceDefinition $resource, OperationDefinition $operation, TranslatableMarkup $title): void {
    $id = $resource->id . ':' . $operation->id . ResourceOperationReadTool::SUFFIX;
    try {
      $definition = $this->definition(
        $base_plugin_definition,
        $this->t('Current values: @label', ['@label' => $title]),
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
   * Derives the narrowing tool beside an operation's write tool, if any.
   *
   * Only where the operation's listing leaves a key's entries out
   * (OperationInputs::narrows()): a resource with no variants the site
   * lists, and every map of keys from the site spelled out, adds no tool.
   * Read only and idempotent, so the MCP bridge annotates it so.
   *
   * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
   *
   * @param \Drupal\tool\Tool\ToolDefinition $base_plugin_definition
   *   The write tool's base definition.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The write tool's title, which the narrowing tool's names.
   */
  protected function narrowing(ToolDefinition $base_plugin_definition, ResourceDefinition $resource, OperationDefinition $operation, TranslatableMarkup $title): void {
    $id = $resource->id . ':' . $operation->id . ResourceOperationSchemaTool::SUFFIX;
    try {
      if (!$this->operationInputs->narrows($resource, $operation)) {
        return;
      }
      $definition = $this->definition(
        $base_plugin_definition,
        $this->t('Schema for these values: @label', ['@label' => $title]),
        $this->operationInputs->schemaDescription($resource, $operation),
        $this->operationInputs->schemaInputs($resource, $operation),
        $this->operationInputs->schemaOutputs(),
        $operation,
      )
        ->setOperation(ToolOperation::Read)
        ->setDestructive(FALSE)
        ->setInputDefinitionRefiners([]);
      $definition->setClass(ResourceOperationSchemaTool::class);
    }
    catch (\LogicException | \InvalidArgumentException $e) {
      $this->skip($id, $e);
      return;
    }
    $this->derivatives[$id] = $definition;
  }

  /**
   * Lists the operation labels more than one tool would carry.
   *
   * A client lists tools by title, and an agent or a picker choosing
   * between them reads the title first, so two tools never share one:
   * the examples' three registration steps are each "Configure
   * registration" in their own module's words, and as tools each says
   * which resource it configures.
   *
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   The resources, by name; those that name no storage, or that a plugin
   *   uses, have no tool and are passed over.
   *
   * @return array<string, true>
   *   The labels, as rendered, that two or more tools would carry.
   */
  protected function sharedLabels(array $resources): array {
    $seen = [];
    foreach ($resources as $resource) {
      $plugins = $this->plugins->usedBy($resource->name());
      if ($resource->storage === NULL || $plugins !== []) {
        continue;
      }
      try {
        $operations = $this->registry->getOperations($resource->name());
      }
      catch (\LogicException) {
        continue;
      }
      foreach ($operations as $operation) {
        if (ResourceCatalog::standalone($resource, $operation, $plugins)) {
          $label = (string) $operation->label;
          $seen[$label] = isset($seen[$label]);
        }
      }
    }
    return array_filter($seen);
  }

  /**
   * Gets the tool's title: the operation's label, translated when read.
   *
   * The definition is cached for every language, so a translatable label
   * is handed over as itself, never rendered in the language that
   * discovered the tool. Where several tools would carry one label, each
   * adds the resource it is an operation of: its label, or its id where
   * it has none.
   *
   * Decision: see docs/decisions.md#a-static-tool-definition-is-no-accounts.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $resource
   *   The resource.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param array<string, true> $shared
   *   The labels several tools would carry (sharedLabels()).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The title.
   */
  protected function title(ResourceDefinition $resource, OperationDefinition $operation, array $shared): TranslatableMarkup {
    $label = OperationInputs::operationLabel($operation);
    if (isset($shared[(string) $operation->label])) {
      return $this->t('@label (@resource)', ['@label' => $label, '@resource' => $resource->label() ?? $resource->id]);
    }
    return $label instanceof TranslatableMarkup ? $label : $this->t('@label', ['@label' => $label]);
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
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
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
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
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
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
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
    $this->skipped = TRUE;
    $this->logger->warning('No tool for @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
  }

}

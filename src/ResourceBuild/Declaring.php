<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\Narrowing;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraint;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\AltersStorageLayoutInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\DefinesInputsInterface;
use Drupal\surface\Resource\DeletableStorageInterface;
use Drupal\surface\Resource\HeldToConfigSchemaInterface;
use Drupal\surface\Resource\ResourceStorageInterface;

/**
 * The first phase of the build: the blueprint filled from the class.
 *
 * Steps 1 to 4 of Resources: a resource's or structure's blueprints
 * filled from its class and from each alter that applies, every named
 * shape held to narrowing, the site's defaults written over the declared
 * ones, and the context's constraints and known identity applied. With
 * what each later phase asks of the declaration: the instance of a class
 * discovery names (an alter, a storage, an access class), the blueprints
 * of another resource read again, the context a child is invoked in
 * (invokeChild()), and the checks that hold a resource to the config
 * schema its storage writes.
 *
 * Whether the build under way is held to its config schema is set by
 * Resources (declaration(), settleVariant()).
 *
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 *
 * @internal
 *
 * @see \Drupal\surface\ResourceBuild\Resources
 */
final class Declaring {

  /**
   * Whether a resource is held to the config schema its storage writes.
   *
   * FALSE only while a schema is being generated from it
   * (Resources::declaration()).
   */
  public bool $holdsToSchema = TRUE;

  /**
   * The Choice option that says a Choice of one value fixes its key.
   *
   * Read by the build and taken off before the constraint is added, so
   * the Choice the key carries is core's own.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   */
  public const FIXED = 'surface_fixed';

  /**
   * The resources found no wider than their storage's schema.
   *
   * Keyed by the resource's name and the context's id.
   *
   * @var array<string, true>
   */
  protected array $heldToSchema = [];

  /**
   * What resolves a shape, with its alters, once asked for.
   */
  protected ?Shapes $shapes = NULL;

  /**
   * What finds the key a path names and holds a value to it, once asked.
   */
  protected ?KeyPaths $keyPaths = NULL;

  /**
   * Constructs the declaring phase.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions add() creates.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which returns an alter, a storage or an access
   *   class as the autowired service discovery registered.
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $watchedValueCheck
   *   What a watched value passes before a refiner is handed it.
   * @param \Drupal\surface\ResourceBuild\SiteDefaults|null $siteDefaults
   *   The defaults the site sets over the declared ones; NULL reads none.
   * @param \Drupal\surface\Reference\References|null $heldReferences
   *   What builds reference keys, where Resources::withRegistry() was
   *   handed one; NULL for the service.
   * @param \Drupal\surface\ResourceBuild\Resources $resources
   *   The build step, for the operation a child is invoked through and the
   *   structure an operation takes the keys of.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface|null $typedConfig
   *   The config schema, whose data types a key may be declared with
   *   (`machine_name`); NULL for none.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
    protected readonly ?SiteDefaults $siteDefaults,
    protected readonly ?References $heldReferences,
    protected readonly Resources $resources,
    protected readonly ?TypedConfigManagerInterface $typedConfig = NULL,
  ) {
  }

  /**
   * Refuses a resource wider than the config schema its storage writes.
   *
   * Asked of a storage that holds what it writes to a config schema
   * (HeldToConfigSchemaInterface), once per resource and context: a key
   * the schema types otherwise, says may not be null where the resource
   * lets it be cleared, or constrains further than the resource, would be
   * advertised by every door and then refused by storage.
   *
   * Decision: see
   * docs/decisions.md#a-config-object-is-described-by-its-resource.
   *
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   Where it was asked for.
   * @param \Drupal\surface\SurfaceInterface $built
   *   What was built.
   *
   * @throws \LogicException
   *   Naming each key the schema says less for.
   */
  public function assertHeldToSchema(string $resource, OperationContext $context, SurfaceInterface $built): void {
    if (!$this->holdsToSchema) {
      return;
    }
    $definition = $this->registry->getDefinition($resource);
    $memo = $definition->name() . ' ' . $context->id;
    // An operation's own values are not what the config schema describes:
    // they are a query or a list over the things, which storage reads.
    if ($definition->storage === NULL || isset($this->heldToSchema[$memo]) || $this->resources->shapeOf($definition, $context) !== $definition) {
      return;
    }
    $storage = $this->instance($definition->storage, ResourceStorageInterface::class);
    if ($storage instanceof HeldToConfigSchemaInterface) {
      $wider = $storage->widerThanSchema($built);
      if ($wider !== []) {
        $keys = [];
        foreach ($wider as $path => $why) {
          $keys[] = sprintf('"%s": %s', $path, $why);
        }
        throw new \LogicException(sprintf(
          'The %s resource advertises more than %s, the config schema %s holds what it stores to, so storage would refuse what every door offers. Where: %s. Narrow the resource to what the schema states; a schema generated from the resource is written again with drush surface:config-schema --write.',
          $definition->id,
          $storage->configSchemaType(),
          $definition->storage,
          implode('; ', $keys),
        ));
      }
    }
    $this->heldToSchema[$memo] = TRUE;
  }

  /**
   * Resolves how a child at a key is invoked, the same way at every use.
   *
   * In this order, the first that applies:
   * 1. The parent's context names one of the child's operations, by id,
   *    with withChild($key, operation: ...); or it hands the child a
   *    context whole with withChild($key, $context), which is taken as it
   *    is and invokes no operation of the child's, so no operation's
   *    permission is asked of it (its access class still is, by
   *    childAccess()).
   * 2. The child's operation whose id is the parent context's id.
   * 3. The child's operation with the standard id of the parent
   *    operation's kind (OperationDefinition::defaultIds()): `create` for
   *    a parent that creates, `update` (or `configure` for a child with no
   *    identity keys) for one that updates, `read` or `list` for one that
   *    reads, `delete` for one that deletes. A parent context that is no
   *    declared operation (a host's verb) is read by whether it creates.
   * 4. None: the child sees its parent's context id, `creates` and known
   *    identity, exactly as a child always did (childContext()).
   *
   * An operation invoked here is called with the arguments it declares,
   * read by name from the identity the parent handed it and then from
   * the parent's known identity; the context it returns then knows that
   * identity too, its own known keys winning on a clash. A context handed
   * whole is taken as it is. An operation found by the parent's id or by
   * its kind that cannot be given an argument it needs is passed over, for
   * the next step; one the parent named is refused.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param string $parent
   *   The parent's id, for messages.
   *
   * @return array{0: \Drupal\surface\Resource\OperationContext, 1: \Drupal\surface\ResourceBuild\OperationDefinition|null}
   *   The child's context, and the operation of its own it was invoked
   *   through, or NULL when it inherits.
   *
   * @throws \LogicException
   *   When the parent names an operation the child does not declare, or
   *   one that cannot be given an argument it needs.
   */
  public function invokeChild(ResourceDefinition $child, OperationContext $context, string $key, string $parent): array {
    // Decision: see docs/decisions.md#one-invocation-model-for-both-levels.
    $operations = $this->registry->getOperations($child->name());
    $named = $context->childOperation($key);
    if ($named !== NULL) {
      $operation = $operations[$named['operation']] ?? throw new \LogicException(sprintf(
        '%s, in its "%s" context, names the "%s" operation for its "%s" child, which %s does not declare; it declares %s.',
        $parent,
        $context->id,
        $named['operation'],
        $key,
        $child->id,
        $operations === [] ? 'none' : implode(', ', array_keys($operations)),
      ));
      $own = $this->childOperationContext($child, $operation, $context, $named['known'], $key, $parent);
      assert($own !== NULL);
      return [static::handDown($context, $key, $own), $operation];
    }
    $handed = $context->forChild($key);
    if ($handed !== $context) {
      // A context handed whole was built by the parent, which took
      // responsibility for entering it: it is taken as it is, and no
      // operation of the child's is invoked, so none of their permissions
      // is asked. The child's access class still answers.
      return [static::handDown($context, $key, $handed), NULL];
    }
    // The parent's id is a hint, and so is its kind: either is passed
    // over when the parent knows too little to invoke it, as a context
    // that knows nothing yet, which a static tool definition or a catalog
    // builds, does.
    $candidates = isset($operations[$context->id]) ? [$operations[$context->id]] : [];
    foreach (OperationDefinition::defaultIds($this->parentKind($parent, $context), $child->identity === []) as $id) {
      if (isset($operations[$id]) && $id !== $context->id) {
        $candidates[] = $operations[$id];
      }
    }
    foreach ($candidates as $operation) {
      $own = $this->childOperationContext($child, $operation, $context, [], $key, $parent, FALSE);
      if ($own !== NULL) {
        return [static::handDown($context, $key, $own), $operation];
      }
    }
    return [static::handDown($context, $key, Resources::childContext($context, $key)), NULL];
  }

  /**
   * Hands a child's context what its parent's says below the child's key.
   *
   * A constraint or a default the parent's context names by a path into
   * the structure attached at $key (`title.value`) is the child's to apply
   * in its own frame: its context says it, by the path below the key, so a
   * child is still narrowed by its own context alone.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\surface\Resource\OperationContext $parent
   *   The parent's context.
   * @param string $key
   *   The key the child sits at.
   * @param \Drupal\surface\Resource\OperationContext $child
   *   The child's context as invoked.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   The child's context, saying what the parent's said below the key.
   */
  protected static function handDown(OperationContext $parent, string $key, OperationContext $child): OperationContext {
    $prefix = $key . '.';
    foreach ($parent->constraints() as $path => $constraints) {
      if (str_starts_with((string) $path, $prefix)) {
        foreach ($constraints as $name => $options) {
          $child = $child->withConstraint(substr((string) $path, strlen($prefix)), (string) $name, $options);
        }
      }
    }
    $defaults = [];
    foreach ($parent->defaults() as $path => $value) {
      if (str_starts_with((string) $path, $prefix)) {
        $defaults[substr((string) $path, strlen($prefix))] = $value;
      }
    }
    return $defaults === [] ? $child : $child->withDefaults($defaults);
  }

  /**
   * Reads the kind of the operation a parent is built in.
   *
   * @param string $parent
   *   The parent's id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The parent's context.
   *
   * @return string
   *   The kind of the parent's operation of the context's id; for a
   *   context that is no declared operation of the parent's, create when
   *   it creates and update otherwise.
   */
  protected function parentKind(string $parent, OperationContext $context): string {
    $operation = $this->registry->hasDefinition($parent) ? ($this->registry->getOperations($parent)[$context->id] ?? NULL) : NULL;
    return $operation?->kind() ?? ($context->creates ? OperationDefinition::CREATE : OperationDefinition::UPDATE);
  }

  /**
   * Invokes one of a child's operations for its parent.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $child
   *   The child.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The child's operation.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The parent's context.
   * @param array<string, mixed> $handed
   *   The identity the parent handed the child with the operation's name.
   * @param string $key
   *   The key the child sits at, for messages.
   * @param string $parent
   *   The parent's id, for messages.
   * @param bool $named
   *   Whether the parent named the operation, so that one that cannot be
   *   invoked is a mistake rather than a step to pass over.
   *
   * @return \Drupal\surface\Resource\OperationContext|null
   *   The child's context, knowing what it was handed and what its parent
   *   knows, its own known keys first; NULL for an operation the parent did
   *   not name that cannot be given what it needs.
   *
   * @throws \LogicException
   *   When a named operation needs an argument nothing handed it.
   */
  protected function childOperationContext(ResourceDefinition $child, OperationDefinition $operation, OperationContext $context, array $handed, string $key, string $parent, bool $named = TRUE): ?OperationContext {
    $identity = $handed + $context->known;
    try {
      $own = $this->resources->operation($child->name(), $operation->id, $identity);
    }
    catch (\InvalidArgumentException $e) {
      if (!$named) {
        return NULL;
      }
      throw new \LogicException(sprintf(
        '%s invokes the "%s" operation of %s at its "%s" key, from its "%s" context, and cannot give it what it needs: %s Hand it with withChild(\'%s\', operation: \'%s\', known: [...]).',
        $parent,
        $operation->id,
        $child->id,
        $key,
        $context->id,
        $e->getMessage(),
        $key,
        $operation->id,
      ), 0, $e);
    }
    return $own->withKnown(array_diff_key($identity, $own->known));
  }

  /**
   * Lists what the build left out, which the parent stores.
   *
   * The keys the owner's blueprint attaches a child or a collection at, or
   * a structure it mixes in brought, that the built resource does not
   * carry: only access omits a key. Inside a child with no storage of its
   * own, which this storage stores, the same, by dotted path. The storage
   * keeps what it holds under each, since the caller could not see it.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource, or a child it stores.
   * @param \Drupal\surface\SurfaceInterface $built
   *   It as built.
   * @param string $prefix
   *   The child's path in the resource, with its trailing dot.
   *
   * @return string[]
   *   The omitted keys, by dotted path.
   */
  public function omittedKeys(ResourceDefinition $definition, SurfaceInterface $built, string $prefix = ''): array {
    $blueprint = $this->blueprint(new SurfaceBuilder(), $definition);
    $this->fillBlueprints($definition, $blueprint);
    // A mixed-in structure's members its access took back out, too.
    // Decision: see docs/decisions.md#a-mixin-is-merged-flat.
    $mixed = [];
    foreach ($blueprint->mixins() as ['blueprint' => $mixin]) {
      array_push($mixed, ...$mixin->keys());
    }
    $omitted = [];
    $children = [
      ...array_keys($blueprint->attachments()),
      ...array_keys($blueprint->lists()),
      ...array_keys($blueprint->fromSite()),
    ];
    foreach ([...$children, ...$mixed] as $key) {
      if ($built->getDefinitions()->entry((string) $key) === NULL) {
        $omitted[] = $prefix . $key;
      }
    }
    // And inside a child this storage stores, by its path.
    foreach ($built->getDefinitions()->entries() as $key => $entry) {
      $child = $entry->attachment?->source === NULL ? NULL : $this->registry->getDefinition((string) $entry->attachment->source);
      if ($child !== NULL && $child->storage === NULL) {
        array_push($omitted, ...$this->omittedKeys($child, $entry->attachment->child, $prefix . $key . '.'));
      }
    }
    return $omitted;
  }

  /**
   * Writes the defaults the site sets over a frame's declared ones.
   *
   * Every build carries the config object's cache tag, so a change to it
   * reaches every door at the next request. Read for what is built at the
   * top, by its id, or handed down by the parent for a child attached at a
   * fixed key; never while a config schema is written from the resource,
   * whose install file holds the module's defaults.
   *
   * Decision: see docs/decisions.md#a-site-sets-a-default-in-configuration.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The builder, its keys and its alters' declared.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   What is being built.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   Its blueprint, which says which keys hold no plain value.
   * @param bool $top
   *   Whether it is built at the top.
   * @param array{resource: string, at: string, values: array<string, mixed>}|null $siteDefaults
   *   What the parent handed down, or NULL.
   *
   * @return array<string, array{resource: string, at: string, values: array<string, mixed>}>
   *   The defaults for each child attached at a fixed key, by its key.
   */
  public function writeSiteDefaults(SurfaceBuilderInterface $builder, ResourceDefinition $definition, ResourceBlueprint $inputs, bool $top, ?array $siteDefaults): array {
    if ($this->siteDefaults === NULL || !$this->holdsToSchema) {
      return [];
    }
    $builder->addCacheableDependency($this->siteDefaults->cacheability());
    $defaults = $siteDefaults ?? ($top ? $this->siteDefaults->of($definition->id) : NULL);
    if ($defaults === NULL || $defaults['values'] === []) {
      return [];
    }
    return $this->siteDefaults->write($builder, $defaults, KeyPaths::kinds($inputs));
  }

  /**
   * Refuses a context that fixes or fills an identity key it knows.
   *
   * Each identity key the context knows is set as that key's default and
   * locked (applyContext()): which thing this is, is the operation's to
   * say, from its parameters. A fixed value or a default on the same key
   * would say it a second time, and nothing would refuse the two
   * disagreeing, so a route and a parent are refused alike. Asked before
   * either is written, so the refusal is this one whatever the value.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   What is being built.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context it is built in.
   * @param array<int, array<string, mixed>> $ancestry
   *   The parents it is built inside, outermost first, for messages.
   *
   * @throws \LogicException
   *   When the context fixes or fills an identity key it knows.
   */
  public function assertKnownIdentityKept(ResourceDefinition $definition, OperationContext $context, array $ancestry): void {
    $constraints = $context->constraints();
    $defaults = $context->defaults();
    foreach ($definition->identity as $key) {
      if (!array_key_exists($key, $context->known)) {
        continue;
      }
      $fixes = array_filter($constraints[$key] ?? [], static fn (mixed $options, string|int $name): bool => static::fixes((string) $name, $options), ARRAY_FILTER_USE_BOTH) !== [];
      if (!$fixes && !array_key_exists($key, $defaults)) {
        continue;
      }
      $where = static::contextWhere($definition, $context, $ancestry, $fixes ? 'fixes' : 'sets');
      throw new \LogicException(sprintf(
        '%s %s "%s" on %s, an identity key the context knows: identity is the operation\'s to say, from its parameters, so neither a route nor a parent may fix or fill it.',
        $where['source'],
        $where['verb'],
        $where['at'] . $key,
        $where['resource'],
      ));
    }
  }

  /**
   * Writes the defaults the context carries over the site's and the declared.
   *
   * Each is what its key starts from in this build: read through the
   * coercion table and held to the key, by the reach a site default has
   * (KeyPaths). A path into a structure attached at a fixed key is the
   * child's, handed to its context (handDown()). Only a context that
   * creates carries defaults: on an update the stored value stands.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The builder, its keys and its alters' declared, the site's defaults
   *   written.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   What is being built.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context it is built in.
   * @param array<string, string> $kinds
   *   Its keys that hold no plain value (KeyPaths::kinds()).
   * @param array<int, array<string, mixed>> $ancestry
   *   The parents it is built inside, outermost first, for messages.
   *
   * @throws \LogicException
   *   When the context does not create, or a path names no key a default
   *   reaches, or a value does not fit its key.
   */
  public function writeContextDefaults(SurfaceBuilderInterface $builder, ResourceDefinition $definition, OperationContext $context, array $kinds, array $ancestry): void {
    $defaults = $context->defaults();
    if ($defaults === []) {
      return;
    }
    $where = static::contextWhere($definition, $context, $ancestry, 'sets');
    if (!$context->creates) {
      throw new \LogicException(sprintf(
        '%s on %s does not create, and carries defaults for %s: a default is what a new thing starts from, and on an update the stored value stands.',
        $where['source'],
        $where['resource'],
        implode(', ', array_map(static fn (string|int $path): string => '"' . $where['at'] . $path . '"', array_keys($defaults))),
      ));
    }
    // What is below a child's key is handed to its context (handDown()).
    $this->keyPaths()->writeDefaults($builder, $where, $defaults, $kinds);
  }

  /**
   * Says where a context's value comes from, for its refusals.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   What is being built.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context it is built in.
   * @param array<int, array<string, mixed>> $ancestry
   *   The parents it is built inside, outermost first: the resource named
   *   is the outermost, and each one's key leads to this frame.
   * @param string $verb
   *   What the value does: `sets` for a default, `fixes` for a fixed
   *   value, `narrows` for any other constraint.
   *
   * @return array{resource: string, at: string, source: string, noun: string, verb: string}
   *   What KeyPaths names in a refusal.
   */
  protected static function contextWhere(ResourceDefinition $definition, OperationContext $context, array $ancestry, string $verb): array {
    return [
      'resource' => $ancestry === [] ? $definition->id : (string) $ancestry[0]['id'],
      'at' => $ancestry === [] ? '' : implode('.', array_map(static fn (array $level): string => (string) ($level['key'] ?? ''), $ancestry)) . '.',
      'source' => sprintf('The "%s" operation', $context->id),
      'noun' => match ($verb) {
        'sets' => 'default',
        'fixes' => 'fixed value',
        default => 'constraint',
      },
      'verb' => $verb,
    ];
  }

  /**
   * Answers whether a constraint fixes its key to one value.
   *
   * A Choice of one value that says so, with the FIXED option, as a
   * route's `_surface_fixed` does. A Choice of one value that does
   * not say so narrows as any constraint does and is drawn as a list of
   * one, as an operation's own (a placed block's theme on its update)
   * always was.
   *
   * @param string $name
   *   The constraint plugin ID.
   * @param mixed $options
   *   Its options.
   *
   * @return bool
   *   TRUE for a Choice of one value, not of a list's items, that fixes.
   */
  public static function fixes(string $name, mixed $options): bool {
    return $name === 'Choice'
      && is_array($options)
      && ($options[self::FIXED] ?? FALSE) === TRUE
      && is_array($options['choices'] ?? NULL)
      && count($options['choices']) === 1
      && empty($options['multiple']);
  }

  /**
   * Gets what finds the key a path names and holds a value to it.
   *
   * @return \Drupal\surface\ResourceBuild\KeyPaths
   *   The key paths.
   */
  protected function keyPaths(): KeyPaths {
    return $this->keyPaths ??= new KeyPaths($this->typedDataManager);
  }

  /**
   * Applies what a context says about where it is asked for.
   *
   * A constraint names its key by path, with the reach of a default
   * (KeyPaths): a path into a structure attached at a fixed key is the
   * child's, handed to its context (handDown()). A Choice of one value
   * with the FIXED option fixes its key: the value is read through the
   * coercion table and held to the key first, and the key holds it. It
   * starts from the value, whatever default it declares, and must hold
   * one, though it may hold nothing where nothing fixes it, so it is drawn
   * as the value and stated `const`.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The build: the builder holding the shape, the resource or
   *   structure, the context, the owner's input keys, its keys holding an
   *   attached child or variants, and its collection keys, which a context
   *   may narrow.
   * @param array<string, string> $kinds
   *   The owner's keys that hold no plain value (KeyPaths::kinds()).
   *
   * @throws \LogicException
   *   When the context names a key the shape does not declare, widens a
   *   key, or fixes one to a value it does not accept.
   */
  public function applyContext(Build $build, array $kinds = []): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $context = $build->context;
    $input_keys = $build->inputKeys;
    $attached = $build->attached;
    $lists = $build->lists;
    $assert_input = static function (string $key, string $what) use ($definition, $context, $attached, $lists, $kinds): void {
      if (in_array($key, $lists, TRUE)) {
        // The list key is the parent's, so where the parent is asked for
        // may narrow it (its Count).
        return;
      }
      if (in_array($key, $attached, TRUE)) {
        throw new \LogicException(sprintf(
          'The "%s" context gives a %s for "%s", which holds a child in %s: a child is narrowed by its own context, handed to it with withChild(\'%s\', ...)%s.',
          $context->id,
          $what,
          $key,
          $definition->id,
          $key,
          ($kinds[$key] ?? NULL) === 'child' ? sprintf(', or by a path to one of its keys, "%s.<key>"', $key) : '',
        ));
      }
    };

    foreach ($context->constraints() as $key => $constraints) {
      $key = (string) $key;
      $segments = explode('.', $key);
      if (count($segments) > 1 && ($kinds[$segments[0]] ?? NULL) === 'child') {
        // The child's, narrowed by its own context in its own frame.
        continue;
      }
      $fixing = array_filter($constraints, static fn (mixed $options, string|int $name): bool => static::fixes((string) $name, $options), ARRAY_FILTER_USE_BOTH) !== [];
      $where = static::contextWhere($definition, $context, $build->ancestry, $fixing ? 'fixes' : 'narrows');
      $ancestors = [];
      if (count($segments) > 1) {
        $this->keyPaths()->assertReached($where, $key, $kinds);
        [$target, $ancestors] = $this->keyPaths()->resolve($builder, $segments, $where, $key);
      }
      else {
        if (!in_array($key, $input_keys, TRUE) && !in_array($key, $attached, TRUE) && !in_array($key, $build->lists, TRUE)) {
          throw $this->keyPaths()->noKey($where, $key);
        }
        $assert_input($key, 'constraint');
        $target = $builder->getDefinition($key);
      }
      assert($target !== NULL);
      $before = Surface::deepClone($target);
      $fixed = [];
      foreach ($constraints as $name => $options) {
        if (static::fixes((string) $name, $options)) {
          // Decision: see
          // docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
          $options['choices'] = [$this->keyPaths()->held($before, reset($options['choices']), $where, $key)];
          $fixed = $options['choices'];
        }
        unset($options[self::FIXED]);
        $target->addConstraint((string) $name, $options);
      }
      Narrowing::assertNarrows($key, sprintf('the "%s" operation', $context->id), $before, $target);
      if ($fixed !== []) {
        // A fixed key holds its value: it starts from it, whether or not it
        // declares a default, and may not be left empty, so a key that may
        // hold nothing is made to hold one, and is drawn as the value and
        // stated `const` and required as a key that must hold one is.
        DefinitionMetadata::setFixed($target);
        if (!$target->isRequired()) {
          if (!method_exists($target, 'setRequired')) {
            throw new \LogicException(sprintf('The "%s" operation fixes "%s", a key that cannot be made to hold a value.', $context->id, $key));
          }
          $target->setRequired(TRUE);
        }
        $starts = DefinitionMetadata::hasDefaultValue($target) ? DefinitionMetadata::getDefaultValue($target) : NULL;
        if ($starts !== $fixed[0]) {
          $this->keyPaths()->setDefault($target, $ancestors, $fixed[0]);
        }
      }
    }

    // Decision: see docs/decisions.md#known-keys-that-are-not-identity.
    foreach ($definition->identity as $key) {
      if (array_key_exists($key, $context->known)) {
        $builder->setDefault($key, $context->known[$key]);
        $builder->lock($key);
      }
    }
    $this->holdIdentityOnce($build);
  }

  /**
   * Holds the identity a create names to one no thing holds yet.
   *
   * The stock SurfaceUnique constraint on the identity key, for an
   * operation that creates a thing of a resource whose storage can say
   * whether one is stored under an identity: one identity key, an input
   * the context does not know, and a storage implementing
   * DeletableStorageInterface. Nobody writes it on the key, and no
   * resource writes a uniqueness rule of its own.
   *
   * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The build.
   */
  protected function holdIdentityOnce(Build $build): void {
    $definition = $build->definition;
    $context = $build->context;
    if (!$context->creates || count($definition->identity) !== 1 || $definition->storage === NULL || !is_subclass_of($definition->storage, DeletableStorageInterface::class)) {
      return;
    }
    $key = (string) array_values($definition->identity)[0];
    $target = $build->builder->getDefinition($key);
    if ($target === NULL || array_key_exists($key, $context->known) || !in_array($key, $build->inputKeys, TRUE)) {
      return;
    }
    $storage = $this->instance($definition->storage, ResourceStorageInterface::class);
    assert($storage instanceof DeletableStorageInterface);
    $thing = $storage->singularLabel();
    $target->addConstraint('SurfaceUnique', [
      'storage' => $definition->storage,
      'key' => $key,
      'thing' => $thing,
      'reason' => UniqueConstraint::reason($thing),
    ]);
  }

  /**
   * Makes the blueprint a resource or structure declares into.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The builder it fills.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param bool $outputs
   *   TRUE for its outputs' blueprint.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint, which names a structure mixed in by discovery's id.
   */
  public function blueprint(SurfaceBuilderInterface $builder, ResourceDefinition $definition, bool $outputs = FALSE): ResourceBlueprint {
    return (new ResourceBlueprint($builder, $this->typedDataManager, $outputs, $this->registry, $definition->name()))->useShapes($this->shapes())->useReferences($this->references());
  }

  /**
   * Gets what resolves a shape, with its alters, for a blueprint.
   *
   * @return \Drupal\surface\ResourceBuild\Shapes
   *   The resolver.
   */
  public function shapes(): Shapes {
    return $this->shapes ??= new Shapes($this->registry, $this->classResolver, $this->typedConfig);
  }

  /**
   * Gets what builds a key that refers to an entity, for a blueprint.
   *
   * @return \Drupal\surface\Reference\References
   *   The service.
   */
  public function references(): References {
    if ($this->heldReferences !== NULL) {
      return $this->heldReferences;
    }
    $references = $this->classResolver->getInstanceFromDefinition('surface.references');
    assert($references instanceof References);
    return $references;
  }

  /**
   * Refuses a key that widens the shape it was declared with.
   *
   * What a key adds to a shape (required, a default, a constraint, a
   * description) is checked once its owner and every alter have declared,
   * by the narrowing check a refiner's answer is held to, against the
   * shape as its alters left it.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being built.
   * @param array<string, \Drupal\surface\ResourceBuild\BlueprintAdapterBase> $blueprints
   *   Its blueprints, each keyed by the prefix its keys are named with.
   *
   * @throws \LogicException
   *   Naming the key, the shape and what widened.
   */
  protected static function assertShapesNarrowed(ResourceDefinition $definition, array $blueprints): void {
    foreach ($blueprints as $prefix => $blueprint) {
      foreach ($blueprint->shapedKeys() as $key => $declared) {
        // A shape, or a config schema data type such as `machine_name`.
        // Decision: see
        // docs/decisions.md#a-machine-name-is-a-computed-identity.
        $shape = DefinitionMetadata::shapeDefinition($declared) ?? DefinitionMetadata::configTypeDefinition($declared);
        assert($shape !== NULL);
        $widened = Narrowing::narrowed($declared, $shape);
        if ($widened !== NULL) {
          throw new \LogicException(sprintf(
            '%s declares "%s" with the %s %s and widens it: %s. A key narrows the %s it uses, as a refiner narrows a key: declare a shape of its own, or narrow less.',
            $definition->id,
            $prefix . $key,
            DefinitionMetadata::shapeOf($declared) !== NULL ? 'shape' : 'config schema type',
            DefinitionMetadata::shapeOf($declared) ?? DefinitionMetadata::configTypeOf($declared),
            $widened,
            DefinitionMetadata::shapeOf($declared) !== NULL ? 'shape' : 'type',
          ));
        }
      }
    }
  }

  /**
   * Declares the owner's shape and its alters' additions into a builder.
   *
   * Steps 1 and 2 of the build: defineInputs() and defineOutputs() over
   * a fresh builder, then each alter that applies in this context, its
   * additions mounted under its module, its storage layout set; then each
   * named shape is held to narrowing, wherever it was used.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context it is built in, which decides the alters that apply.
   *
   * @return array{0: \Drupal\surface\SurfaceBuilder, 1: \Drupal\surface\ResourceBuild\ResourceBlueprint, 2: \Drupal\surface\ResourceBuild\ResourceBlueprint, 3: array<int, array{0: object|string, 1: \Drupal\surface\ResourceBuild\RefinerDefinition[], 2: string|null}>, 4: array<string, \Drupal\surface\ResourceBuild\ResourceBlueprintAdditions>}
   *   The builder; the inputs and the outputs blueprints; the refiner
   *   links, the owner's first, each its class or alter, its refiners and
   *   its module (NULL for the owner); and each alter's additions, by
   *   module.
   */
  public function declareShape(ResourceDefinition $definition, OperationContext $context): array {
    $builder = (new SurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $inputs = $this->blueprint($builder, $definition);
    $outputs = $this->blueprint($builder, $definition, TRUE);
    $this->fillBlueprints($definition, $inputs, $outputs);

    // The owner's own refiners first, one link per class they are on:
    // the owner's class itself, or the classes a deriver named.
    $links = [];
    foreach ($definition->refiners as $refiner) {
      $links[$refiner->class] ??= [$refiner->class, [], NULL];
      $links[$refiner->class][1][] = $refiner;
    }
    $links = array_values($links);
    $additions = [];
    // What each alter says of a key it does not own, by key and fact, so a
    // second alter saying the same fact is refused.
    // Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
    $described = new \ArrayObject();
    foreach ($definition->alters as $alter) {
      if (!$alter->appliesIn($context->id)) {
        continue;
      }
      $instance = $this->alter($alter->class);
      if ($instance instanceof AltersInputsInterface) {
        $blueprint = $additions[$alter->module] ??= (new ResourceBlueprintAdditions($builder, $this->typedDataManager, $alter->module, described: $described))->useShapes($this->shapes())->useReferences($this->references());
        PipelineGuard::onBehalf(PipelineGuard::ALTER, $alter->class, 'alterInputs', static fn () => $instance->alterInputs($blueprint));
      }
      if ($instance instanceof AltersStorageLayoutInterface) {
        $builder->setThirdPartyLayout($alter->module, $instance->storageLayout());
      }
      $links[] = [$instance, $alter->refiners, $alter->module];
    }
    $declaring = ['' => $inputs, 'output ' => $outputs];
    foreach ($additions as $module => $blueprint) {
      $declaring[sprintf('third_party_settings.%s.', $module)] = $blueprint;
    }
    static::assertShapesNarrowed($definition, $declaring);
    return [$builder, $inputs, $outputs, $links, $additions];
  }

  /**
   * Fills a blueprint, from its class or from what derived it.
   *
   * A class's defineInputs() and defineOutputs() are called on the
   * class; a derived resource's are the callables its deriver
   * named, handed the blueprint first.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The input blueprint.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint|null $outputs
   *   The output blueprint, or NULL when only the inputs are wanted.
   */
  public function fillBlueprints(ResourceDefinition $definition, ResourceBlueprint $inputs, ?ResourceBlueprint $outputs = NULL): void {
    if ($definition->isDerived()) {
      // Decision: see docs/decisions.md#a-derived-resource-has-no-class.
      $definition->inputs?->call($this->classResolver, $inputs);
      if ($outputs !== NULL) {
        $definition->outputs?->call($this->classResolver, $outputs);
      }
      return;
    }
    // Decision: see docs/decisions.md#a-resource-or-structure-is-static.
    $owner = static::resourceClass($definition);
    $owner::defineInputs($inputs);
    if ($outputs !== NULL && is_subclass_of($owner, DefinesOutputsInterface::class)) {
      $owner::defineOutputs($outputs);
    }
  }

  /**
   * Gets the class whose static methods describe a definition.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   *
   * @return class-string<\Drupal\surface\Resource\DefinesInputsInterface>
   *   Its class, which discovery held to DefinesInputsInterface.
   */
  public static function resourceClass(ResourceDefinition $definition): string {
    $class = $definition->class;
    assert(is_subclass_of($class, DefinesInputsInterface::class));
    return $class;
  }

  /**
   * Gets an instance of a class discovery named, of the expected kind.
   *
   * @param string $class
   *   The class.
   * @param class-string<T> $interface
   *   What it has to implement.
   * @param string|null $role
   *   What it runs as, for the guard (PipelineGuard::ALTER for an alter
   *   answering access); NULL reads it from the interface.
   *
   * @return T
   *   The instance.
   *
   * @template T of object
   *
   * @throws \LogicException
   *   When it does not implement the interface.
   */
  public function instance(string $class, string $interface, ?string $role = NULL): object {
    $instance = $this->classResolver->getInstanceFromDefinition($class);
    if (!$instance instanceof $interface) {
      throw new \LogicException(sprintf('%s is named as a %s but does not implement it.', $class, $interface));
    }
    // Decision: see
    // docs/decisions.md#nothing-an-operation-runs-reads-the-request.
    assert(PipelineGuard::holdsNothingDoorSpecific($instance, $role ?? match ($interface) {
      ResourceStorageInterface::class => PipelineGuard::STORAGE,
      OperationAccessInterface::class => PipelineGuard::ACCESS,
      default => PipelineGuard::PROVIDER,
    }));
    return $instance;
  }

  /**
   * Gets an alter, held to holding no door-specific service.
   *
   * @param class-string $class
   *   The alter's class.
   *
   * @return object
   *   The instance.
   */
  public function alter(string $class): object {
    $instance = $this->classResolver->getInstanceFromDefinition($class);
    assert(PipelineGuard::holdsNothingDoorSpecific($instance, PipelineGuard::ALTER));
    return $instance;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Collection;
use Drupal\surface\Computed;
use Drupal\surface\Contract\ContractBaselines;
use Drupal\surface\Contract\SiteListBudget;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Widget\SurfaceWidgetManager;

/**
 * The live map of a site: what discovery found, its doors, its refusals.
 *
 * What `drush surface:catalog` prints, and what docs/catalog.md is: every
 * resource and structure discovery finds on this site, from any module,
 * derived ones and read-back ones included, with per operation the
 * static layer's facts (ResourceCatalog::describe()) and the doors it
 * reaches, as each module that opens doors names them
 * (CatalogDoorsInterface); every build refusal discovery or a build
 * raised, and every route written wrong for the operation it serves (an
 * operation form's or a React page's a module serves), with its message
 * and the file it points at; and one resource in full, its keys as the
 * build seals them. Every build is for no account
 * (ResourcesInterface::buildDeclared()), asked as the anonymous one, as
 * the tool listing is, so the map is the same whoever asks.
 *
 * @see \Drupal\surface\Drush\Commands\CatalogCommand
 */
final class SiteCatalog {

  /**
   * The form route a module declares for the operation.
   */
  public const FORM = 'form';

  /**
   * The HTTP path the operation is run at, under contract 3.
   */
  public const HTTP = 'http';

  /**
   * The tool derived for the operation.
   */
  public const TOOL = 'tool';

  /**
   * The read tool derived beside it.
   */
  public const READ_TOOL = 'read tool';

  /**
   * The narrowing tool derived beside it, where its listing states a key open.
   */
  public const SCHEMA_TOOL = 'schema tool';

  /**
   * The OpenAPI operation that runs it.
   */
  public const OPENAPI = 'openapi';

  /**
   * The React page its form mounts on, and each one a module serves it at.
   */
  public const REACT = 'react';

  /**
   * Every door, in the order the catalog lists them.
   */
  public const DOORS = [
    self::FORM,
    self::HTTP,
    self::TOOL,
    self::READ_TOOL,
    self::SCHEMA_TOOL,
    self::OPENAPI,
    self::REACT,
  ];

  /**
   * A refusal discovery raised, before any one resource could be read.
   */
  public const DISCOVERY = 'discovery';

  /**
   * A refusal a build raised, of one resource or structure.
   */
  public const BUILD = 'build';

  /**
   * A refusal of a route, written wrong for the operation it serves.
   */
  public const ROUTE = 'route';

  /**
   * The catalog with doors, once read.
   *
   * @var array<string, array>|null
   */
  protected ?array $described = NULL;

  /**
   * The refusals, once read.
   *
   * @var list<array{resource: string|null, operation: string|null, stage: string, message: string, file: string|null}>|null
   */
  protected ?array $refusals = NULL;

  /**
   * Constructs a SiteCatalog.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceCatalog $catalog
   *   The static layer's catalog.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The account switcher, so every build is the anonymous account's.
   * @param iterable<\Drupal\surface\ResourceBuild\CatalogDoorsInterface> $doors
   *   What names the doors, one per module that opens some.
   * @param string $root
   *   The Drupal root, which a refusal's file is named from.
   * @param \Drupal\surface\Contract\SiteListBudget|null $budget
   *   How many variants of a list the site decides have their keys listed;
   *   without it, every one.
   * @param \Drupal\surface\Widget\SurfaceWidgetManager|null $widgets
   *   The widget manager, which names the widget each key is drawn by;
   *   without it, none is named.
   * @param \Drupal\surface\ResourceBuild\FormRouteDoors|null $forms
   *   The routes modules serve operations at, an operation form's or a
   *   React page's; with the two below, each is judged for the operation
   *   it serves. Without them, none is.
   * @param \Drupal\surface\ResourceBuild\OperationArguments|null $arguments
   *   What maps a route's parameters onto the operation's.
   * @param \Drupal\surface\ResourceBuild\KeyPaths|null $keyPaths
   *   What finds the key a path names on a built resource.
   */
  public function __construct(
    protected readonly ResourceCatalog $catalog,
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourcePlugins $plugins,
    protected readonly AccountSwitcherInterface $switcher,
    protected readonly iterable $doors,
    protected readonly string $root,
    protected readonly ?SiteListBudget $budget = NULL,
    protected readonly ?SurfaceWidgetManager $widgets = NULL,
    protected readonly ?FormRouteDoors $forms = NULL,
    protected readonly ?OperationArguments $arguments = NULL,
    protected readonly ?KeyPaths $keyPaths = NULL,
  ) {}

  /**
   * Describes every resource and structure, with the doors it reaches.
   *
   * @return array<string, array>
   *   What ResourceCatalog::describe() answers, each with `read` NULL and
   *   each operation with `doors` beside: what each door is, by its name,
   *   in DOORS order. After them, by the name each is built by, every
   *   structure a build read from a schema document (a variant a provider
   *   lists, a def), `read` that name and `class` NULL. Empty when
   *   discovery itself was refused (refused() says why).
   */
  public function describe(): array {
    if ($this->described !== NULL) {
      return $this->described;
    }
    try {
      $catalog = $this->catalog->describe();
    }
    catch (\LogicException) {
      return $this->described = [];
    }
    foreach ($catalog as $id => $entry) {
      $definition = $this->registry->getDefinition((string) ($entry['class'] ?? $id));
      $catalog[$id]['read'] = NULL;
      foreach ($entry['operations'] as $delta => $operation) {
        $catalog[$id]['operations'][$delta]['doors'] = $this->doorsOf($definition, $definition->operations[$delta], $operation['standalone'], $operation['reads']);
      }
    }
    // What a schema document supplies is registered when a build reads
    // it: build everything first.
    $this->refused();
    foreach ($this->registry->getReadDefinitions() as $name => $definition) {
      $catalog[$name] = [
        'id' => $definition->id,
        'label' => ResourceCatalog::text($definition->label()),
        'description' => ResourceCatalog::text($definition->description()),
        'class' => NULL,
        'deriver' => NULL,
        'read' => $name,
        'module' => $definition->module,
        'kind' => $definition->kind,
        'identity' => [],
        'storage' => NULL,
        'access' => NULL,
        'plugins' => [],
        'operations' => [],
        'alters' => [],
        'variants' => [],
      ];
    }
    return $this->described = $catalog;
  }

  /**
   * Describes every deriver, as ResourceCatalog::derivers() does.
   *
   * @return array<string, array>
   *   The derivers, by base id; empty when discovery was refused.
   */
  public function derivers(): array {
    try {
      return $this->catalog->derivers();
    }
    catch (\LogicException) {
      return [];
    }
  }

  /**
   * Builds every operation of every declaration, and lists what failed.
   *
   * Each operation is built in its own context when it needs nothing,
   * and in a context of its id alone when it needs a subject, as its tool
   * listing is; one with no operation is built as a host builds it
   * (ContractBaselines::ALONE). What discovery refuses is one refusal of
   * the whole site, since nothing can be read past it. Each route serving
   * an operation that builds, a form's or a React page's, is judged beside
   * it (routeRefusals()).
   *
   * @return list<array{resource: string|null, operation: string|null, stage: string, message: string, file: string|null}>
   *   Each refusal: the resource or structure and the operation it was
   *   raised for (NULL where it was the whole, or discovery), the stage
   *   (DISCOVERY, BUILD or ROUTE), the message, which names the route for
   *   a route's, and the file it points at, from the Drupal root: the
   *   first class the message names, else the resource's own class.
   */
  public function refused(): array {
    if ($this->refusals !== NULL) {
      return $this->refusals;
    }
    $refusals = [];
    try {
      $definitions = $this->registry->getDefinitions();
    }
    catch (\LogicException $e) {
      return $this->refusals = [$this->refusal(NULL, NULL, self::DISCOVERY, $e)];
    }
    $this->asAnonymous(function () use ($definitions, &$refusals): void {
      foreach ($definitions as $name => $definition) {
        $name = (string) $name;
        try {
          $operations = $this->registry->getOperations($name);
        }
        catch (\Throwable $e) {
          $refusals[] = $this->refusal($definition, NULL, self::BUILD, $e);
          continue;
        }
        if ($operations === []) {
          try {
            static::settle($this->resources->buildDeclared($name, new OperationContext(ContractBaselines::ALONE)));
          }
          catch (\Throwable $e) {
            $refusals[] = $this->refusal($definition, NULL, self::BUILD, $e);
          }
          continue;
        }
        foreach ($operations as $operation) {
          try {
            $context = $this->context($name, $operation);
            $built = $this->resources->buildDeclared($name, $context);
            static::settle($built);
          }
          catch (\Throwable $e) {
            $refusals[] = $this->refusal($definition, $operation->id, self::BUILD, $e);
            continue;
          }
          foreach ($this->routeRefusals($definition, $operation, $context, $built) as $e) {
            $refusals[] = $this->refusal($definition, $operation->id, self::ROUTE, $e);
          }
        }
      }
    });
    return $this->refusals = $refusals;
  }

  /**
   * Judges what each route serving an operation says of its keys.
   *
   * A route whose path has no variable, and which fixes or fills keys or
   * pins a parameter, is read as its access check reads it
   * (OperationRoute::fromRouteMatch()), its plain defaults as core hands
   * them: its literal values held once, by the declared build the bin
   * keeps for the next request to read, and a refusal is the route's, as
   * every visitor would hear it. A route with a variable is the URL's to
   * finish, so it is listed and not held. Each key a route lets a link
   * start is found on the operation's own declared build
   * (KeyPaths::reachedOn()), whatever the route's path holds.
   *
   * Decision: see
   * docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   One of its operations.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context the catalog built it in.
   * @param \Drupal\surface\SurfaceInterface $built
   *   The build, for no account.
   *
   * @return list<\Throwable>
   *   What refused each route written wrong, one per route, its message
   *   naming the route.
   */
  protected function routeRefusals(ResourceDefinition $definition, OperationDefinition $operation, OperationContext $context, SurfaceInterface $built): array {
    if ($this->forms === NULL || $this->arguments === NULL || $this->keyPaths === NULL) {
      return [];
    }
    $refusals = [];
    foreach ($this->forms->served($definition, $operation) as $name => [$route]) {
      $plain = array_filter($route->getDefaults(), static fn (string|int $default): bool => !str_starts_with((string) $default, '_'), ARRAY_FILTER_USE_KEY);
      try {
        $served = NULL;
        $seeded = array_filter(
          [$route->getDefault(OperationRoute::FIXED), $route->getDefault(OperationRoute::DEFAULTS)],
          static fn (mixed $map): bool => !in_array($map, [NULL, []], TRUE),
        ) !== [];
        if ($route->compile()->getVariables() === [] && ($seeded || $plain !== [])) {
          $served = OperationRoute::fromRouteMatch(new RouteMatch($name, $route, $plain, $plain), $this->registry, $this->arguments, $this->resources);
        }
        $query = OperationRoute::query($route, $name);
        $known = array_map('strval', array_keys(($served->context ?? $context)->known));
        $where = [
          'resource' => $definition->id,
          'at' => '',
          'source' => sprintf('The %s route\'s "%s"', $name, OperationRoute::QUERY),
          'noun' => 'prefill',
          'verb' => 'starts',
        ];
        foreach (array_keys($query) as $path) {
          $this->keyPaths->reachedOn($built, (string) $path, $where, array_values(array_intersect($definition->identity, $known)));
        }
      }
      catch (\Throwable $e) {
        // Every refusal names the route, as the access check's does.
        $refusals[] = str_starts_with($e->getMessage(), sprintf('The %s route', $name))
          ? $e
          : new \InvalidArgumentException(sprintf('The %s route: %s', $name, $e->getMessage()), 0, $e);
      }
    }
    return $refusals;
  }

  /**
   * Builds every child a build left to be built when read, at any depth.
   *
   * A variant the site lists is built when a door reads it; the catalog
   * reads every one, so a refusal inside one is listed under the
   * operation that holds it, and every structure it reads from a schema
   * is registered and listed, as when the build built them all.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The build.
   * @param array<int, true> $seen
   *   The frames read, by object id.
   */
  protected static function settle(SurfaceInterface $surface, array &$seen = []): void {
    if (isset($seen[spl_object_id($surface)])) {
      return;
    }
    $seen[spl_object_id($surface)] = TRUE;
    foreach ($surface->getDefinitions()->entries() as $entry) {
      $children = [
        ...($entry->attachment === NULL ? [] : [$entry->attachment]),
        ...array_values($entry->mounted),
        ...array_values($entry->variants->variants ?? []),
        ...($entry->list === NULL ? [] : [$entry->list->item]),
      ];
      foreach ($children as $attachment) {
        static::settle($attachment->child, $seen);
      }
    }
    foreach ($surface->getDefinitions()->mixins() as $mixin) {
      static::settle($mixin->structure->child, $seen);
    }
  }

  /**
   * Describes one resource or structure in full.
   *
   * @param string $resource
   *   Its id, or its class.
   *
   * @return array
   *   Its catalog entry (describe()), and: `built`, the context it was
   *   built in to read its keys; `answers`, its access class, the alters
   *   that answer access and the kind of the answer at the top (`none`,
   *   the operation's permission alone; `static`; or `runtime`); `keys`,
   *   every key as the build seals it, a child's, a variant's and an
   *   item's by their dotted path (each with its type, label, required,
   *   default, constraints, locked, secret, computed, the refiners on it,
   *   the keys its declared rules read, the keys it watches, whether a
   *   runtime refiner narrows it, who added it, and the child, variants
   *   or items at it); and `refused`, its refusals (refused()).
   *
   * @throws \InvalidArgumentException
   *   When discovery found nothing by that name.
   */
  public function resource(string $resource): array {
    // A structure read from a schema is known once a build read it.
    $this->refused();
    $definition = $this->registry->getDefinition($resource);
    $name = $definition->name();
    $entry = $this->describe()[$definition->readAs ?? $definition->id] ?? [];
    $refused = array_values(array_filter($this->refused(), static fn (array $refusal): bool => $refusal['resource'] === $definition->id));
    $operations = $this->registry->getOperations($name);
    $first = NULL;
    foreach ($operations as $operation) {
      if ($operation->needsNothing()) {
        $first = $operation;
        break;
      }
    }
    $first ??= $operations === [] ? NULL : reset($operations);
    $built = NULL;
    $context = NULL;
    $this->asAnonymous(function () use ($name, $first, &$built, &$context): void {
      try {
        $context = $first === NULL ? new OperationContext(ContractBaselines::ALONE) : $this->context($name, $first);
        $built = $this->resources->buildDeclared($name, $context);
      }
      catch (\Throwable) {
        // Listed under refused, with the rest.
      }
    });
    $kind = 'none';
    if ($definition->answersAccess() && $context instanceof OperationContext) {
      $kind = $this->resources->isRuntimeAtTheTop($name, $context, new AnonymousUserSession()) ? 'runtime' : 'static';
    }
    return $entry + [
      'built' => $context?->id,
      'answers' => [
        'class' => $definition->access,
        'alters' => array_values(array_map(static fn (AlterDefinition $alter): string => $alter->class, array_filter($definition->alters, static fn (AlterDefinition $alter): bool => $alter->answersAccess))),
        'kind' => $kind,
      ],
      'keys' => $built === NULL ? [] : $this->keys($built, $name, ''),
      'refused' => $refused,
    ];
  }

  /**
   * Gives the URL of each door of one operation that has one.
   *
   * Asked of every door provider that links its doors
   * (CatalogDoorLinksInterface), for the catalog page; a door none links
   * is shown as text.
   *
   * @param string $resource
   *   The resource's id, as describe() keys it.
   * @param string $operation
   *   The operation's id.
   *
   * @return array<string, \Drupal\Core\Url>
   *   By door name, in DOORS order.
   */
  public function links(string $resource, string $operation): array {
    $entry = $this->describe()[$resource] ?? NULL;
    if ($entry === NULL) {
      return [];
    }
    $definition = $this->registry->getDefinition((string) ($entry['class'] ?? $resource));
    $linked = [];
    foreach ($entry['operations'] as $delta => $described) {
      if ($described['id'] !== $operation) {
        continue;
      }
      foreach ($this->doors as $doors) {
        if (!$doors instanceof CatalogDoorLinksInterface) {
          continue;
        }
        try {
          $linked += $doors->links($definition, $definition->operations[$delta], $described['standalone'], $described['reads']);
        }
        catch (\Throwable) {
          // A door with no URL here is shown as text.
        }
      }
    }
    return array_intersect_key(array_replace(array_flip(self::DOORS), $linked), $linked);
  }

  /**
   * Renders the catalog as markdown: what docs/catalog.md is.
   *
   * @return string
   *   The document.
   */
  public function markdown(): string {
    return CatalogDocument::render($this->describe(), $this->derivers(), $this->refused());
  }

  /**
   * Asks every door provider about one operation.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   * @param bool $standalone
   *   Whether it can be asked on its own.
   * @param bool $reads
   *   Whether what it changes can be read on its own.
   *
   * @return array<string, string>
   *   What each door is, by name, in DOORS order.
   */
  protected function doorsOf(ResourceDefinition $definition, OperationDefinition $operation, bool $standalone, bool $reads): array {
    $named = [];
    foreach ($this->doors as $doors) {
      try {
        $named += $doors->doors($definition, $operation, $standalone, $reads);
      }
      catch (\Throwable) {
        // A door that cannot be named for it is not reached; the
        // refusal, if a build raised one, is listed by refused().
      }
    }
    return array_intersect_key(array_replace(array_flip(self::DOORS), $named), $named);
  }

  /**
   * Gives the context an operation is built in for the catalog.
   *
   * @param string $name
   *   The resource's name.
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\surface\Resource\OperationContext
   *   Its own context when it needs nothing; else one of its id alone,
   *   its subject unknown.
   */
  protected function context(string $name, OperationDefinition $operation): OperationContext {
    return $operation->needsNothing() ? $this->resources->operation($name, $operation->id) : new OperationContext($operation->id);
  }

  /**
   * Runs builds as the anonymous account, so they are no account's.
   *
   * @param callable(): void $run
   *   What builds.
   */
  protected function asAnonymous(callable $run): void {
    $this->switcher->switchTo(new AnonymousUserSession());
    try {
      $run();
    }
    finally {
      $this->switcher->switchBack();
    }
  }

  /**
   * Writes one refusal.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition|null $definition
   *   What it was raised for, or NULL for discovery.
   * @param string|null $operation
   *   The operation, or NULL for the whole.
   * @param string $stage
   *   DISCOVERY, BUILD or ROUTE.
   * @param \Throwable $e
   *   What was raised.
   *
   * @return array{resource: string|null, operation: string|null, stage: string, message: string, file: string|null}
   *   The refusal.
   */
  protected function refusal(?ResourceDefinition $definition, ?string $operation, string $stage, \Throwable $e): array {
    $message = $e->getMessage();
    $file = NULL;
    if (preg_match_all('/(?<![\w\\\\])(?:[A-Z]\w*\\\\)+[A-Z]\w*/', $message, $matches)) {
      foreach ($matches[0] as $class) {
        if (class_exists($class) || interface_exists($class)) {
          $file = (new \ReflectionClass($class))->getFileName() ?: NULL;
          break;
        }
      }
    }
    if ($file === NULL && $definition !== NULL) {
      $class = $definition->class ?? $definition->deriver;
      $file = $class !== NULL && class_exists($class) ? ((new \ReflectionClass($class))->getFileName() ?: NULL) : NULL;
    }
    if ($file !== NULL && str_starts_with($file, $this->root . '/')) {
      $file = substr($file, strlen($this->root) + 1);
    }
    return [
      'resource' => $definition?->id,
      'operation' => $operation,
      'stage' => $stage,
      'message' => $message,
      'file' => $file,
    ];
  }

  /**
   * Lists the keys of a built resource or structure, its parts' too.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource or structure.
   * @param string|null $source
   *   The name it was built from, for its refiners; NULL for none.
   * @param string $prefix
   *   The dotted path its keys sit under.
   * @param string|null $within
   *   For a variant's keys, the value of the deciding key that chooses it.
   *
   * @return list<array>
   *   The keys, in declaration order, each part's after the key holding it.
   */
  protected function keys(SurfaceInterface $surface, ?string $source, string $prefix, ?string $within = NULL): array {
    $refiners = [];
    if ($source !== NULL && $this->registry->hasDefinition($source)) {
      $owner = $this->registry->getDefinition($source);
      $lists = [
        $owner->refiners,
        ...array_map(static fn (AlterDefinition $alter): array => $alter->refiners, $owner->alters),
      ];
      foreach ($lists as $list) {
        foreach ($list as $refiner) {
          $refiners[$refiner->key][] = $refiner->describe() . ($refiner->watched() === [] ? '' : ' watching ' . implode(', ', $refiner->watched())) . ($refiner->runtime ? ', at runtime' : '');
        }
      }
    }
    $keys = [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $path = $prefix . $name;
      $definition = $entry->definition;
      $computed = Computed::of($definition);
      // Variants the site lists past the budget are listed by value, and
      // their keys not walked: one structure a variant, at every depth, is
      // the product of the lists, which no page or command can hold.
      // Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
      $open = $entry->variants !== NULL && ($this->budget?->variantsOver($entry->variants) ?? FALSE);
      $type = match (TRUE) {
        $entry->attachment !== NULL => 'child',
        $entry->variants !== NULL => 'variants',
        $entry->list !== NULL => 'list',
        default => $definition->getDataType(),
      };
      $keys[] = [
        'key' => $path,
        'variant' => $within,
        'type' => $type,
        'label' => (string) $definition->getLabel(),
        'required' => $definition->isRequired(),
        'default' => static::plain($surface->getDefault($name)),
        // The two a type and a required key add are said by those.
        'constraints' => static::plain(array_diff_key(
          ($entry->variants->shell ?? $definition)->getConstraints(),
          ['PrimitiveType' => TRUE, 'NotNull' => TRUE],
        )),
        'locked' => $entry->locked,
        'secret' => DefinitionMetadata::isSecret($definition),
        'computed' => $computed === NULL ? NULL : [
          'from' => $computed->from,
          'by' => $computed->using,
          'when' => $computed->when,
        ],
        'refiners' => $refiners[$name] ?? [],
        'rules' => $entry->declaredDependencies(),
        'watches' => array_values(array_map('strval', $entry->dependencies)),
        'runtime' => $entry->isRefinedAtRuntime(),
        'contributor' => $entry->contributor === SurfaceInterface::OWNER ? NULL : $entry->contributor,
        'child' => $entry->attachment->id ?? $entry->attachment?->source,
        'variants' => $entry->variants === NULL ? NULL : [
          'by' => $entry->variants->by,
          'values' => array_map(static fn ($variant): ?string => $variant->id ?? $variant->source, $entry->variants->variants),
          'listed' => !$open,
        ],
        'items' => $entry->list === NULL ? NULL : ($entry->list->item->id ?? $entry->list->item->source),
        // A list of maps is drawn as a collection, which no widget is.
        'widget' => $entry->attachment === NULL && $entry->variants === NULL && $entry->list === NULL && !Collection::holdsMaps($definition) ? $this->widget($definition) : NULL,
      ];
      if ($entry->attachment !== NULL) {
        array_push($keys, ...$this->keys($entry->attachment->child, $entry->attachment->source, $path . '.', $within));
      }
      foreach ($open ? [] : $entry->variants->variants ?? [] as $value => $variant) {
        array_push($keys, ...$this->keys($variant->child, $variant->source, $path . '.', $entry->variants->by . ' = ' . $value));
      }
      if ($entry->list !== NULL) {
        array_push($keys, ...$this->keys($entry->list->child(), $entry->list->item->source, $path . '.*.', $within));
      }
    }
    return $keys;
  }

  /**
   * Names the widget a key is drawn by, as the form chooses it.
   *
   * A module's widget that claims the key is named as the stock ones are,
   * by the word it answers to.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition, as built.
   *
   * @return string|null
   *   The word, or NULL when no widget draws the key.
   */
  protected function widget(DataDefinitionInterface $definition): ?string {
    if ($this->widgets === NULL) {
      return NULL;
    }
    try {
      return SurfaceWidgetManager::wordOf($this->widgets->getWidgetFor($definition));
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Writes a value as plain data: markup as text, an object by its class.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   Scalars, NULL and arrays of them.
   */
  protected static function plain(mixed $value): mixed {
    return match (TRUE) {
      is_array($value) => array_map(static::plain(...), $value),
      $value instanceof \Stringable => (string) $value,
      is_object($value) => get_class($value),
      default => $value,
    };
  }

}

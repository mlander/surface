<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\Computed;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\OperationContext;

/**
 * The live map of a site: what discovery found, its doors, its refusals.
 *
 * What `drush surface:catalog` prints, and what docs/catalog.md is: every
 * resource and structure discovery finds on this site, from any module,
 * derived ones and read-back ones included, with per operation the
 * static layer's facts (ResourceCatalog::describe()) and the doors it
 * reaches, as each module that opens doors names them
 * (CatalogDoorsInterface); every build refusal discovery or a build
 * raised, with its message and the file it points at; and one resource
 * in full, its keys as the build seals them. Every build is for no
 * account (ResourcesInterface::buildDeclared()), asked as the anonymous
 * one, as the tool listing is, so the map is the same whoever asks.
 *
 * @see \Drupal\data_surface\Drush\Commands\CatalogCommand
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
   * The OpenAPI operation that runs it.
   */
  public const OPENAPI = 'openapi';

  /**
   * The React page its form mounts on.
   */
  public const REACT = 'react';

  /**
   * Every door, in the order the catalog lists them.
   */
  public const DOORS = [self::FORM, self::HTTP, self::TOOL, self::READ_TOOL, self::OPENAPI, self::REACT];

  /**
   * A refusal discovery raised, before any one resource could be read.
   */
  public const DISCOVERY = 'discovery';

  /**
   * A refusal a build raised, of one resource or structure.
   */
  public const BUILD = 'build';

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
   * @param \Drupal\data_surface\ResourceBuild\ResourceCatalog $catalog
   *   The static layer's catalog.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\data_surface\ResourceBuild\ResourcePlugins $plugins
   *   Which plugins name which structure.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $switcher
   *   The account switcher, so every build is the anonymous account's.
   * @param iterable<\Drupal\data_surface\ResourceBuild\CatalogDoorsInterface> $doors
   *   What names the doors, one per module that opens some.
   * @param string $root
   *   The Drupal root, which a refusal's file is named from.
   */
  public function __construct(
    protected readonly ResourceCatalog $catalog,
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ResourcePlugins $plugins,
    protected readonly AccountSwitcherInterface $switcher,
    protected readonly iterable $doors,
    protected readonly string $root,
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
        'derived' => [],
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
   * the whole site, since nothing can be read past it.
   *
   * @return list<array{resource: string|null, operation: string|null, stage: string, message: string, file: string|null}>
   *   Each refusal: the resource or structure and the operation it was
   *   raised for (NULL where it was the whole, or discovery), the stage
   *   (DISCOVERY or BUILD), the message, and the file it points at, from
   *   the Drupal root: the first class the message names, else the
   *   resource's own class.
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
            $this->resources->buildDeclared($name, new OperationContext(ContractBaselines::ALONE));
          }
          catch (\Throwable $e) {
            $refusals[] = $this->refusal($definition, NULL, self::BUILD, $e);
          }
          continue;
        }
        foreach ($operations as $operation) {
          try {
            $this->resources->buildDeclared($name, $this->context($name, $operation));
          }
          catch (\Throwable $e) {
            $refusals[] = $this->refusal($definition, $operation->id, self::BUILD, $e);
          }
        }
      }
    });
    return $this->refusals = $refusals;
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure.
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
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
   * @param \Drupal\data_surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
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
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition|null $definition
   *   What it was raised for, or NULL for discovery.
   * @param string|null $operation
   *   The operation, or NULL for the whole.
   * @param string $stage
   *   DISCOVERY or BUILD.
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
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
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
  protected function keys(DataSurfaceInterface $surface, ?string $source, string $prefix, ?string $within = NULL): array {
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
          'by' => $computed->deriver,
          'when' => $computed->when,
        ],
        'refiners' => $refiners[$name] ?? [],
        'rules' => $entry->declaredDependencies(),
        'watches' => array_values(array_map('strval', $entry->dependencies)),
        'runtime' => $entry->isRefinedAtRuntime(),
        'contributor' => $entry->contributor === DataSurfaceInterface::OWNER ? NULL : $entry->contributor,
        'child' => $entry->attachment->id ?? $entry->attachment?->source,
        'variants' => $entry->variants === NULL ? NULL : [
          'by' => $entry->variants->by,
          'values' => array_map(static fn ($variant): ?string => $variant->id ?? $variant->source, $entry->variants->variants),
        ],
        'items' => $entry->list === NULL ? NULL : ($entry->list->item->id ?? $entry->list->item->source),
      ];
      if ($entry->attachment !== NULL) {
        array_push($keys, ...$this->keys($entry->attachment->child, $entry->attachment->source, $path . '.', $within));
      }
      foreach ($entry->variants->variants ?? [] as $value => $variant) {
        array_push($keys, ...$this->keys($variant->child, $variant->source, $path . '.', $entry->variants->by . ' = ' . $value));
      }
      if ($entry->list !== NULL) {
        array_push($keys, ...$this->keys($entry->list->child(), $entry->list->item->source, $path . '.*.', $within));
      }
    }
    return $keys;
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

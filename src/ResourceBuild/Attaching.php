<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;
use Drupal\surface\Resource\ClosedSiteListInterface;
use Drupal\surface\Resource\EntrySchemasInterface;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Mixin;
use Drupal\surface\Port;

/**
 * The attach phase of the build: children, collections, variants, mixins.
 *
 * Steps 5 and 7 of Resources: each structure mixed in, each structure an
 * alter attached at its own mount, each child at a fixed key, each map of
 * keys from the site, each variant and each collection's item, its ports
 * wired to the parent's keys and its access asked of the access phase.
 *
 * A child is built through the whole sequence again, in its own frame, so
 * this holds the build step and the two of its steps a child re-enters by,
 * buildResource() and buildRead(), handed in as closures since neither is
 * public.
 *
 * Decision: see docs/decisions.md#the-build-is-read-by-phase.
 *
 * @internal
 *
 * @see \Drupal\surface\ResourceBuild\Resources
 */
final class Attaching {

  /**
   * The ports and keys of each read structure, once read, by name.
   *
   * @var array<string, array{0: array<string, \Drupal\surface\Port>, 1: string[]}>
   */
  protected array $readPorts = [];

  /**
   * Whether each document read so far states no secret, by its hash.
   *
   * @var array<string, bool>
   */
  protected array $noSecret = [];

  /**
   * Constructs the attach phase.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, for the definitions a map of keys from the
   *   site creates.
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $watchedValueCheck
   *   What a watched value passes before a refiner is handed it, sealed
   *   into every map and every owner read again.
   * @param \Drupal\surface\ResourceBuild\Declaring $declaring
   *   The declaring phase.
   * @param \Drupal\surface\ResourceBuild\Binding $binding
   *   The binding phase, for a map's keys its storage always holds.
   * @param \Drupal\surface\ResourceBuild\Gating $gating
   *   The access phase, which answers for each child.
   * @param \Drupal\surface\ResourceBuild\Resources $resources
   *   The build step, which builds a variant held unsettled when a door
   *   reads it (VariantsToSettle).
   * @param \Closure $buildsResource
   *   Resources::buildResource(), which builds a child.
   * @param \Closure $buildsRead
   *   Resources::buildRead(), which builds a structure read from a schema.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly WatchedValueCheckInterface $watchedValueCheck,
    protected readonly Declaring $declaring,
    protected readonly Binding $binding,
    protected readonly Gating $gating,
    protected readonly Resources $resources,
    protected readonly \Closure $buildsResource,
    protected readonly \Closure $buildsRead,
  ) {
  }

  /**
   * Builds one child at a key, invoked the way invokeChild() resolves.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The parent's build: its context, its frame (the parent, for messages
   *   and the cycle check, with the permission its own operation asks,
   *   resolved) and its own ancestry.
   * @param string $child
   *   The child's name: its class, or a derived resource's id.
   * @param string $key
   *   The key the child sits at.
   * @param bool $whole
   *   TRUE for a variant, which a locked answer builds locked whole, since
   *   the parent's key holds all the variants and not this one alone.
   * @param string|null $storedAt
   *   Where the parent's storage keeps the child's keys, by dotted path
   *   from the parent, when that is not the key: an entry of a map of keys
   *   from the site, `<key>.<entry>`. NULL for the key.
   * @param array{resource: string, at: string, values: array<string, mixed>}|null $siteDefaults
   *   The defaults the site sets on the child's keys, from its parent's;
   *   NULL for none. A child with some is built here, never reused.
   * @param \Drupal\surface\ResourceBuild\VariantsToSettle|null $deferred
   *   For a variant the site lists, what it is built in when a door reads
   *   it, its access asked now; NULL to build the child now.
   *
   * @return array{0: \Drupal\surface\Attachment|null, 1: \Drupal\surface\Resource\AccessAnswer|null, 2: \Drupal\surface\Resource\OperationContext, 3: bool}
   *   The sealed child, or NULL when its access omits it; that access
   *   answer, or NULL for a child that invokes no operation of its own and
   *   has no access class, which is open and was asked nothing; the
   *   context the child was invoked in; and whether, built for the account
   *   alone, a runtime answer was set aside, so the key is marked.
   */
  protected function buildChild(Build $build, string $child, string $key, bool $whole = FALSE, ?string $storedAt = NULL, ?array $siteDefaults = NULL, ?VariantsToSettle $deferred = NULL): array {
    $context = $build->context;
    $frame = $build->frame;
    $ancestry = $build->ancestry;
    $definition = $this->registry->getDefinition($child);
    [$child_context, $operation] = $this->declaring->invokeChild($definition, $context, $key, $frame['id']);
    [$access, $runtime] = $this->gating->childAccess($definition, $operation, $child_context, $this->gating->account(), $frame['permission'] ?? NULL);
    if ($access !== NULL && $access->isOmitted()) {
      return [NULL, $access, $child_context, FALSE];
    }
    $frame['key'] = $key;
    if ($storedAt !== NULL) {
      $frame['storedAt'] = $storedAt;
    }
    $locked = $whole && $access?->isLocked() ? Gating::lockReason($access) : NULL;
    $read = $definition->isRead() && $access === NULL && $locked === NULL && $siteDefaults === NULL;
    if ($deferred !== NULL) {
      // A variant the site lists is built when a door reads it, in the
      // build this one is, for the account this one is for.
      // Decision: see
      // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
      return [
        new Attachment(
          new VariantToSettle($deferred, $definition->name(), $deferred->shared($child_context), $locked === NULL ? NULL : (string) $locked, $read),
          $definition->name(),
          $definition->id,
          label: $definition->label(),
          description: $definition->description(),
        ),
        $access,
        $child_context,
        $runtime,
      ];
    }
    return [
      new Attachment(
        $read
          ? $this->buildRead($definition->name(), $child_context, [...$ancestry, $frame])
          : $this->buildResource($definition->name(), $child_context, [...$ancestry, $frame], $locked, siteDefaults: $siteDefaults),
        $definition->name(),
        $definition->id,
        label: $definition->label(),
        description: $definition->description(),
      ),
      $access,
      $child_context,
      $runtime,
    ];
  }

  /**
   * Answers whether a structure read from a schema holds no secret.
   *
   * Asked of its document once per document, which every variant a list
   * reads from it shares.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The read structure.
   * @param \Drupal\surface\ResourceBuild\SiteEntries $entries
   *   What reads an entry a provider lists without its schema: that
   *   entry's schema alone is read.
   *
   * @return bool
   *   TRUE when its document states no secret (SchemaReader::holdsNoSecret()).
   */
  public function readHoldsNoSecret(ResourceDefinition $definition, SiteEntries $entries): bool {
    $inputs = $definition->inputs;
    $root = $hash = NULL;
    if ($inputs?->class === SiteEntries::class && $inputs->method === 'fill') {
      [$from, $value] = $inputs->arguments + [NULL, NULL];
      if (!is_string($from) || !is_string($value)) {
        return FALSE;
      }
      [$root, , $hash] = $entries->document($from, $value);
    }
    elseif ($inputs?->class === SchemaReader::class && $inputs->method === 'fill') {
      [$root, , $hash] = $inputs->arguments + [NULL, NULL, NULL];
    }
    if (!is_array($root)) {
      return FALSE;
    }
    $hash = is_string($hash) ? $hash : ResourceBlueprint::documentHash($root);
    return $this->noSecret[$hash] ??= SchemaReader::holdsNoSecret($root, fn (string $id): bool => $this->registry->hasShape($id));
  }

  /**
   * Attaches the owner's fixed children, each built at its key.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's inputs blueprint.
   * @param array<string, array{resource: string, at: string, values: array<string, mixed>}> $childDefaults
   *   The defaults the site sets on each child's keys, by key
   *   (writeSiteDefaults()).
   */
  public function attachChildren(Build $build, ResourceBlueprint $inputs, array $childDefaults): void {
    $builder = $build->builder;
    $definition = $build->definition;
    // The children, each through this same build step in its own frame:
    // its own shape, its own alters, its own refiners, and the context
    // it is invoked in, which its access may then narrow to locked or to
    // nothing at all, however it was invoked.
    foreach ($inputs->attachments() as $key => $child) {
      // Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
      $reads = $inputs->wiring((string) $key);
      $this->assertWiring($build, NULL, (string) $key, (string) $child, $reads);
      [$attachment, $access, , $runtime] = $this->buildChild($build, (string) $child, $key, siteDefaults: $childDefaults[(string) $key] ?? NULL);
      if ($access !== NULL) {
        // What the child's access answer read is what this data surface,
        // and everything emitted from it, varies by.
        $builder->addCacheableDependency($access);
      }
      if ($attachment === NULL) {
        // Decision: see docs/decisions.md#omitted-is-omitted-both-ways.
        $builder->omit($key);
        continue;
      }
      $attachment = $attachment->withReads($reads, static::wiredKeys($builder, $reads));
      $builder->attach($key, $attachment);
      $this->wirePorts($builder, $key . '.', $attachment, FALSE, $definition->id);
      if ($access !== NULL && $access->isLocked()) {
        $builder->lock($key, $access->getReason());
      }
      if ($runtime) {
        $builder->markRuntimeAccess($key);
      }
    }
  }

  /**
   * Registers the structures an owner's variants are read from, by listing.
   *
   * What building the owner registers (variantKeysOf()), for a request
   * that read the owner from the build cache and builds one of its
   * variants now. The lists are the ones the kept build was made from:
   * a change to one is a tag that dropped it.
   *
   * @param string $owner
   *   The owner, by name.
   */
  public function registerVariantsOf(string $owner): void {
    $definition = $this->registry->getDefinition($owner);
    $builder = (new SurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $inputs = $this->declaring->blueprint($builder, $definition);
    $this->declaring->fillBlueprints($definition, $inputs);
    $this->variantKeysOf($definition, $inputs, $inputs->keys(), $builder);
  }

  /**
   * Builds a map of keys from the site at its key, from its provider.
   *
   * The provider is asked now, and what it read is this data surface's
   * cacheability. Each entry becomes a key of a child sealed at the key,
   * which its parent stores: a value of the declared type or shape, held
   * to what is stored when the entry is locked; or the structure, built
   * once for the map in this frame and held at every entry's key, its
   * access answering once for the map, as a collection's child is built
   * once for the list.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its context, and the
   *   frame and ancestry its children are built under.
   * @param string $key
   *   The key the map sits at.
   * @param array{each: string, from: class-string<\Drupal\surface\Resource\SiteListInterface>, default: mixed, child: bool, reads: array<string, string>} $each
   *   What the blueprint recorded for it.
   * @param array<string, array> $maps
   *   Every map of keys from the site the owner declared, by key, as the
   *   blueprint recorded them: what a port on an entry may read.
   * @param array<string, \Drupal\surface\SurfaceInterface|null> $listed
   *   The maps built so far, by key: the sealed map, or NULL for one left
   *   out.
   *
   * @return \Drupal\surface\SurfaceInterface|null
   *   The sealed map, or NULL when its access left it out.
   *
   * @throws \LogicException
   *   When the provider's list cannot be cached or varies by the account,
   *   or names an entry no key can be (keysFrom()), or an entry of a
   *   structure says what it starts from, or the
   *   structure stores itself, or its ports are wired to what an entry
   *   cannot read (assertEachWiring()).
   */
  protected function buildEach(Build $build, string $key, array $each, array $maps = [], array $listed = []): ?SurfaceInterface {
    $builder = $build->builder;
    $definition = $build->definition;
    $frame = $build->frame;
    $keys = $this->keysFrom($definition, $key, $each['from']);
    $builder->addCacheableDependency($keys);
    // What every entry is, whatever the site lists: what the map's config
    // schema is written from.
    $shell = $builder->getDefinition($key);
    if ($shell !== NULL) {
      DefinitionMetadata::setEach($shell, $each['child'] ? $each['each'] : (new ResourceBlueprint(new SurfaceBuilder(), $this->typedDataManager))->useShapes($this->declaring->shapes())->useReferences($this->declaring->references())->add($key, $each['each'], $shell->getLabel() ?? $key, $each['default']));
    }
    $entries = $keys->entries();
    $map = (new SurfaceBuilder())->setWatchedValueCheck($this->watchedValueCheck);
    $access = NULL;
    $runtime = FALSE;
    if ($each['child']) {
      $structure = $this->registry->getDefinition($each['each']);
      if ($structure->storage !== NULL || $structure->identity !== []) {
        throw new \LogicException(sprintf('%s attaches %s at every entry of "%s", which names %s: an entry is stored by its owner, under the key, so it is a structure.', $definition->id, $structure->id, $key, $structure->storage !== NULL ? 'storage of its own' : 'identity keys'));
      }
      // Each port is wired per entry, to the entry of the same id of a
      // sibling map from the same provider.
      // Decision: see docs/decisions.md#keys-that-come-from-the-site.
      $wired = $this->assertEachWiring($definition, $key, $each, $entries, $maps, $listed);
      $first = array_key_first($entries);
      [$attachment, $access, , $runtime] = $this->buildChild($build, $each['each'], $key, storedAt: $first === NULL ? NULL : $key . '.' . $first);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      if ($attachment === NULL) {
        // Decision: see docs/decisions.md#omitted-is-omitted-both-ways.
        $builder->omit($key);
        return NULL;
      }
      foreach (array_unique(array_values($each['reads'])) as $sibling) {
        $map->addPort(Port::through($sibling));
      }
      foreach ($entries as $id => $entry) {
        $id = (string) $id;
        if ($entry['default'] !== NULL) {
          throw new \LogicException(sprintf('%s says what the "%s" entry of "%s" on %s starts from, and each entry there is %s, which starts from its own defaults.', $each['from'], $id, $key, $definition->id, $structure->id));
        }
        $map->setDefinition($id, MapDataDefinition::create()->setLabel($entry['label']));
        $at = $attachment;
        if ($each['reads'] !== []) {
          // In the map, the entry reads the sibling's entry through the
          // map's own port; in the owner, the edges run from the sibling's
          // entry to what the entry's keys read it for.
          $through = [];
          $owned = [];
          foreach ($each['reads'] as $port => $sibling) {
            $through[$port] = Port::through($sibling)->name . '.' . $id;
            $owned[$port] = $sibling . '.' . $id;
          }
          $at = $attachment->withReads($through, $wired[$id]);
          $this->wirePorts($builder, $key . '.' . $id . '.', $attachment->withReads($owned), FALSE, $definition->id);
        }
        $map->attach($id, $at);
        if ($entry['locked']) {
          $map->lock($id);
        }
      }
    }
    else {
      $blueprint = (new ResourceBlueprint($map, $this->typedDataManager))->useShapes($this->declaring->shapes())->useReferences($this->declaring->references());
      foreach ($entries as $id => $entry) {
        $blueprint->add((string) $id, $each['each'], $entry['label'], $entry['default'] ?? $each['default']);
        if ($entry['locked']) {
          $map->hold((string) $id);
        }
      }
      // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
      $held = $frame['held'] ?? NULL;
      $this->binding->markAlwaysHeld($map, array_map('strval', array_keys($entries)), is_array($held) ? [
        'prefix' => $held['prefix'] . $key . '.',
      ] + $held : NULL);
    }
    $sealed = $map->seal();
    $reads = [];
    foreach (array_unique(array_values($each['reads'])) as $sibling) {
      $reads[Port::through($sibling)->name] = $sibling;
    }
    $builder->attach($key, (new Attachment($sealed))->withReads($reads, static::wiredKeys($builder, $reads)));
    if ($access !== NULL && $access->isLocked()) {
      $builder->lock($key, $access->getReason());
    }
    if ($runtime) {
      $builder->markRuntimeAccess($key);
    }
    return $sealed;
  }

  /**
   * Attaches the owner's maps of keys from the site.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's inputs blueprint.
   */
  public function attachMaps(Build $build, ResourceBlueprint $inputs): void {
    // Each map of keys from the site, its entries listed by its provider
    // now, as an options resolver lists choices.
    // Decision: see docs/decisions.md#keys-that-come-from-the-site.
    // A map whose entries read a sibling map's is built after the maps
    // that read nothing, so each sibling's entries are listed by then.
    $maps = $inputs->fromSite();
    $listed = [];
    foreach ([FALSE, TRUE] as $reading) {
      foreach ($maps as $key => $each) {
        if (($each['reads'] !== []) === $reading) {
          $listed[(string) $key] = $this->buildEach($build, (string) $key, $each, $maps, $listed);
        }
      }
    }
  }

  /**
   * Refuses a wiring of an entry's ports that a sibling map cannot honor.
   *
   * Each port wired is one the structure reads, to a sibling map that
   * addEach() declared from the same provider, whose entry of the same id
   * is of the port's type (or declared with its shape); every entry has
   * its match there; and every port the structure requires is wired.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param string $key
   *   The key the map sits at.
   * @param array $each
   *   What the blueprint recorded for it.
   * @param array<string, array> $entries
   *   What its provider listed, by id.
   * @param array<string, array> $maps
   *   Every map of keys from the site the owner declared, by key.
   * @param array<string, \Drupal\surface\SurfaceInterface|null> $listed
   *   The maps built so far, by key.
   *
   * @return array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>>
   *   For each entry, the sibling's entry each port reads, as declared, by
   *   port: what a refusal in the entry names.
   *
   * @throws \LogicException
   *   Naming the owner, the port and what is wrong with the wiring.
   */
  protected function assertEachWiring(ResourceDefinition $definition, string $key, array $each, array $entries, array $maps, array $listed): array {
    [$ports] = $this->portsOf($each['each']);
    $id = $this->registry->getDefinition($each['each'])->id;
    $wired = array_fill_keys(array_map('strval', array_keys($entries)), []);
    foreach ($each['reads'] as $port => $sibling) {
      $port = (string) $port;
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at every entry of "%s", which %s does not read; it reads %s.', $definition->id, $port, $id, $key, $id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $other = $maps[$sibling] ?? NULL;
      $problem = match (TRUE) {
        $sibling === $key => 'which is the map itself: a port reads a value its container holds beside the entry',
        $other === NULL => sprintf('which is no map of keys from the site: an entry\'s port reads the entry of the same id in a sibling map, addEach(\'%s\', ..., from: %s::class)', $sibling, $each['from']),
        $other['child'] => 'which holds a structure at every entry: a port reads one value',
        $other['from'] !== $each['from'] => sprintf('which lists its keys from %s: an entry\'s port reads the entry of the same id, so both maps list their keys from %s', $other['from'], $each['from']),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at every entry of "%s" to "%s", %s.', $definition->id, $port, $id, $key, $sibling, $problem));
      }
      $read = $listed[$sibling] ?? NULL;
      foreach (array_keys($wired) as $entry) {
        $match = $read?->getDefinition($entry);
        if ($match === NULL) {
          throw new \LogicException(sprintf('%s wires the "%s" port of %s at every entry of "%s" to "%s", which has no "%s" entry: %s listed it for one map and not the other.', $definition->id, $port, $id, $key, $sibling, $entry, $each['from']));
        }
        $type = $match->getDataType();
        if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
          throw new \LogicException(sprintf('%s wires the "%s" port of %s at every entry of "%s", which reads %s %s, to "%s", whose "%s" entry holds %s %s.', $definition->id, $port, $id, $key, static::article($ports[$port]->type), $ports[$port]->type, $sibling, $entry, static::article($type), $type));
        }
        if (static::unshaped($ports[$port], $match) !== NULL) {
          $shape = DefinitionMetadata::shapeOf($match);
          throw new \LogicException(sprintf('%s wires the "%s" port of %s at every entry of "%s" to "%s", whose "%s" entry is declared with %s, and the port reads the shape %s: the sibling map declares its entries with it, addEach(\'%s\', <shape>::class, ...).', $definition->id, $port, $id, $key, $sibling, $entry, $shape === NULL ? 'a type alone' : 'the shape ' . $shape, $ports[$port]->shape, $sibling));
        }
        $wired[$entry][$port] = Surface::deepClone($match);
      }
    }
    foreach ($ports as $port) {
      if ($port->required && !isset($each['reads'][$port->name])) {
        throw new \LogicException(sprintf('%s attaches %s at every entry of "%s" without wiring the "%s" port it requires: attachEach(\'%s\', ..., reads: [\'%s\' => \'<map>\']) names a sibling map from %s whose entry of the same id it reads.', $definition->id, $id, $key, $port->name, $key, $port->name, $each['from']));
      }
    }
    return $wired;
  }

  /**
   * Asks a provider for its keys, refusing a list that cannot be kept.
   *
   * Decision: see docs/decisions.md#keys-that-come-from-the-site.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner, for the messages.
   * @param string $key
   *   The key the map sits at, for the messages.
   * @param class-string<\Drupal\surface\Resource\SiteListInterface> $from
   *   The provider's class.
   *
   * @return \Drupal\surface\Resource\SiteList
   *   What it listed.
   *
   * @throws \LogicException
   *   When the list cannot be cached, or varies by the account, or names
   *   an entry no key can be, or one with a schema or a structure, which a
   *   key per thing does not read.
   */
  protected function keysFrom(ResourceDefinition $definition, string $key, string $from): SiteList {
    $keys = $this->siteList($from);
    if ($keys->getCacheMaxAge() === 0) {
      throw new \LogicException(sprintf('%s lists the keys of "%s" on %s with max-age 0. Keys from the site must be cacheable, since the form, both schema documents and the tool listing are kept with them: declare the cache tags of what it reads (a configuration object\'s, an entity type\'s list) instead.', $from, $key, $definition->id));
    }
    $accounts = static::accountContexts($keys);
    if ($accounts !== []) {
      throw new \LogicException(sprintf('%s lists the keys of "%s" on %s varying by %s. Which keys there are is the site\'s, the same for every account; an entry an account may not change is access, which the structure\'s access class answers.', $from, $key, $definition->id, implode(', ', $accounts)));
    }
    foreach ($keys->entries() as $id => $entry) {
      $id = (string) $id;
      if (strpbrk($id, '.:') !== FALSE) {
        throw new \LogicException(sprintf('%s lists "%s" for the keys of "%s" on %s. "%s" cannot be the id of an entry: it becomes a key, which is not empty and holds no dot or colon.', $from, $id, $key, $definition->id, $id));
      }
      if ($entry['schema'] !== NULL || $entry['structure'] !== NULL) {
        throw new \LogicException(sprintf('%s lists "%s" for the keys of "%s" on %s with a %s, which a key from the site does not read: each entry is the type or structure the blueprint names at "%s", with an id, a label, and optionally a start and a lock.', $from, $id, $key, $definition->id, $entry['schema'] !== NULL ? 'schema' : 'structure', $key));
      }
    }
    return $keys;
  }

  /**
   * Asks a provider named by `from:` for what it lists.
   *
   * @param class-string<\Drupal\surface\Resource\SiteListInterface> $from
   *   The provider's class.
   *
   * @return \Drupal\surface\Resource\SiteList
   *   What it listed.
   */
  protected function siteList(string $from): SiteList {
    $provider = $this->declaring->instance($from, SiteListInterface::class);
    return PipelineGuard::onBehalf(PipelineGuard::PROVIDER, $from, 'list', static fn (): SiteList => $provider->list());
  }

  /**
   * Gets the cache contexts of a list that vary by the account.
   *
   * @param \Drupal\surface\Resource\SiteList $list
   *   The list.
   *
   * @return string[]
   *   Its contexts that name the user or the session.
   */
  protected static function accountContexts(SiteList $list): array {
    return array_values(array_filter($list->getCacheContexts(), static fn (string $context): bool => $context === 'user' || str_starts_with($context, 'user.') || $context === 'session' || str_starts_with($context, 'session.')));
  }

  /**
   * Builds each structure mixed in by itself, and settles its members.
   *
   * A mixed-in structure's members are the owner's keys already, declared
   * through its blueprint where mixIn() stands. Here each is checked (its
   * ports wired to the owner's keys), built by itself in the owner's
   * context for its def and asked its access: omitted takes its members
   * back out, locked holds them to what is stored, and what a structure
   * it mixes in itself answered inside it is read off its own build.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its context, its frame
   *   (with the permission its operation asks), and its input keys, every
   *   structure's members among them, the keys holding a child or
   *   variants, and its collection keys.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint.
   *
   * @return array{bind: array<int, array{definition: \Drupal\surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]}>, members: array<string, string>, guarded: array<string, string>, answeredBy: array<string, string>, ruling: string[]}
   *   What to bind in the owner's frame, innermost first, each with the
   *   members still there; every member, and every member of a structure
   *   with an access answer (an access class, or an alter's), each mapped
   *   to its structure's id, with what answers for each such structure;
   *   and the members a structure's coded refiner may rule out.
   */
  public function mixIn(Build $build, ResourceBlueprint $inputs): array {
    $builder = $build->builder;
    $context = $build->context;
    $frame = $build->frame;
    $mixed = ['bind' => [], 'members' => [], 'guarded' => [], 'answeredBy' => [], 'ruling' => []];
    foreach ($inputs->mixins() as ['class' => $class, 'reads' => $reads, 'blueprint' => $blueprint]) {
      $structure = $this->registry->getDefinition($class);
      $this->assertMixinWiring($build, $structure, $blueprint, $reads);
      $mixed = static::mixedMembers($mixed, $blueprint, $structure, fn (string $name): ResourceDefinition => $this->registry->getDefinition($name));
      // By itself, as if on its own: what its def is written from, in the
      // context its owner's hands it, and what its access answers there.
      $child_context = Resources::childContext($context, $structure->id);
      [$access, $runtime] = $this->gating->childAccess($structure, NULL, $child_context, $this->gating->account(), $frame['permission'] ?? NULL);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      if ($access !== NULL && $access->isOmitted()) {
        foreach ($blueprint->keys() as $key) {
          $builder->withdraw($key);
        }
        continue;
      }
      $built = $this->buildResource($structure->name(), $child_context, []);
      $map = $built->getDefinitions();
      $locked = $access !== NULL && $access->isLocked();
      $present = [];
      foreach ($blueprint->keys() as $key) {
        if (!$map->has($key)) {
          // A structure it mixes in itself, which that one's access left
          // out inside it.
          $builder->withdraw($key);
          continue;
        }
        if ($locked || $map->isHeld($key)) {
          $builder->hold($key);
        }
        // Decided once the subject is known, here or by a structure it
        // mixes in itself: marked, built for the account alone.
        if ($runtime || $map->isRuntimeAccess($key)) {
          $builder->markRuntimeAccess($key);
        }
        $present[] = $key;
      }
      $builder->addMixin(new Mixin(new Attachment($built, $structure->name(), $structure->id, label: $structure->label(), description: $structure->description()), $present, $reads));
      array_push($mixed['bind'], ...$this->mixinBindings($structure, $blueprint, $reads, $present));
    }
    return $mixed;
  }

  /**
   * Lists a structure's members, and those its access class may take away.
   *
   * @param array $mixed
   *   What mixIn() collects so far.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint the structure was declared through.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param callable(string): \Drupal\surface\ResourceBuild\ResourceDefinition $definitions
   *   Reads a structure it mixes in itself.
   *
   * @return array
   *   The same, with this structure's members and those of every
   *   structure it mixes in.
   */
  protected static function mixedMembers(array $mixed, ResourceBlueprint $blueprint, ResourceDefinition $structure, callable $definitions): array {
    foreach ($blueprint->mixins() as ['class' => $class, 'blueprint' => $inner]) {
      $mixed = static::mixedMembers($mixed, $inner, $definitions($class), $definitions);
    }
    foreach ($blueprint->keys() as $key) {
      $mixed['members'][$key] ??= $structure->id;
      if ($structure->answersAccess()) {
        $mixed['guarded'][$key] ??= $structure->id;
        $mixed['answeredBy'][$structure->id] = $structure->access !== NULL ? 'an access class' : 'an alter answering its access';
      }
    }
    foreach ($structure->refiners as $refiner) {
      if ($refiner->mayNotApply) {
        $mixed['ruling'][] = $refiner->key;
      }
    }
    return $mixed;
  }

  /**
   * Lists what to bind in the owner's frame for one structure mixed in.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   Its ports, wired to the owner's keys.
   * @param string[] $present
   *   The members still there.
   *
   * @return array<int, array{definition: \Drupal\surface\ResourceBuild\ResourceDefinition, blueprint: \Drupal\surface\ResourceBuild\ResourceBlueprint, reads: array<string, string>, keys: string[]}>
   *   A structure it mixes in itself first, unless its members are gone,
   *   then this one.
   */
  protected function mixinBindings(ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads, array $present): array {
    $bindings = [];
    foreach ($blueprint->mixins() as ['class' => $class, 'reads' => $inner_reads, 'blueprint' => $inner]) {
      if (array_diff($inner->keys(), $present) !== []) {
        continue;
      }
      // Its ports are wired to keys of the structure mixing it in, which
      // are the owner's keys now, under the same names.
      array_push($bindings, ...$this->mixinBindings($this->registry->getDefinition($class), $inner, $inner_reads, $present));
    }
    $bindings[] = [
      'definition' => $structure,
      'blueprint' => $blueprint,
      'reads' => $reads,
      'keys' => array_values(array_intersect($blueprint->keys(), $present)),
    ];
    return $bindings;
  }

  /**
   * Refuses a wiring of a mixed-in structure's ports its owner cannot honor.
   *
   * Every port is wired, to a plain input of the owner's of the port's
   * type that the structure did not bring itself: in the owner's frame a
   * port is read as the key it is wired to, so one left unwired would
   * have nothing to be read as.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its plain input keys,
   *   the keys holding a child or variants, and its collection keys.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $structure
   *   The structure.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $blueprint
   *   The blueprint it was declared through.
   * @param array<string, string> $reads
   *   The wiring.
   *
   * @throws \LogicException
   *   Naming the owner, the port and what is wrong with the wiring.
   */
  protected function assertMixinWiring(Build $build, ResourceDefinition $structure, ResourceBlueprint $blueprint, array $reads): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $input_keys = $build->inputKeys;
    $attached = $build->attached;
    $lists = $build->lists;
    $ports = $blueprint->ports();
    foreach ($reads as $port => $wired) {
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which %s does not read; it reads %s.', $definition->id, $port, $structure->id, $structure->id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $problem = match (TRUE) {
        in_array($wired, $blueprint->keys(), TRUE) => sprintf('which %s brought itself: a port reads a key of the owner it is mixed into, and its members are its own', $structure->id),
        in_array($wired, $attached, TRUE), in_array($wired, $lists, TRUE) => 'which holds a child: a port reads one value',
        !in_array($wired, $input_keys, TRUE) => sprintf('which %s does not declare as an input: a port reads a key of its owner, by name', $definition->id),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $problem));
      }
      $type = $builder->getDefinition($wired)?->getDataType();
      if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, which reads %s %s, to "%s", which holds %s %s.', $definition->id, $port, $structure->id, static::article((string) $ports[$port]->type), $ports[$port]->type, $wired, static::article((string) $type), $type));
      }
      $unshaped = static::unshaped($ports[$port], $builder->getDefinition($wired));
      if ($unshaped !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s, mixed in, to "%s", %s.', $definition->id, $port, $structure->id, $wired, $unshaped));
      }
    }
    foreach ($ports as $port) {
      if (!isset($reads[$port->name])) {
        throw new \LogicException(sprintf(
          '%s mixes %s in without wiring its "%s" port: with(..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads. A mixed-in structure\'s refiners read each port as the owner\'s key it is wired to, so every port is wired, required or not.',
          $definition->id,
          $structure->id,
          $port->name,
          $port->name,
          $definition->id,
        ));
      }
    }
  }

  /**
   * Refuses what of the owner's names a member it may not depend on.
   *
   * A mixed-in structure's members are the owner's keys, so the owner's
   * refiners and rules, and its alters', may watch them and narrow them
   * as their own, with two exceptions. Whether a member applies is the
   * structure's to say: its def, which every owner shares, says where it
   * is required. And nothing of the owner's may name a member of a
   * structure that has an access class: what that access may take away
   * for one account, nothing may depend on for every account.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner.
   * @param array $mixins
   *   What mixIn() answered.
   * @param array<int, array{0: object|string, 1: \Drupal\surface\ResourceBuild\RefinerDefinition[], 2: string|null}> $links
   *   The owner's refiners and its alters'.
   * @param \Drupal\surface\Refinement\Rule[] $rules
   *   The owner's rules and its alters'.
   * @param array<string, array<string, string>> $wirings
   *   The ports of each child the owner or an alter attached, wired.
   *
   * @throws \LogicException
   *   Naming what names the member, and the structure.
   */
  public function assertMixinsUnnamed(ResourceDefinition $definition, array $mixins, array $links, array $rules, array $wirings): void {
    $guarded = $mixins['guarded'];
    $guard = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s names "%s", a member of %s, which is mixed into %s and has %s. What that access may take away for one account, nothing of the owner\'s may depend on: read it inside %s, or drop %s.',
      $what,
      $key,
      $guarded[$key],
      $definition->id,
      $mixins['answeredBy'][$guarded[$key]] ?? 'an access class',
      $guarded[$key],
      ($mixins['answeredBy'][$guarded[$key]] ?? 'an access class') === 'an access class' ? 'the access class' : 'the alter\'s access',
    ));
    $ruled = static fn (string $what, string $key): \LogicException => new \LogicException(sprintf(
      '%s may answer that "%s", a member of %s mixed into %s, does not apply. Whether a mixed-in structure\'s member applies is the structure\'s to say: its def, which every owner shares, says where it is required.',
      $what,
      $key,
      $mixins['members'][$key],
      $definition->id,
    ));
    foreach ($links as [, $refiners]) {
      foreach ($refiners as $refiner) {
        foreach ([$refiner->key, ...$refiner->watched()] as $key) {
          if (isset($guarded[$key])) {
            throw $guard($refiner->describe(), $key);
          }
        }
        if ($refiner->mayNotApply && isset($mixins['members'][$refiner->key])) {
          throw $ruled($refiner->describe(), $refiner->key);
        }
      }
    }
    foreach ($rules as $rule) {
      $name = sprintf('narrow("%s") in %s', $rule->key, $definition->id);
      foreach ([$rule->key, ...($rule->condition()?->keys() ?? [])] as $key) {
        if (isset($guarded[$key])) {
          throw $guard($name, $key);
        }
      }
      if ($rule->rulesOut() && isset($mixins['members'][$rule->key])) {
        throw $ruled($name, $rule->key);
      }
    }
    foreach ($wirings as $at => $reads) {
      foreach ($reads as $port => $key) {
        if (isset($guarded[$key])) {
          throw $guard(sprintf('The "%s" port of the child at "%s"', $port, $at), $key);
        }
      }
    }
  }

  /**
   * Builds the structure an alter attached at its own mount, and fixes it.
   *
   * The alter is an adapter: the structure's keys are its mount's, stored
   * under its module's name as any key an alter mounts, and the structure
   * refines them there in its own frame, its ports reading the owner's
   * keys the alter wired them to. Built before any refiner or rule is
   * bound, because the alter's glue may read the structure's keys.
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build: its builder, the owner, its context, the frame
   *   and ancestry the structure is built under, and its input keys, the
   *   keys holding a child or variants, and its collection keys.
   * @param string $module
   *   The alter's module.
   * @param string $key
   *   The mount, `third_party_settings.<module>`.
   * @param string $child
   *   The structure, by name.
   * @param array<string, string> $reads
   *   Its ports, each wired to an owner's key.
   *
   * @return string[]
   *   The structure's keys, which the alter's glue may read.
   *
   * @throws \LogicException
   *   For a resource with storage or identity, a structure holding a
   *   child, a wiring the owner cannot honor, or an access answer other
   *   than open.
   */
  protected function mountStructure(Build $build, string $module, string $key, string $child, array $reads): array {
    $builder = $build->builder;
    $definition = $build->definition;
    $structure = $this->registry->getDefinition($child);
    if ($structure->storage !== NULL || $structure->identity !== []) {
      throw new \LogicException(sprintf(
        'The %s alter of %s attaches %s at its mount, %s, and %s names %s. A mount holds a structure, which the owner\'s storage stores under the module\'s name.',
        $module,
        $definition->id,
        $structure->id,
        $key,
        $structure->id,
        $structure->storage !== NULL ? 'storage of its own' : 'identity keys',
      ));
    }
    $this->assertWiring($build, $module, $key, $child, $reads);
    [$attachment, $access] = $this->buildChild($build, $child, $key);
    if ($attachment === NULL || ($access !== NULL && !$access->isOpen())) {
      throw new \LogicException(sprintf(
        'The access of %s, attached at %s by the %s alter of %s, answered %s. A structure at a mount that is locked or left out is not built yet.',
        $structure->id,
        $key,
        $module,
        $definition->id,
        $access?->getAnswer() ?? 'omitted',
      ));
    }
    if ($access !== NULL) {
      $builder->addCacheableDependency($access);
    }
    foreach ($attachment->child->getDefinitions()->entries() as $name => $entry) {
      if ($entry->isNested() || $entry->list !== NULL || $entry->mounted !== []) {
        throw new \LogicException(sprintf(
          'The %s alter of %s attaches %s at its mount, %s, and %s holds a child at "%s". A structure at a mount holds keys and maps, not children, for now.',
          $module,
          $definition->id,
          $structure->id,
          $key,
          $structure->id,
          $name,
        ));
      }
    }
    $attachment = $attachment->withReads($reads, static::wiredKeys($builder, $reads));
    $builder->mountStructure($module, $attachment);
    $this->wirePorts($builder, $key . '.', $attachment, TRUE, sprintf('the %s alter of %s', $module, $definition->id));
    return $attachment->child->getDefinitions()->names();
  }

  /**
   * Builds each structure an alter attached at its own mount.
   *
   * Decision: see docs/decisions.md#an-alter-attaches-at-its-own-mount.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build.
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceBlueprintAdditions> $additions
   *   Each alter's additions, by module.
   *
   * @return array{0: array<string, string[]>, 1: array<string, string[]>}
   *   The keys of the structure each module attached at its mount, by
   *   module; and every key any alter mounted, mapped to the modules that
   *   mounted it.
   */
  public function mountAlters(Build $build, array $additions): array {
    $readable = [];
    foreach ($additions as $contributor => $blueprint) {
      foreach ($blueprint->attachments() as $key => $child) {
        $readable[(string) $contributor] = $this->mountStructure($build, (string) $contributor, (string) $key, (string) $child, $blueprint->wiring((string) $key));
      }
    }
    // What each module mounted, so an alter's method may watch its own
    // module's keys and is told so when it names another's.
    $mounted_by = [];
    foreach ($additions as $contributor => $blueprint) {
      foreach ([...$blueprint->keys(), ...($readable[(string) $contributor] ?? [])] as $key) {
        $mounted_by[$key][] = (string) $contributor;
      }
    }
    return [$readable, $mounted_by];
  }

  /**
   * Refuses a wiring of a child's ports that its container cannot honor.
   *
   * Each port wired is one the child reads, to a key of the container's
   * own (an owner's plain input, by name) outside the child, of the
   * port's type; every port the child requires is wired. A variant and a
   * collection's item are wired by nothing yet, so neither may
   * require a port.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The container's build: its builder, holding its keys, the container
   *   (the owner, or the owner an alter adapts), its plain input keys,
   *   the keys holding a child or variants, and its collection keys.
   * @param string|null $module
   *   The alter's module, or NULL for the owner itself.
   * @param string $key
   *   The key the child sits at.
   * @param string $child
   *   The child, by name.
   * @param array<string, string> $reads
   *   The wiring.
   * @param string $as
   *   How the child is held: attach, variant or collection. An entry of a
   *   map of keys from the site is wired by assertEachWiring().
   *
   * @throws \LogicException
   *   Naming the container, the port and what is wrong with the wiring.
   */
  public function assertWiring(Build $build, ?string $module, string $key, string $child, array $reads, string $as = 'attach'): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $input_keys = $build->inputKeys;
    $attached = $build->attached;
    $lists = $build->lists;
    [$ports, $declared] = $this->portsOf($child);
    if ($ports === [] && $reads === []) {
      return;
    }
    $who = $module === NULL ? $definition->id : sprintf('The %s alter of %s', $module, $definition->id);
    $id = $this->registry->getDefinition($child)->id;
    foreach ($reads as $port => $wired) {
      if (!isset($ports[$port])) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s", which %s does not read; it reads %s.', $who, $port, $id, $key, $id, $ports === [] ? 'no port' : implode(', ', array_keys($ports))));
      }
      $inside = $wired === $key || str_starts_with($wired, $key . '.') || (in_array($wired, $declared, TRUE) && !in_array($wired, $input_keys, TRUE));
      $problem = match (TRUE) {
        $inside => 'which is inside the child itself: a port reads its container\'s key, and what the child holds is its own',
        in_array($wired, $attached, TRUE), in_array($wired, $lists, TRUE) => 'which holds a child: a port reads one value',
        !in_array($wired, $input_keys, TRUE) => sprintf('which %s does not declare as an input: a port reads a key of its container, by name', $definition->id),
        default => NULL,
      };
      if ($problem !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s" to "%s", %s.', $who, $port, $id, $key, $wired, $problem));
      }
      $type = $builder->getDefinition($wired)?->getDataType();
      if ($ports[$port]->type !== 'any' && $type !== $ports[$port]->type) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s", which reads %s %s, to "%s", which holds %s %s.', $who, $port, $id, $key, static::article((string) $ports[$port]->type), $ports[$port]->type, $wired, static::article((string) $type), $type));
      }
      $unshaped = static::unshaped($ports[$port], $builder->getDefinition($wired));
      if ($unshaped !== NULL) {
        throw new \LogicException(sprintf('%s wires the "%s" port of %s at "%s" to "%s", %s.', $who, $port, $id, $key, $wired, $unshaped));
      }
    }
    foreach ($ports as $port) {
      if (!$port->required || isset($reads[$port->name])) {
        continue;
      }
      throw new \LogicException($as === 'attach'
        ? sprintf('%s attaches %s at "%s" without wiring the "%s" port it requires: attach(\'%s\', ..., reads: [\'%s\' => \'<key>\']) names the key of %s it reads.', $who, $id, $key, $port->name, $key, $port->name, $definition->id)
        : sprintf('%s fills the "%s" %s of %s and requires the "%s" port, which nothing wires: a port is wired by attach(), and a %s is wired by nothing yet.', $id, $key, match ($as) {
          'variant' => 'variants',
          default => 'collection',
        }, $who, $port->name, match ($as) {
          'variant' => 'variant',
          default => 'collection\'s item',
        }));
    }
  }

  /**
   * Says why a key cannot be wired to a port that reads a shape.
   *
   * A port that reads a shape is handed a value its readers may trust to
   * be of that shape, so the key wired to it is declared with the shape:
   * narrowed or not, it allows nothing the shape refuses. A key of the
   * shape's type alone could hold anything of that type. A port that reads
   * a type takes a key of that type, a shape's among them.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \Drupal\surface\Port $port
   *   The port.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $wired
   *   The key wired to it.
   *
   * @return string|null
   *   Why not, or NULL when it may be.
   */
  protected static function unshaped(Port $port, ?DataDefinitionInterface $wired): ?string {
    if ($port->shape === NULL || $wired === NULL) {
      return NULL;
    }
    $shape = DefinitionMetadata::shapeOf($wired);
    if ($shape === $port->shape) {
      return NULL;
    }
    return sprintf(
      'which is declared with %s, and the port reads the shape %s: a port that reads a shape reads a key declared with it, add(\'<key>\', <shape>::class, ...)',
      $shape === NULL ? 'a type alone' : 'the shape ' . $shape,
      $port->shape,
    );
  }

  /**
   * Gets the container's key each port is wired to, as declared.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The container's builder.
   * @param array<string, string> $reads
   *   The wiring.
   *
   * @return array<string, \Drupal\Core\TypedData\DataDefinitionInterface>
   *   Each wired key's definition, by port.
   */
  protected static function wiredKeys(SurfaceBuilderInterface $builder, array $reads): array {
    $wired = [];
    foreach ($reads as $port => $key) {
      $definition = $builder->getDefinition($key);
      if ($definition !== NULL) {
        $wired[$port] = Surface::deepClone($definition);
      }
    }
    return $wired;
  }

  /**
   * Reads what a child declares, without building it.
   *
   * @param string $child
   *   The child, by name.
   *
   * @return array{0: array<string, \Drupal\surface\Port>, 1: string[]}
   *   Its ports, by name, and every key it declares.
   */
  protected function portsOf(string $child): array {
    $definition = $this->registry->getDefinition($child);
    // A read structure's name holds the hash of what it was read from.
    if ($definition->isRead() && isset($this->readPorts[$definition->name()])) {
      return $this->readPorts[$definition->name()];
    }
    $blueprint = $this->declaring->blueprint(new SurfaceBuilder(), $definition);
    $this->declaring->fillBlueprints($definition, $blueprint);
    $ports = [$blueprint->ports(), $blueprint->declared()];
    if ($definition->isRead()) {
      $this->readPorts[$definition->name()] = $ports;
    }
    return $ports;
  }

  /**
   * Gets the article a type name is read with.
   *
   * @param string $type
   *   The type.
   *
   * @return string
   *   "an" or "a".
   */
  public static function article(string $type): string {
    return in_array(strtolower($type[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], TRUE) ? 'an' : 'a';
  }

  /**
   * Records, in the container, what a child's ports and keys move.
   *
   * The child refines in its own frame, under its own names; the
   * container is told which of its keys each port reads, as edges at the
   * child's paths in the container's frame, so a form rebuilds the child
   * when one of them changes, a client refines, the cycle check follows
   * the wiring, and a refusal names the container's key. For a structure
   * at a mount, whose keys the container accepts as its own, the child's
   * edges between its own keys are recorded at their paths too.
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The container's builder.
   * @param string $prefix
   *   The child's path in the container's frame, with its trailing dot.
   * @param \Drupal\surface\Attachment $attachment
   *   The child, wired.
   * @param bool $internal
   *   Whether the child's edges between its own keys are recorded too.
   * @param string $container
   *   The container, in words, for a cycle's refusal.
   */
  protected function wirePorts(SurfaceBuilderInterface $builder, string $prefix, Attachment $attachment, bool $internal, string $container): void {
    $map = $attachment->child->getDefinitions();
    $ports = $map->ports();
    $kinds = [
      SurfaceInterface::CODED => [FALSE, FALSE],
      SurfaceInterface::DECLARED => [FALSE, TRUE],
      SurfaceInterface::RUNTIME => [TRUE, FALSE],
    ];
    foreach ($map->refinementPaths() as $target => $dependencies) {
      $entry = $map->entry(explode('.', (string) $target)[0]);
      foreach ($dependencies as $dependency) {
        $port = isset($ports[$dependency]);
        if ($port ? !isset($attachment->reads[$dependency]) : !$internal) {
          // An unwired port holds nothing, and moves nothing.
          continue;
        }
        $mapped = $port ? $attachment->reads[$dependency] : $prefix . $dependency;
        $wiring = $port ? sprintf('the "%s" port of %s, which %s wires to "%s"', $dependency, $attachment->id ?? $attachment->source, $container, $mapped) : NULL;
        $mask = $entry === NULL ? SurfaceInterface::CODED : ($entry->watchedBy[$dependency] ?? SurfaceInterface::CODED);
        foreach ($kinds as $kind => [$runtime, $declared]) {
          if (($mask & $kind) !== 0) {
            $builder->addRefinement($prefix . $target, [$mapped], $runtime, $declared, $wiring);
          }
        }
      }
    }
  }

  /**
   * Resolves the variants at each key and checks their deciding key.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure with the variants.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's input blueprint.
   * @param string[] $input_keys
   *   The owner's plain input keys.
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The owner's builder, which what a provider of variants read reaches.
   *
   * @return array<string, array{by: string, children: array<string, string>, labels: array<string, string|\Stringable>, site: array<string, true>}>
   *   The variants at each key, filled from discovery's #[Variant]
   *   classes, then from what a schema read into the blueprint states and
   *   what a provider of variants from the site lists, each of those a
   *   read structure by name, with the label each of those gives its
   *   value, and the values whose variant the site's list names rather
   *   than a class, which are built when read.
   *
   * @throws \LogicException
   *   When a deciding key is not a plain input of the owner, or a provider
   *   of variants answers what cannot be kept.
   */
  public function variantKeysOf(ResourceDefinition $definition, ResourceBlueprint $inputs, array $input_keys, SurfaceBuilderInterface $builder): array {
    $variantKeys = [];
    foreach ($inputs->variants() as $key => $by) {
      if (!in_array($by, $input_keys, TRUE)) {
        throw new \LogicException(sprintf(
          'In %s, the variants at "%s" are chosen by "%s", which it never declares as a plain input: variants are chosen by a sibling that holds one value.',
          $definition->id,
          $key,
          $by,
        ));
      }
      // Every structure whose #[Variant] names this class and key is
      // one of the variants; the parent names none.
      // Decision: see docs/decisions.md#variants-nothing-declares.
      $children = $this->registry->getVariants($definition->name(), $key);
      $labels = [];
      // The values whose variant the site's list names, not a class.
      $site = [];
      // A variant a schema read into the blueprint states, or one a
      // provider lists from the site: a structure read from its schema,
      // beside the declared ones, which win for a value both name.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      foreach ($inputs->readVariants()[$key] ?? [] as $value => ['name' => $name, 'label' => $label]) {
        if (!isset($children[(string) $value])) {
          $site[(string) $value] = TRUE;
        }
        $children[(string) $value] ??= $name;
        if ($label !== NULL) {
          $labels[(string) $value] = $label;
        }
      }
      $from = $inputs->variantsFrom()[$key] ?? NULL;
      if ($from !== NULL) {
        $list = $this->variantsFrom($definition, (string) $key, $from);
        $builder->addCacheableDependency($list);
        // A provider that answers an entry's schema when it is read: the
        // entry is registered by name, its schema read when it is built.
        $lazy = is_subclass_of($from, EntrySchemasInterface::class);
        // The defs the entries share, read once for all of them.
        $shared = $list->defs() === [] ? NULL : SchemaReader::prepared(['$defs' => $list->defs()]);
        $shared_hash = $shared === NULL ? NULL : ResourceBlueprint::documentHash($shared);
        foreach ($list->entries() as $value => $entry) {
          $value = (string) $value;
          if (isset($children[$value])) {
            continue;
          }
          // A structure the site's code declares is built as declared; any
          // other is read from its schema.
          $id = $definition->id . '.' . $key . '.' . $value;
          $schema = $entry['schema'] ?? [];
          $reference = is_string($schema['$ref'] ?? NULL) && count($schema) === 1 ? SchemaResolver::defName($schema['$ref']) : NULL;
          $shared_def = $shared !== NULL && $reference !== NULL && isset($shared['$defs'][$reference]);
          $pointer = '/$defs/' . str_replace(['~', '/'], ['~0', '~1'], (string) $reference);
          $children[$value] = match (TRUE) {
            isset($entry['structure']) => $this->registry->getDefinition($entry['structure'])->name(),
            $lazy && $entry['schema'] === NULL => $inputs->readListed($id, $from, $value),
            $shared_def => $inputs->readStructure($id, $shared, $pointer, $shared_hash),
            default => $inputs->readStructure($id, SchemaReader::prepared($schema + ($list->defs() === [] ? [] : ['$defs' => $list->defs()])), ''),
          };
          $labels[$value] = $entry['label'];
          $site[$value] = TRUE;
        }
      }
      $variantKeys[$key] = [
        'by' => $by,
        'children' => $children,
        'labels' => $labels,
        'site' => $site,
      ];
    }
    return $variantKeys;
  }

  /**
   * Attaches the owner's variants at each key that has them.
   *
   * A variant the site lists is held unsettled, built when a door reads
   * it (VariantsToSettle); any other is built now.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build.
   * @param array<string, array{by: string, children: array<string, string>, labels: array<string, string|\Stringable>, site: array<string, true>}> $variantKeys
   *   What variantKeysOf() answered.
   */
  public function attachVariants(Build $build, array $variantKeys): void {
    $builder = $build->builder;
    $definition = $build->definition;
    $ancestry = $build->ancestry;
    $frame = $build->frame;
    foreach ($variantKeys as $key => ['by' => $by, 'children' => $children, 'site' => $site]) {
      $variants = [];
      $withheld = [];
      $runtime_variant = FALSE;
      // What every variant the site lists at the key is built in when read.
      $at = $site === [] ? NULL : new VariantsToSettle(
        $this->resources,
        [...$ancestry, array_replace($frame, ['key' => $key])],
        $key,
        $this->gating->accountAlone,
        $this->gating->forNoAccount,
        $this->declaring->holdsToSchema,
        $this->gating->forNoAccount ? NULL : $this->gating->underlyingAccount(),
      );
      foreach ($children as $value => $child) {
        // A variant the site lists is built when read, its wiring checked
        // then; any other is built now.
        // Decision: see
        // docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
        $deferred = isset($site[(string) $value]) ? $at : NULL;
        if ($deferred === NULL) {
          // Nothing wires a variant's ports yet.
          $this->assertWiring($build, NULL, $key, $child, [], 'variant');
        }
        [$attachment, $access, , $runtime] = $this->buildChild($build, $child, $key, TRUE, deferred: $deferred);
        if ($access !== NULL) {
          $builder->addCacheableDependency($access);
        }
        // A variant offered here that only the subject decides may be
        // withheld once it is known: the variants say so.
        $runtime_variant = $runtime_variant || $runtime;
        // A locked variant is offered, built locked whole.
        // Decision: see
        // docs/decisions.md#a-variant-is-open-locked-or-not-offered.
        if ($attachment === NULL) {
          $withheld[] = (string) $value;
          continue;
        }
        $variants[(string) $value] = $attachment;
      }
      $declared = [...array_map('strval', array_keys($variants)), ...$withheld];
      static::assertListNarrowsVariants($builder, $definition, $key, $by, $declared);
      $builder->attachVariants($key, $by, $variants, $variantKeys[$key]['labels'] + $this->variantLabels($children));
      if ($runtime_variant) {
        $builder->markRuntimeAccess($key);
      }
    }
  }

  /**
   * Attaches each collection's child, built once for the list.
   *
   * @param \Drupal\surface\ResourceBuild\Build $build
   *   The owner's build.
   * @param \Drupal\surface\ResourceBuild\ResourceBlueprint $inputs
   *   The owner's inputs blueprint.
   * @param array<string, true> $gated_lists
   *   The collections a gate asks about per item, which are marked
   *   (Gating::itemGatedLists()).
   */
  public function attachLists(Build $build, ResourceBlueprint $inputs, array $gated_lists): void {
    $builder = $build->builder;
    // Each collection's child, built once for the list: every item is
    // invoked in the one context its operation resolves, and its access
    // answers once for the list, since field access is per field and not
    // per delta.
    // Decision: see docs/decisions.md#access-once-per-list.
    foreach ($inputs->lists() as $key => $child) {
      // Nothing wires an item's ports yet.
      $this->assertWiring($build, NULL, $key, $child, [], 'collection');
      [$attachment, $access, $child_context, $runtime] = $this->buildChild($build, $child, $key);
      if ($access !== NULL) {
        $builder->addCacheableDependency($access);
      }
      if ($attachment === NULL) {
        $builder->omit($key);
        continue;
      }
      $builder->attachList($key, new Collection($attachment, $child_context));
      if ($access !== NULL && $access->isLocked()) {
        $builder->lock($key, $access->getReason());
      }
      if ($runtime || isset($gated_lists[(string) $key])) {
        $builder->markRuntimeAccess($key);
      }
    }
  }

  /**
   * Asks a provider for its variants, refusing a list that cannot be kept.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The owner, for the messages.
   * @param string $key
   *   The key the variants sit at, for the messages.
   * @param class-string<\Drupal\surface\Resource\SiteListInterface> $from
   *   The provider's class.
   *
   * @return \Drupal\surface\Resource\SiteList
   *   What it listed, each entry of a closed provider naming the structure
   *   its structures() names for it.
   *
   * @throws \LogicException
   *   When the list cannot be cached, or varies by the account, or names
   *   an entry with a start or a lock, which a variant does not read, or
   *   an entry of an open provider names no schema and no structure, or
   *   one of a closed provider names one, or a value its structures() do
   *   not name.
   */
  protected function variantsFrom(ResourceDefinition $definition, string $key, string $from): SiteList {
    $list = $this->siteList($from);
    if ($list->getCacheMaxAge() === 0) {
      throw new \LogicException(sprintf('%s lists the variants at "%s" on %s with max-age 0. Variants from the site must be cacheable, since the form, both schema documents and the tool listing are kept with them: declare the cache tags of what it reads instead.', $from, $key, $definition->id));
    }
    $accounts = static::accountContexts($list);
    if ($accounts !== []) {
      throw new \LogicException(sprintf('%s lists the variants at "%s" on %s varying by %s. Which variants there are is the site\'s, the same for every account; one an account may not choose is access, which the variant\'s access class answers.', $from, $key, $definition->id, implode(', ', $accounts)));
    }
    $closed = is_subclass_of($from, ClosedSiteListInterface::class) ? $from::structures() : NULL;
    $named = new SiteList();
    $named->addCacheableDependency($list)->addDefs($list->defs());
    foreach ($list->entries() as $value => $entry) {
      $value = (string) $value;
      if ($entry['default'] !== NULL || $entry['locked']) {
        throw new \LogicException(sprintf('%s lists "%s" for the variants at "%s" on %s with a %s, which a variant does not read: each entry is a value of the deciding key, its label, and the schema or structure it chooses.', $from, $value, $key, $definition->id, $entry['locked'] ? 'lock' : 'start'));
      }
      if ($closed === NULL) {
        if ($entry['schema'] === NULL && $entry['structure'] === NULL && !is_subclass_of($from, EntrySchemasInterface::class)) {
          throw new \LogicException(sprintf('%s lists "%s" for the variants at "%s" on %s with no schema and no structure: each variant from the site is one of them, SiteList::add(..., schema: ...) or add(..., structure: ...).', $from, $value, $key, $definition->id));
        }
        $named->add($value, $entry['label'], schema: $entry['schema'], structure: $entry['structure']);
        continue;
      }
      // Decision: see
      // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
      if ($entry['schema'] !== NULL || $entry['structure'] !== NULL) {
        throw new \LogicException(sprintf('%s lists "%s" for the variants at "%s" on %s with a %s of its own. A closed provider names each structure once, in its structures(); its list names a value and its label.', $from, $value, $key, $definition->id, $entry['schema'] !== NULL ? 'schema' : 'structure'));
      }
      if (!isset($closed[$value])) {
        throw new \LogicException(sprintf('%s lists "%s" for the variants at "%s" on %s, which its structures() does not name. A closed provider lists only the structures it names.', $from, $value, $key, $definition->id));
      }
      $named->add($value, $entry['label'], structure: $closed[$value]);
    }
    return $named;
  }

  /**
   * Refuses a deciding key's list naming a value no variant is for.
   *
   * The variants say the values a deciding key may hold; a list the key
   * declares itself narrows them, keeping fewer, and never names one more:
   * a value with no variant would choose nothing.
   *
   * Decision: see docs/decisions.md#variants-not-slots.
   *
   * @param \Drupal\surface\SurfaceBuilderInterface $builder
   *   The builder, holding the deciding key as declared and extended.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The resource or structure being built.
   * @param string $key
   *   The key the variants sit at.
   * @param string $by
   *   The deciding key.
   * @param string[] $values
   *   The values a variant is declared or derived for, offered or
   *   withheld from this account.
   *
   * @throws \LogicException
   *   Naming the key and each value no variant is for.
   */
  protected static function assertListNarrowsVariants(SurfaceBuilderInterface $builder, ResourceDefinition $definition, string $key, string $by, array $values): void {
    $deciding = $builder->getDefinition($by);
    $set = $deciding === NULL ? NULL : ChoiceSet::of($deciding);
    if ($set === NULL) {
      return;
    }
    $unfilled = array_values(array_diff(array_map('strval', $set->values), $values));
    if ($unfilled !== []) {
      throw new \LogicException(sprintf(
        '%s lists %s for "%s", and no variant at "%s" is for %s. A deciding key\'s list narrows the values its variants are for and names no other: declare a #[Variant] for %s, or leave the list out and let the variants say the values.',
        $definition->id,
        '"' . implode('", "', $unfilled) . '"',
        $by,
        $key,
        count($unfilled) === 1 ? 'it' : 'them',
        count($unfilled) === 1 ? 'it' : 'each',
      ));
    }
  }

  /**
   * Gets what each variant calls its value.
   *
   * Its #[Variant]'s label, the choice's word, else what the structure
   * itself is called (ResourceDefinition::label()), so a structure that
   * names itself is not named again on each #[Variant].
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param array<string, string> $children
   *   The variants, by the value each is for: a class, or the name a
   *   derived one is built by.
   *
   * @return array<string, string|\Stringable>
   *   The labels, by value; a variant that says none either way is left
   *   out.
   */
  protected function variantLabels(array $children): array {
    $labels = [];
    foreach ($children as $value => $child) {
      $attribute = class_exists($child) ? ((new \ReflectionClass($child))->getAttributes(Variant::class)[0] ?? NULL) : NULL;
      $label = $attribute?->newInstance()->label ?? ($this->registry->hasDefinition($child) ? $this->registry->getDefinition($child)->label() : NULL);
      if ($label !== NULL) {
        $labels[(string) $value] = $label;
      }
    }
    return $labels;
  }

  /**
   * Builds one resource or structure, or one child inside its parent.
   *
   * Through the whole sequence again: Resources::buildResource().
   *
   * @param string $resource
   *   The class, or its id.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   Where it is being asked for.
   * @param array $ancestry
   *   The parents it is built inside, outermost first.
   * @param string|\Stringable|null $locked
   *   Why every key is held to what is stored, or NULL.
   * @param bool $top
   *   TRUE for the resource an operation is asked of on its own.
   * @param array{resource: string, at: string, values: array<string, mixed>}|null $siteDefaults
   *   The defaults the site sets on its keys, from its parent's, or NULL.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The sealed data surface.
   */
  protected function buildResource(string $resource, OperationContext $context, array $ancestry, string|\Stringable|null $locked = NULL, bool $top = FALSE, ?array $siteDefaults = NULL): SurfaceInterface {
    return ($this->buildsResource)($resource, $context, $ancestry, $locked, $top, $siteDefaults);
  }

  /**
   * Builds a structure read from a schema once for every site it is at.
   *
   * Resources::buildRead(), which keeps it for the rest of the request.
   *
   * @param string $name
   *   The read structure's name.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The context it is invoked in.
   * @param array $ancestry
   *   Where it is first attached, for messages and the cycle check.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The sealed structure.
   */
  protected function buildRead(string $name, OperationContext $context, array $ancestry): SurfaceInterface {
    return ($this->buildsRead)($name, $context, $ancestry);
  }

  /**
   * Forgets the ports and keys of each read structure.
   *
   * For Resources::forgetBuilds(), when discovery itself was read again.
   */
  public function forget(): void {
    $this->readPorts = [];
    $this->noSecret = [];
  }

}

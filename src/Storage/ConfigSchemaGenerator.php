<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use Composer\Autoload\ClassLoader;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\Type\BooleanInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\TypedData\Type\StringInterface;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Entry;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Resource\ClosedSiteListInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\AutowiringClassResolver;
use Drupal\data_surface\ResourceBuild\ResourceCollectorPass;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\ResourcePlugins;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\Resources;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a config object's schema and install file from its resource.
 *
 * The resource is the one description of a config object; its config
 * schema and the values it is installed with are derived from it, until
 * core can read a data definition as the schema. For every resource whose
 * storage is a ConfigObjectStorage of an object its own module provides
 * (another module's object keeps its owner's schema, which the resource is
 * held to), sealed as declared:
 *
 * - `config/schema/<module>.schema.yml` gains the object's entry, a
 *   `config_object` that is fully validatable, its mapping in declaration
 *   order: each key's type from its definition (a multi-line string is
 *   still a `string`), its owner's label, `nullable: true` where null
 *   clears it by the three-way rule (an optional key that is no secret and
 *   that the storage class does not name in ALWAYS_HELD, or a required one
 *   installed holding nothing), the bounds and patterns a schema can carry
 *   (Length, Range, Regex, Count, NotBlank; a list of values is left to the
 *   resource, which keeps a value a list no longer offers as stale rather
 *   than refusing the object), a map as a `mapping`, a
 *   list as a `sequence`, keys from the site as a `sequence` of what each
 *   entry is, and variants as a type their deciding key names, each
 *   variant's mapping written in the module declaring it.
 * - Keys another module mounts are the owner's `third_party_settings`, a
 *   sequence typed `<config>.third_party.[%key]`, and the mounting
 *   module's own `<config>.third_party.<module>` entry, every key of it
 *   `requiredKey: false`, since a mounted key is stored only once sent.
 * - `config/install/<config>.yml` holds every key the object stores, in
 *   declaration order: the value the file already gives it, which is what
 *   a site starts from and may differ from the default; its declared
 *   default, for a key the file does not give; nothing for one with
 *   neither (an empty list for a list).
 *
 * For every resource whose storage is a ConfigEntityStorage over an
 * entity type the resource's own module declares, the entity type's entry,
 * `<module>.<config prefix>.*`, a `config_entity` that is fully
 * validatable, its mapping each key the storage maps to a property, by the
 * property's name, written by the same rules; its install files
 * are the things a module ships, which no resource describes, so none is
 * written. An entity type of core or of another module has a schema of
 * its own.
 *
 * A file's entries the resource does not describe are kept as they are,
 * and so are the comments above an entry; each written entry says it was
 * generated on the line above it. What the resource does not say, the
 * object's own label and a variant's when it declares none, is kept from
 * the file too. Writing nothing itself: files() answers what each file
 * would hold, and the Drush command and the drift test compare or write.
 *
 * @see \Drupal\data_surface\Drush\Commands\ConfigSchemaCommand
 * @see docs/storage.md#a-schema-generated-from-the-resource
 */
final class ConfigSchemaGenerator {

  /**
   * The line above each entry this writes, with the resource's id.
   */
  public const GENERATED = '# Generated from the %s resource by drush data_surface:config-schema.';

  /**
   * The constraints a schema carries, and the options of each it keeps.
   */
  protected const CARRIED = [
    'Length' => ['min', 'max'],
    'Range' => ['min', 'max'],
    'Regex' => ['pattern', 'match'],
    'Count' => ['min', 'max'],
    'NotBlank' => ['allowNull'],
  ];

  /**
   * Config schema types a definition's data type is written as.
   */
  protected const TYPES = ['string', 'email', 'integer', 'float', 'boolean', 'uri', 'timestamp'];

  /**
   * The keys the storage of the resource being described always holds.
   *
   * @var string[]
   */
  protected array $alwaysHeld = [];

  /**
   * Constructs a ConfigSchemaGenerator.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   The registry.
   * @param \Drupal\data_surface\ResourceBuild\Resources $resources
   *   The build step, which seals each resource as declared.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, for where each module's files are.
   * @param string $root
   *   The Drupal root.
   * @param array<string, array{pathname: string}> $installed
   *   The installed modules, as the container was built for them, each
   *   with the path of its info file from the root.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface|null $container
   *   The container, which a module not installed takes its classes'
   *   arguments from; NULL reads installed modules alone.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface|null $classResolver
   *   The class resolver.
   * @param \Drupal\data_surface\ResourceBuild\ResourcePlugins|null $plugins
   *   The plugins that are their own structure.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, for the config entity types an installed
   *   module declares; NULL writes no entity type's entry.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly Resources $resources,
    protected readonly ModuleExtensionList $moduleList,
    protected readonly string $root,
    protected readonly array $installed = [],
    protected readonly ?ContainerInterface $container = NULL,
    protected readonly ?ClassResolverInterface $classResolver = NULL,
    protected readonly ?ResourcePlugins $plugins = NULL,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  /**
   * Gives a generator that also reads modules that are not installed.
   *
   * A module's schema and install file are read when it is installed, so
   * they are written before: each named module that is not installed has
   * its classes scanned from its src/ as discovery scans an installed
   * module's (ResourceCollectorPass::discover()), autoloaded from there,
   * and its alters, storage and access classes built as the container
   * would build them (AutowiringClassResolver). Nothing is installed and
   * no cache is written. An owner and a module mounting keys on it are
   * named together, so one run writes both, each seeing the other.
   *
   * Decision: see
   * docs/decisions.md#a-config-object-is-described-by-its-resource.
   *
   * @param string[] $modules
   *   The modules, installed or not.
   * @param bool $fromCode
   *   TRUE to read the named modules from their code even where they are
   *   installed: a resource class written since the container was built
   *   (drush generate surface:resource) is in no registry yet.
   *
   * @return static
   *   This generator when every one is installed and none is read again;
   *   otherwise one reading them beside the installed modules.
   *
   * @throws \InvalidArgumentException
   *   When no module of a name is there to read.
   */
  public function including(array $modules, bool $fromCode = FALSE): static {
    $missing = $fromCode ? array_values($modules) : array_values(array_diff($modules, array_keys($this->installed)));
    if ($missing === [] || $this->container === NULL || $this->classResolver === NULL) {
      return $this;
    }
    $absent = array_filter($missing, fn (string $module): bool => !isset($this->installed[$module]) && !$this->moduleList->exists($module));
    if ($absent !== []) {
      // A module written since the list was cached is not in it yet.
      $this->moduleList->reset();
      $absent = array_filter($absent, fn (string $module): bool => !$this->moduleList->exists($module));
    }
    if ($absent !== []) {
      throw new \InvalidArgumentException(sprintf('No module named %s is in the code base.', implode(', ', $absent)));
    }
    $scanned = $this->installed;
    $loader = new ClassLoader();
    foreach ($missing as $module) {
      $pathname = $this->installed[$module]['pathname'] ?? $this->moduleList->getPathname($module);
      $loader->addPsr4('Drupal\\' . $module . '\\', $this->root . '/' . dirname($pathname) . '/src');
      $scanned[$module] = ['pathname' => $pathname];
    }
    // As the kernel does for a module installed while it runs: a loader of
    // their own, since the main one may have cached a miss for a class.
    $loader->register();
    $resolver = new AutowiringClassResolver($this->classResolver, $this->container);
    $registry = new ResourceRegistry(ResourceCollectorPass::discover($scanned, $this->root . '/'), new NullBackend('data_surface_config_schema'), $this->plugins, $resolver);
    return new static($registry, $this->resources->withRegistry($registry, $resolver), $this->moduleList, $this->root, $scanned, entityTypeManager: $this->entityTypeManager);
  }

  /**
   * Lists the resources whose config schema this writes.
   *
   * @return array<string, \Drupal\data_surface\ResourceBuild\ResourceDefinition>
   *   Each resource stored in a simple config object, or in a config
   *   entity whose type its own module declares, by name.
   */
  public function resources(): array {
    $found = [];
    foreach ($this->registry->getDefinitions() as $name => $definition) {
      if ($definition->isDerived() || $definition->storage === NULL) {
        continue;
      }
      if (is_subclass_of($definition->storage, ConfigObjectStorage::class) && !static::owns($definition->module, (string) constant($definition->storage . '::CONFIG'))) {
        // Another module's object: its owner's schema describes it, and the
        // resource is held to that schema (HeldToConfigSchemaInterface).
        // Decision: see
        // docs/decisions.md#the-generator-writes-what-the-first-page-shows.
        continue;
      }
      if (is_subclass_of($definition->storage, ConfigObjectStorage::class) || $this->entityType($definition) !== NULL) {
        $found[(string) $name] = $definition;
      }
    }
    return $found;
  }

  /**
   * Gives the config entity type a resource is stored in, if its module's.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityTypeInterface|null
   *   The entity type its ConfigEntityStorage names, when the resource's
   *   module declares it; NULL for any other storage, and for an entity
   *   type of core or of another module, which has a schema of its own.
   */
  protected function entityType(ResourceDefinition $definition): ?ConfigEntityTypeInterface {
    if ($this->entityTypeManager === NULL || !is_subclass_of((string) $definition->storage, ConfigEntityStorage::class)) {
      return NULL;
    }
    $type = $this->entityTypeManager->getDefinition((string) constant($definition->storage . '::ENTITY_TYPE_ID'), FALSE);
    return $type instanceof ConfigEntityTypeInterface && $type->getProvider() === $definition->module ? $type : NULL;
  }

  /**
   * Lists the modules whose files this writes.
   *
   * @return string[]
   *   Each module owning such a resource, or writing an entry for one: a
   *   variant it declares, or keys it mounts.
   */
  public function modules(): array {
    $modules = [];
    foreach ($this->resources() as $definition) {
      foreach ($this->describe($definition)['entries'] as $entry) {
        $modules[$entry['module']] = TRUE;
      }
    }
    return array_keys($modules);
  }

  /**
   * Answers what some modules' schema and install files would hold.
   *
   * @param string[] $modules
   *   The modules.
   *
   * @return array<string, string>
   *   Each file this writes for them, by its path from the Drupal root,
   *   as it would be written.
   */
  public function files(array $modules): array {
    $schemas = [];
    $installs = [];
    foreach ($this->resources() as $definition) {
      $described = $this->describe($definition);
      foreach ($described['entries'] as $entry) {
        if (in_array($entry['module'], $modules, TRUE)) {
          $schemas[$entry['module']][$entry['name']] = $entry;
        }
      }
      if (in_array($definition->module, $modules, TRUE) && $described['install'] !== NULL) {
        $installs[$this->path($definition->module, 'config/install/' . $described['config'] . '.yml')] = $described['install'];
      }
    }
    $files = [];
    foreach ($schemas as $module => $entries) {
      $path = $this->path($module, 'config/schema/' . $module . '.schema.yml');
      $files[$path] = static::merge($this->read($path), $entries);
    }
    foreach ($installs as $path => $values) {
      $files[$path] = static::installFile($values);
    }
    ksort($files);
    return $files;
  }

  /**
   * Describes one resource's config object.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return array{config: string, entries: list<array{module: string, name: string, resource: string, schema: array}>, install: array|null}
   *   The object's name, each schema entry with the module it is written
   *   in, and the values it is installed with; for a config entity type,
   *   the name of its entry and no install values.
   */
  public function describe(ResourceDefinition $definition): array {
    $type = $this->entityType($definition);
    if ($type !== NULL) {
      return $this->describeEntityType($definition, $type);
    }
    $config = (string) constant($definition->storage . '::CONFIG');
    $built = $this->resources->declaration($definition->name(), $this->context($definition));
    $existing = $this->existing($definition->module, $config);
    $always_held = array_map('strval', (array) constant($definition->storage . '::ALWAYS_HELD'));
    return $this->describeBuilt($built, $definition, $config, $always_held, $existing['schema'], $existing['install']);
  }

  /**
   * Describes the config object a sealed resource is stored in.
   *
   * What describe() writes for a resource discovery found, for one built
   * any other way: the resource generator builds what it is about to
   * write before any class of it exists, and writes its schema and install
   * file from this, the same mapping the command uses.
   *
   * Decision: see
   * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource, sealed as declared.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $owner
   *   What it was built from: its id names the entry, its module the file.
   * @param string $config
   *   The config object.
   * @param string[] $always_held
   *   The optional keys its storage always holds, by dotted path.
   * @param array $schema
   *   The entries the module's schema file holds now, by name, for what
   *   the resource does not say: the object's label.
   * @param array $install
   *   What the object's install file holds now.
   *
   * @return array{config: string, entries: list<array{module: string, name: string, resource: string, schema: array}>, install: array}
   *   The object's name, each schema entry with the module it is written
   *   in, and the values it is installed with.
   */
  public function describeBuilt(DataSurfaceInterface $built, ResourceDefinition $owner, string $config, array $always_held, array $schema, array $install): array {
    $this->alwaysHeld = $always_held;
    $entries = [];
    $installed = $this->install($built, $install);
    $mapping = $this->mapping($built->getDefinitions(), $config, $owner, $entries, $schema, TRUE, installed: $installed);
    array_unshift($entries, [
      'module' => $owner->module,
      'name' => $config,
      'resource' => $owner->id,
      'schema' => [
        'type' => 'config_object',
        'label' => $schema[$config]['label'] ?? $owner->id,
        'constraints' => ['FullyValidatable' => NULL],
        'mapping' => $mapping,
      ],
    ]);
    return [
      'config' => $config,
      'entries' => $entries,
      'install' => $installed,
    ];
  }

  /**
   * Answers whether a config object is a module's own, by core's rule.
   *
   * @param string $module
   *   The module.
   * @param string $config
   *   The object's name, whose first part is the module that provides it.
   *
   * @return bool
   *   TRUE when the module provides it.
   */
  public static function owns(string $module, string $config): bool {
    return explode('.', $config, 2)[0] === $module;
  }

  /**
   * Describes a config entity type a resource is stored in.
   *
   * Decision: see
   * docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   * @param \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $type
   *   The entity type its storage names, which its module declares.
   *
   * @return array{config: string, entries: list<array{module: string, name: string, resource: string, schema: array}>, install: null}
   *   The entry's name, `<config prefix>.*`, each schema entry with the
   *   module it is written in, and no install values.
   */
  protected function describeEntityType(ResourceDefinition $definition, ConfigEntityTypeInterface $type): array {
    $config = $type->getConfigPrefix() . '.*';
    $built = $this->resources->declaration($definition->name(), $this->context($definition));
    $existing = $this->existing($definition->module, $config);
    $this->alwaysHeld = array_map('strval', (array) constant($definition->storage . '::ALWAYS_HELD'));
    $storage = (string) $definition->storage;
    assert(is_subclass_of($storage, ConfigEntityStorage::class));
    $properties = array_filter($storage::properties(), 'is_string');
    $entries = [];
    $written = $this->mapping($built->getDefinitions(), $type->getConfigPrefix(), $definition, $entries, $existing['schema']);
    $mapping = [];
    foreach ($properties as $key => $property) {
      if (isset($written[$key])) {
        $mapping[$property] = $written[$key];
      }
    }
    array_unshift($entries, [
      'module' => $definition->module,
      'name' => $config,
      'resource' => $definition->id,
      'schema' => [
        'type' => 'config_entity',
        'label' => $existing['schema'][$config]['label'] ?? (string) $type->getLabel(),
        'constraints' => ['FullyValidatable' => NULL],
        'mapping' => $mapping,
      ],
    ]);
    return [
      'config' => $config,
      'entries' => $entries,
      'install' => NULL,
    ];
  }

  /**
   * Gives the context a resource is described in.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $definition
   *   The resource.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   Its first operation that needs nothing and takes the resource's own
   *   keys, or the plugin host's verb.
   */
  protected function context(ResourceDefinition $definition): OperationContext {
    foreach ($this->registry->getOperations($definition->name()) as $operation) {
      if ($operation->needsNothing() && $operation->structure === NULL) {
        return $this->resources->operation($definition->name(), $operation->id);
      }
    }
    return new OperationContext(ContractBaselines::ALONE);
  }

  /**
   * Writes the mapping of a map of keys.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param string $prefix
   *   The schema type every named type below starts with.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $owner
   *   The resource.
   * @param array $entries
   *   The named types written for other parts, added to.
   * @param array $existing
   *   The entries the files already hold, by name, for what the resource
   *   does not say.
   * @param bool $top
   *   TRUE for the object's own keys, where other modules mount theirs.
   * @param bool $absent
   *   TRUE where each key may be absent: a module's mounted keys, which
   *   are stored once sent, and the keys of an entry from the site.
   * @param array|null $installed
   *   What the object is installed with at this map; NULL where it is
   *   installed with nothing (a variant not chosen, a mount).
   * @param string[] $ruledOut
   *   The keys of the map a rule of its container may rule out.
   * @param string $at
   *   Where the map is stored in the object, a dotted path and a dot;
   *   empty at the top.
   *
   * @return array
   *   The mapping.
   */
  protected function mapping(DefinitionMap $map, string $prefix, ResourceDefinition $owner, array &$entries, array $existing, bool $top = FALSE, bool $absent = FALSE, ?array $installed = NULL, array $ruledOut = [], string $at = ''): array {
    $mapping = [];
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if ($top && $name === ConfigObjectStorage::THIRD_PARTY) {
        $mapping[$name] = $this->thirdParty($entry, $prefix, $owner, $entries, $existing);
        continue;
      }
      $property = $this->entry($entry, $prefix . '.' . $name, $owner, $entries, $existing, $entry->variants === NULL ? NULL : $map->get($entry->variants->by), $installed, $at . $name);
      if (in_array($name, $ruledOut, TRUE) || in_array('', $entry->mayNotApply(), TRUE)) {
        // A rule holds only where the key applies; where it does not, the
        // key keeps what it holds, or what it was installed with.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        unset($property['constraints']);
      }
      if ($absent) {
        $property = static::after($property, 'nullable', ['requiredKey' => FALSE]);
      }
      $mapping[$name] = $property;
    }
    return $mapping;
  }

  /**
   * Writes one key.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The key.
   * @param string $path
   *   The schema type a named part of it is called.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $owner
   *   The resource.
   * @param array $entries
   *   The named types written for other parts, added to.
   * @param array $existing
   *   The entries the files already hold, by name.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $deciding
   *   The deciding key, for a key with variants.
   * @param array|null $installed
   *   What the object is installed with at the key's map, or NULL.
   * @param string $at
   *   Where the key is stored in the object, a dotted path.
   *
   * @return array
   *   The key's schema.
   */
  protected function entry(Entry $entry, string $path, ResourceDefinition $owner, array &$entries, array $existing, ?DataDefinitionInterface $deciding = NULL, ?array $installed = NULL, string $at = ''): array {
    $held = $installed === NULL ? NULL : ($installed[$entry->name] ?? NULL);
    $definition = $entry->definition;
    $label = static::label($definition, $entry->name);
    $from = $entry->variants === NULL ? NULL : DefinitionMetadata::variantsFrom($entry->variants->shell);
    if ($from !== NULL && !is_subclass_of($from, ClosedSiteListInterface::class)) {
      // Variants from the site: which there are is the site's, not the
      // code's, so no schema written once can name them. The key is open
      // here, and the variant its deciding key chooses holds it to its own
      // keys before anything is written.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      return ['type' => 'ignore', 'label' => $label];
    }
    if ($entry->variants !== NULL) {
      $by = $entry->variants->by;
      $labels = [];
      foreach ($deciding === NULL ? [] : (ChoiceSet::of($deciding)->labels ?? []) as $value => $choice) {
        $labels[(string) $value] = $choice instanceof TranslatableMarkup ? strtr($choice->getUntranslatedString(), array_map('strval', $choice->getArguments())) : (string) $choice;
      }
      $variants = [];
      if ($from !== NULL) {
        // A closed provider: every structure it may list, whichever this
        // site offers now.
        // Decision: see
        // docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
        foreach ($from::structures() as $value => $class) {
          $source = $this->registry->getDefinition($class);
          $declared = $this->resources->declaration($source->name(), $this->context($owner));
          $variants[(string) $value] = [$declared->getDefinitions(), $source];
        }
      }
      else {
        foreach ($entry->variants->variants as $value => $variant) {
          $source = $variant->source !== NULL && $this->registry->hasDefinition($variant->source) ? $this->registry->getDefinition($variant->source) : NULL;
          $variants[(string) $value] = [$variant->child->getDefinitions(), $source];
        }
      }
      foreach ($variants as $value => [$map, $source]) {
        $name = $path . '.' . $value;
        $entries[] = [
          'module' => $source->module ?? $owner->module,
          'name' => $name,
          'resource' => $owner->id,
          'schema' => [
            'type' => 'mapping',
            'label' => $labels[$value] ?? $existing[$name]['label'] ?? $value,
            'constraints' => ['FullyValidatable' => NULL],
            'mapping' => $this->mapping($map, $name, $owner, $entries, $existing, installed: is_array($held) && $entry->variants->chosen($installed[$by] ?? NULL) === $value ? $held : NULL, at: $at . '.') + $this->keptSecrets($value, $variants),
          ],
        ];
      }
      return ['type' => $path . '.[%parent.' . $by . ']', 'label' => $label];
    }
    if ($entry->list !== NULL) {
      return [
        'type' => 'sequence',
        'label' => $label,
      ] + ($this->clearable($definition, $at) ? ['nullable' => TRUE] : []) + static::constraints($definition) + [
        'sequence' => [
          'type' => 'mapping',
          'label' => $label,
          'mapping' => $this->mapping($entry->list->child()->getDefinitions(), $path, $owner, $entries, $existing),
        ],
      ];
    }
    if ($entry->attachment !== NULL) {
      $each = DefinitionMetadata::eachOf($definition);
      if (is_string($each)) {
        $child = $this->resources->declaration($each, $this->context($owner));
        return [
          'type' => 'sequence',
          'label' => $label,
          'sequence' => [
            'type' => 'mapping',
            'label' => $label,
            // An entry is stored as it was accepted, so a key of it may be
            // absent.
            'mapping' => $this->mapping($child->getDefinitions(), $path, $owner, $entries, $existing, absent: TRUE),
          ],
        ];
      }
      if ($each instanceof DataDefinitionInterface) {
        // Each entry is a key of its own, which may hold nothing as one
        // would.
        return [
          'type' => 'sequence',
          'label' => $label,
          'sequence' => $this->definition($each, $label),
        ];
      }
      return [
        'type' => 'mapping',
        'label' => $label,
        'mapping' => $this->mapping($entry->attachment->child->getDefinitions(), $path, $owner, $entries, $existing, installed: is_array($held) ? $held : NULL, at: $at . '.'),
      ];
    }
    return $this->definition($definition, $label, installedEmpty: $installed !== NULL && $held === NULL, at: $at);
  }

  /**
   * Writes the secrets of other variants that a variant's map keeps.
   *
   * A secret is kept as stored when its variant is not chosen, beside the
   * chosen variant's keys, so each variant's mapping says it may be
   * there: of its own type, never null, and absent until it is kept.
   *
   * Decision: see
   * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
   *
   * @param string $value
   *   The variant whose mapping is written.
   * @param array<string, array{0: \Drupal\data_surface\DefinitionMap, 1: mixed}> $variants
   *   Every variant's keys, by value.
   *
   * @return array
   *   The mapping of the secrets other variants keep here, by key.
   */
  protected function keptSecrets(string $value, array $variants): array {
    $own = $variants[$value][0];
    $kept = [];
    foreach ($variants as $other => [$map]) {
      if ((string) $other === $value) {
        continue;
      }
      foreach ($map->entries() as $name => $entry) {
        $name = (string) $name;
        if (!$own->has($name) && !isset($kept[$name]) && DefinitionMetadata::isSecret($entry->definition)) {
          $kept[$name] = $this->definition($entry->definition, static::label($entry->definition, $name)) + ['requiredKey' => FALSE];
        }
      }
    }
    return $kept;
  }

  /**
   * Writes the owner's map of mounted keys, and each module's entry.
   *
   * @param \Drupal\data_surface\Entry $entry
   *   The third_party_settings key.
   * @param string $config
   *   The config object.
   * @param \Drupal\data_surface\ResourceBuild\ResourceDefinition $owner
   *   The resource.
   * @param array $entries
   *   The named types written for other parts, added to.
   * @param array $existing
   *   The entries the files already hold, by name.
   *
   * @return array
   *   The owner's sequence.
   */
  protected function thirdParty(Entry $entry, string $config, ResourceDefinition $owner, array &$entries, array $existing): array {
    $definition = $entry->definition;
    $ruled = [];
    foreach ($entry->mayNotApply() as $path) {
      if (str_contains($path, '.')) {
        [$module, $key] = explode('.', $path, 2);
        $ruled[$module][] = $key;
      }
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $module => $part) {
        $name = $config . '.third_party.' . $module;
        $mounted = $entry->mounted[(string) $module] ?? NULL;
        $mapping = $mounted !== NULL
          ? $this->mapping($mounted->child->getDefinitions(), $name, $owner, $entries, $existing, absent: TRUE, ruledOut: $ruled[(string) $module] ?? [], at: ConfigObjectStorage::THIRD_PARTY . '.' . $module . '.')
          : $this->plainMapping($part, TRUE);
        $entries[] = [
          'module' => (string) $module,
          'name' => $name,
          'resource' => $owner->id,
          'schema' => [
            'type' => 'mapping',
            'label' => static::label($part, (string) $module),
            'constraints' => ['FullyValidatable' => NULL],
            'mapping' => $mapping,
          ],
        ];
      }
    }
    return [
      'type' => 'sequence',
      'label' => 'Third party settings',
      'requiredKey' => FALSE,
      'sequence' => ['type' => $config . '.third_party.[%key]'],
    ];
  }

  /**
   * Writes the mapping of a map definition no entry describes.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map.
   * @param bool $mounted
   *   TRUE for a module's mounted keys, each of which may be absent.
   *
   * @return array
   *   The mapping.
   */
  protected function plainMapping(DataDefinitionInterface $definition, bool $mounted = FALSE): array {
    $mapping = [];
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $written = $this->definition($property, static::label($property, (string) $name));
        $mapping[(string) $name] = $mounted ? static::after($written, 'nullable', ['requiredKey' => FALSE]) : $written;
      }
    }
    return $mapping;
  }

  /**
   * Writes one definition: a single value, a list or a map.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $label
   *   Its label.
   * @param bool $item
   *   TRUE for a list's item, which is never null.
   * @param bool $installedEmpty
   *   TRUE when the object is installed holding nothing for it.
   * @param string|null $at
   *   Where it is stored in the object, a dotted path; NULL where the
   *   storage cannot say it always holds it.
   *
   * @return array
   *   Its schema.
   */
  protected function definition(DataDefinitionInterface $definition, string $label, bool $item = FALSE, bool $installedEmpty = FALSE, ?string $at = NULL): array {
    if ($definition instanceof ListDataDefinitionInterface) {
      $items = $definition->getItemDefinition();
      return ['type' => 'sequence', 'label' => $label] + (!$item && $this->clearable($definition, $at) ? ['nullable' => TRUE] : []) + static::constraints($definition) + [
        'sequence' => $this->definition($items, static::label($items, $label), TRUE),
      ];
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      return ['type' => 'mapping', 'label' => $label, 'mapping' => $this->plainMapping($definition)];
    }
    if ($definition->getDataType() === 'any') {
      return ['type' => 'ignore', 'label' => $label];
    }
    $schema = ['type' => static::type($definition), 'label' => $label];
    // Null clears an optional key, unless its storage always holds it; a
    // required key holds nothing only where the object is installed with
    // nothing for it.
    // Decision: see
    // docs/decisions.md#a-config-object-is-described-by-its-resource.
    if (!$item && (($installedEmpty && !DefinitionMetadata::hasDefaultValue($definition)) || $this->clearable($definition, $at))) {
      $schema['nullable'] = TRUE;
    }
    return $schema + static::constraints($definition);
  }

  /**
   * Answers whether null may clear a key, by the three-way rule.
   *
   * An optional key, unless it is a secret, which null leaves as it is,
   * or one its storage always holds (the storage class's ALWAYS_HELD).
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param string|null $at
   *   Where it is stored in the object; NULL where the storage cannot
   *   name it.
   *
   * @return bool
   *   TRUE when its schema says `nullable: true`.
   */
  protected function clearable(DataDefinitionInterface $definition, ?string $at): bool {
    return !$definition->isRequired()
      && !DefinitionMetadata::isSecret($definition)
      && ($at === NULL || !in_array($at, $this->alwaysHeld, TRUE));
  }

  /**
   * Gives the config schema type a single value is written as.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   Its own type when core's config schema has it, or the primitive
   *   its typed data class holds.
   */
  protected static function type(DataDefinitionInterface $definition): string {
    $type = $definition->getDataType();
    if (in_array($type, self::TYPES, TRUE)) {
      return $type;
    }
    $class = ltrim((string) $definition->getClass(), '\\');
    return match (TRUE) {
      is_a($class, BooleanInterface::class, TRUE) => 'boolean',
      is_a($class, IntegerInterface::class, TRUE) => 'integer',
      is_a($class, FloatInterface::class, TRUE) => 'float',
      is_a($class, StringInterface::class, TRUE) => 'string',
      default => 'ignore',
    };
  }

  /**
   * Writes the constraints of a definition a schema can carry.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   `['constraints' => ...]`, or nothing when it has none to carry.
   */
  protected static function constraints(DataDefinitionInterface $definition): array {
    $declared = $definition instanceof DataDefinition ? ($definition->toArray()['constraints'] ?? []) : [];
    $carried = [];
    foreach (is_array($declared) ? $declared : [] as $name => $options) {
      $name = (string) $name;
      if (!isset(self::CARRIED[$name])) {
        continue;
      }
      $options = is_array($options) ? $options : [];
      $kept = array_intersect_key($options, array_flip(self::CARRIED[$name]));
      if ($name === 'Regex' && ($kept['match'] ?? FALSE) !== FALSE) {
        unset($kept['match']);
      }
      $carried[$name] = $kept === [] ? NULL : $kept;
    }
    return $carried === [] ? [] : ['constraints' => $carried];
  }

  /**
   * Writes the values a config object is installed with.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource.
   * @param array $existing
   *   What the install file holds now.
   *
   * @return array
   *   The values.
   */
  protected function install(DataSurfaceInterface $built, array $existing): array {
    return $this->installMap($built->getDefinitions(), $built, $existing);
  }

  /**
   * Writes the values a map of keys starts from.
   *
   * @param \Drupal\data_surface\DefinitionMap $map
   *   The keys.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   What holds them, for its defaults.
   * @param array $existing
   *   What the file holds for them now.
   *
   * @return array
   *   The values.
   */
  protected function installMap(DefinitionMap $map, DataSurfaceInterface $surface, array $existing): array {
    // In declaration order, the order core keeps a config object in once
    // its schema lists the keys so.
    $values = [];
    foreach ($map->entries() as $name => $entry) {
      $name = (string) $name;
      if ($name === ConfigObjectStorage::THIRD_PARTY) {
        // Stored once a module's keys are sent: installed only where the
        // file says so.
        if (array_key_exists($name, $existing)) {
          $values[$name] = $existing[$name];
        }
        continue;
      }
      $held = array_key_exists($name, $existing) ? $existing[$name] : NULL;
      $definition = $entry->definition;
      if ($entry->variants !== NULL) {
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? $surface->getDefault($entry->variants->by));
        $values[$name] = $chosen === NULL ? $held : $this->installMap($entry->variants->variant($chosen)->child->getDefinitions(), $entry->variants->variant($chosen)->child, is_array($held) ? $held : []);
      }
      elseif ($entry->attachment !== NULL && DefinitionMetadata::eachOf($definition) === NULL) {
        $values[$name] = $this->installMap($entry->attachment->child->getDefinitions(), $entry->attachment->child, is_array($held) ? $held : []);
      }
      elseif (array_key_exists($name, $existing)) {
        // What a site starts from may differ from what a key starts from
        // where nothing is stored: the file says the first.
        $values[$name] = $held;
      }
      elseif (DefinitionMetadata::hasDefaultValue($definition)) {
        $values[$name] = DefinitionMetadata::getDefaultValue($definition);
      }
      elseif ($entry->list !== NULL || $definition instanceof ListDataDefinitionInterface || DefinitionMetadata::eachOf($definition) !== NULL) {
        $values[$name] = [];
      }
      else {
        $values[$name] = NULL;
      }
    }
    return $values;
  }

  /**
   * Reads what a module's files say about a config object now.
   *
   * @param string $module
   *   The module owning the object.
   * @param string $config
   *   The object.
   *
   * @return array{schema: array, install: array}
   *   Every entry of the module's schema file, by name, and the object's
   *   install file.
   */
  protected function existing(string $module, string $config): array {
    $schema = $this->read($this->path($module, 'config/schema/' . $module . '.schema.yml'));
    $install = $this->read($this->path($module, 'config/install/' . $config . '.yml'));
    return [
      'schema' => $schema === '' ? [] : (array) DrupalYaml::decode($schema),
      'install' => $install === '' ? [] : (array) DrupalYaml::decode($install),
    ];
  }

  /**
   * Gives a file's path in a module, from the Drupal root.
   *
   * @param string $module
   *   The module.
   * @param string $file
   *   The file, from the module's directory.
   *
   * @return string
   *   The path.
   */
  protected function path(string $module, string $file): string {
    return $this->moduleList->getPath($module) . '/' . $file;
  }

  /**
   * Reads a file, from the Drupal root.
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   What it holds; empty when it is not there.
   */
  protected function read(string $path): string {
    $file = $this->root . '/' . $path;
    return is_file($file) ? (string) file_get_contents($file) : '';
  }

  /**
   * Writes an install file's values as YAML, the way Drupal's own are.
   *
   * @param array $values
   *   The values the object is installed with.
   *
   * @return string
   *   The file.
   */
  public static function installFile(array $values): string {
    return $values === [] ? "{  }\n" : Yaml::dump($values, PHP_INT_MAX, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
  }

  /**
   * Lays generated entries into a schema file, keeping the rest.
   *
   * A file is a header, then entries, each its comment lines and its
   * top-level key's lines. An entry generated now replaces the one of its
   * name, under that one's comments, the generated line among them
   * written once; a new one is added at the end; every other is kept.
   *
   * @param string $file
   *   The file as it is; empty for none.
   * @param array<string, array{module: string, name: string, resource: string, schema: array}> $entries
   *   The entries, by name.
   *
   * @return string
   *   The file as it would be written.
   */
  public static function merge(string $file, array $entries): string {
    $header = [];
    $blocks = [];
    $comments = [];
    $current = NULL;
    foreach (explode("\n", rtrim($file, "\n")) as $line) {
      if ($file === '') {
        break;
      }
      if (preg_match('/^([^\s#][^:]*):/', $line, $match)) {
        $current = $match[1];
        $blocks[$current] = ['comments' => $comments, 'lines' => [$line]];
        $comments = [];
        continue;
      }
      if (str_starts_with($line, '#')) {
        $comments[] = $line;
        continue;
      }
      if ($current === NULL) {
        // Before the first entry: a comment closed by a blank line heads
        // the file.
        if (trim($line) === '' && $comments !== []) {
          $header = [...$header, ...$comments, ''];
          $comments = [];
        }
        continue;
      }
      if (trim($line) === '') {
        // A blank line ends the comments above the next entry.
        $comments = [];
        continue;
      }
      $blocks[$current]['lines'] = [...$blocks[$current]['lines'], ...$comments, $line];
      $comments = [];
    }
    foreach ($entries as $name => $entry) {
      $kept = array_values(array_filter($blocks[$name]['comments'] ?? [], static fn (string $line): bool => !preg_match('/^# Generated from the \S+ resource by drush data_surface:config-schema\.$/', $line)));
      $blocks[$name] = [
        'comments' => [...$kept, sprintf(self::GENERATED, $entry['resource'])],
        'lines' => explode("\n", rtrim(static::dumpEntry($name, $entry['schema']), "\n")),
      ];
    }
    $out = [];
    foreach ($blocks as $block) {
      $out[] = implode("\n", [...$block['comments'], ...$block['lines']]);
    }
    return ($header === [] ? '' : implode("\n", $header) . "\n") . implode("\n\n", $out) . "\n";
  }

  /**
   * Writes one schema entry as YAML, the way Drupal's own are written.
   *
   * @param string $name
   *   The entry's name.
   * @param array $schema
   *   The entry.
   *
   * @return string
   *   Its lines.
   */
  public static function dumpEntry(string $name, array $schema): string {
    return $name . ":\n" . static::dumpMap($schema, 1);
  }

  /**
   * Writes a map's lines at a depth.
   *
   * @param array $map
   *   The map.
   * @param int $depth
   *   How far in it is.
   *
   * @return string
   *   Its lines.
   */
  protected static function dumpMap(array $map, int $depth): string {
    $pad = str_repeat('  ', $depth);
    $out = '';
    foreach ($map as $key => $value) {
      if (is_array($value) && $value === []) {
        $out .= $pad . $key . ": {  }\n";
      }
      elseif (is_array($value) && array_is_list($value)) {
        $out .= $pad . $key . ': ' . Yaml::dump($value, 0) . "\n";
      }
      elseif (is_array($value)) {
        $out .= $pad . $key . ":\n" . static::dumpMap($value, $depth + 1);
      }
      elseif ($value === NULL) {
        $out .= $pad . $key . ": ~\n";
      }
      elseif ($key === 'label') {
        $out .= $pad . $key . ": '" . str_replace("'", "''", (string) $value) . "'\n";
      }
      else {
        $out .= $pad . $key . ': ' . Yaml::dump($value) . "\n";
      }
    }
    return $out;
  }

  /**
   * Inserts keys into a schema after one, or at the end.
   *
   * @param array $schema
   *   The schema.
   * @param string $after
   *   The key to place them after, when it is there.
   * @param array $keys
   *   The keys.
   *
   * @return array
   *   The schema.
   */
  protected static function after(array $schema, string $after, array $keys): array {
    $position = array_search($after, array_keys($schema), TRUE);
    if ($position === FALSE) {
      $position = array_search('label', array_keys($schema), TRUE);
    }
    $position = $position === FALSE ? count($schema) : $position + 1;
    return array_slice($schema, 0, $position, TRUE) + $keys + array_slice($schema, $position, NULL, TRUE);
  }

  /**
   * Gives a definition's label as plain text, in the language it is written.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param string $fallback
   *   What to call it when it has none.
   *
   * @return string
   *   The label.
   */
  protected static function label(DataDefinitionInterface $definition, string $fallback): string {
    $label = DefinitionMetadata::ownLabel($definition);
    if ($label instanceof TranslatableMarkup) {
      return strtr($label->getUntranslatedString(), array_map('strval', $label->getArguments()));
    }
    return $label === NULL || (string) $label === '' ? $fallback : (string) $label;
  }

}

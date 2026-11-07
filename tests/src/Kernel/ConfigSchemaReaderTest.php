<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Pipeline\TypeMismatchException;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Reader\ConfigSchemaReader;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Storage\FlatVariantsLayout;
use Drupal\surface\Storage\HeldToConfigSchemaTrait;
use Drupal\surface\Storage\StoredSchemaInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Variants;
use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface_reader_test\Resource\ConditionHostResource;
use Drupal\surface_reader_test\Storage\ConditionHostStorage;
use Drupal\Tests\surface\Kernel\Reader\RecordingBlueprint;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\SchemaRoundTripTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests config schema read straight into the blueprint, beside the JSON route.
 *
 * Three hand-written providers write a plugin's config schema as JSON
 * Schema (ConfigSchemaDocument) for the schema reader: the field
 * example's storage settings provider, and the block placement's block
 * and condition providers as they were before they read config schema
 * directly, kept for this test in surface_reader_test
 * (BlockPluginJsonStructures, ConditionPluginJsonStructures).
 * ConfigSchemaTypes lists the same variants as structures
 * ConfigSchemaReader reads from the schema itself. For three variants of
 * each, the full schema of the direct route states everything the JSON
 * route states, but the defaults the providers read from plugin
 * instances and the context mapping the condition provider adds, and
 * each direct structure is held to the config schema it came from with
 * nothing wider.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaReaderTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
  use SchemaRoundTripTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'text',
    'options',
    'serialization',
    'surface',
    'surface_examples_block',
    'surface_examples_field',
    'surface_reader_test',
  ];

  /**
   * Each pair: the JSON route's structure, the direct one's, and the schema.
   *
   * The schema type each variant value names, with `%s` for the value.
   */
  protected const PAIRS = [
    'block' => ['reader_test.block_json', 'reader_test.block_direct', 'block.settings.%s'],
    'field storage' => ['reader_test.storage_json', 'reader_test.storage_direct', 'field.storage_settings.%s'],
    'condition' => ['reader_test.condition_json', 'reader_test.condition_direct', 'condition.plugin.%s'],
  ];

  /**
   * What only the JSON route says, and why the direct route cannot.
   *
   * By pair, variant and key path.
   */
  protected const EXPECTED_LESS = [
    'condition user_role context_mapping' => 'The condition provider adds the contexts the site can give a condition: site state, which no schema says.',
  ];

  /**
   * Where the check calls a direct structure wider, and why it is not.
   *
   * The check reads no `requiredKey: false`: a key the schema lets be
   * absent may be cleared, which stores it absent.
   */
  protected const EXPECTED_WIDER = [
    'block system_menu_block:main' => ['ignore_active_trail' => 'it may be cleared, which the schema refuses: boolean is not nullable'],
  ];

  /**
   * The file the differences are written to.
   *
   * In the directory SURFACE_READER_REPORT names, when it names one.
   */
  protected const REPORT = 'config-schema-comparison.json';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * Tests the direct route never says less than the JSON route.
   */
  public function testTheDirectRouteSaysNoLess(): void {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $report = [];
    $unexplained = [];
    foreach (self::PAIRS as $pair => [$json_id, $direct_id]) {
      $json = $this->full($json_id);
      $direct = $this->full($direct_id);
      $json_variants = $json['properties']['settings']['x-surface']['variants'];
      $direct_variants = $direct['properties']['settings']['x-surface']['variants'];
      $this->assertSame(array_keys($json_variants), array_keys($direct_variants), $pair . ': the same variants.');
      foreach ($json_variants as $value => $pointer) {
        $a = $this->def($json, $pointer);
        $b = $this->def($direct, $direct_variants[$value]);
        $differences = [];
        $this->compare($a, $b, '', $differences);
        foreach ($differences as $difference) {
          $report[$pair][$value][] = $difference;
          if ($difference['side'] === 'json') {
            $why = self::EXPECTED_LESS[$pair . ' ' . $value . ' ' . $difference['path']] ?? NULL;
            if ($why === NULL) {
              $unexplained[] = $pair . ' ' . $value . ': ' . $difference['path'] . ' ' . $difference['what'];
            }
          }
        }
      }
    }
    if (getenv('SURFACE_READER_REPORT')) {
      // Every difference, for a reader comparing the two routes.
      file_put_contents(getenv('SURFACE_READER_REPORT') . '/' . self::REPORT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $this->assertSame([], $unexplained, 'The direct route says everything the JSON route says, but what it cannot know.');
    $this->assertNotEmpty($report, 'And it says more.');
    unset($resources, $emitter);
  }

  /**
   * Tests each direct structure is held to the schema it was read from.
   */
  public function testEachDirectStructureIsHeldToItsSchema(): void {
    $resources = $this->container->get('surface.resources');
    $typed = $this->container->get('config.typed');
    $provider = $this->container->get(ConfigSchemaTypes::class);
    $bound = [
      'block' => ['type' => 'block.block.*', 'path' => 'settings', 'by' => 'plugin'],
      'field storage' => ['type' => 'field.storage.*.*', 'path' => 'settings', 'by' => 'type'],
      'condition' => ['type' => 'block.block.*', 'path' => 'visibility.*', 'by' => 'id'],
    ];
    $values = [
      'block' => ['page_title_block', 'system_branding_block', 'system_menu_block:main'],
      'field storage' => ['decimal', 'entity_reference', 'string'],
      'condition' => ['request_path', 'response_status', 'user_role'],
    ];
    foreach ($bound as $pair => $arguments) {
      $entries = $provider->listFor($arguments)->entries();
      foreach ($values[$pair] as $value) {
        $this->assertArrayHasKey($value, $entries);
        $built = $resources->buildStatic($entries[$value]['structure'], new OperationContext('configure'));
        $type = $this->container->get(ConfigSchemaReader::class)->determine(sprintf(self::PAIRS[$pair][2], $value));
        $this->assertSame(self::EXPECTED_WIDER[$pair . ' ' . $value] ?? [], $this->wider($typed, (string) $type, $built), $pair . ' ' . $value . ': nothing wider than ' . $type . '.');
      }
    }
  }

  /**
   * Tests the provider refuses a deciding key the schema does not name.
   */
  public function testTheProviderRefusesAnotherDecidingKey(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('ConfigSchemaTypes lists the variants of field.storage.*.* at settings for "field_type", and the schema types it by "type"');
    $this->container->get(ConfigSchemaTypes::class)->listFor([
      'type' => 'field.storage.*.*',
      'path' => 'settings',
      'by' => 'field_type',
    ]);
  }

  /**
   * Tests the provider refuses its arguments by position.
   */
  public function testTheProviderRefusesPositionalArguments(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('ConfigSchemaTypes takes its arguments by name, arguments: [\'type\' => \'block.block.*\', \'path\' => \'settings\']');
    $this->container->get(ConfigSchemaTypes::class)->listFor(['block.block.*', 'settings', 'plugin']);
  }

  /**
   * Tests `:own` is a type's keys less its frame type's, matched by keys.
   *
   * The frame type is the type itself or a type it extends, at any
   * distance. `block.settings.system_branding_block` is `type:
   * block_settings`; `block.settings.*` is too and adds no key, so either
   * left out is one structure under one id. A type less itself, as a
   * plugin with no schema of its own is read, holds no key.
   * `views.argument.date_day` is `type: views.argument.date`, which is
   * `type: views_argument`, as `views.argument.*` is: less the root it
   * holds the intermediate type's keys and its own. A type left out whose
   * keys are those of no type in the ancestry is refused, since the id
   * would say what the structure does not hold. Typed config drops a
   * definition's `type:` once it has merged it for a value, and the frame
   * type is found as the schema writes it all the same.
   */
  public function testOwnIsTheTypeLessItsFrameType(): void {
    $this->enableModules(['views']);
    $this->container->get('config.typed')->clearCachedDefinitions();
    $reader = $this->container->get(ConfigSchemaReader::class);
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    $names = fn (string $name): array => $resources->buildStatic($name, new OperationContext('configure'))->getDefinitions()->names();

    $typed = $this->container->get('config.typed');
    $block = [
      'plugin' => 'system_branding_block',
      'settings' => ['use_site_logo' => TRUE],
    ];
    $typed->createFromNameAndData('block.block.branding', $block)->get('settings')->getElements();
    $this->assertArrayNotHasKey('type', $typed->getDefinitions()['block.settings.system_branding_block'], 'Merged for a value, the definition has lost its type.');

    $parent = $reader->structure('block.settings.system_branding_block', '', 'block_settings');
    $same = $reader->structure('block.settings.system_branding_block', '', 'block.settings.*');
    $this->assertSame('config_schema:block.settings.system_branding_block:own', $registry->getDefinition($parent)->id);
    $this->assertSame($registry->getDefinition($parent)->id, $registry->getDefinition($same)->id, 'The frame type, or a type with its keys: one id.');
    $this->assertSame($names($parent), $names($same));
    $this->assertSame(['use_site_logo', 'use_site_name', 'use_site_slogan'], $names($parent), 'The branding block\'s own keys.');

    foreach (['block.settings.system_branding_block', 'block.settings.*'] as $type) {
      $itself = $reader->structure($type, '', $type);
      $this->assertSame('config_schema:' . $type . ':own', $registry->getDefinition($itself)->id);
      $this->assertSame([], $names($itself), $type . ' less itself holds no key.');
    }

    $day = $reader->structure('views.argument.date_day', '', 'views.argument.*');
    $this->assertSame('config_schema:views.argument.date_day:own', $registry->getDefinition($day)->id);
    $this->assertEqualsCanonicalizing(['date', 'node_created', 'node_changed', 'day'], $names($day), 'Less the root: the intermediate type\'s keys and its own.');

    try {
      $reader->structure('block.settings.system_branding_block', '', 'condition.plugin');
      $this->fail('A type left out with the keys of no type in the ancestry is accepted.');
    }
    catch (\LogicException $e) {
      $this->assertStringStartsWith('ConfigSchemaReader reads block.settings.system_branding_block less the keys of condition.plugin, which are the keys of neither the type nor a type it extends (it extends block_settings): an :own structure leaves out the keys of its frame type, the type itself or a type it extends, matched by keys. The keys that differ from block_settings: ', $e->getMessage());
      $this->assertStringContainsString('negate', $e->getMessage());
    }
  }

  /**
   * Tests a whole placed block, read: each form of named type.
   */
  public function testPlacedBlockRead(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    $inputs = new RecordingBlueprint();
    $reading = $reader->read('block.block.*', $inputs);
    $keys = $inputs->keys;
    $this->assertSame('attachVariants', $keys['settings']['verb']);
    $this->assertSame('plugin', $keys['settings']['by']);
    $this->assertSame(ConfigSchemaTypes::class, $keys['settings']['from']);
    $this->assertSame(['type' => 'block.block.*', 'path' => 'settings'], $keys['settings']['arguments']);
    $this->assertSame('attachList', $keys['visibility']['verb']);
    $this->assertSame('config_schema:condition.plugin.[id]', $reading->structures()['visibility.*']);
    $this->assertSame('own key', $reading->expressions()['visibility.*']['form']);
    $this->assertArrayNotHasKey('_core', $keys);
    // The leaf types, each a string or an integer with its constraints.
    $this->assertSame('machine_name', $reading->leaves()['id']);
    $this->assertSame('weight', $reading->leaves()['weight']);
    $this->assertSame('integer', $keys['weight']['definition']->getDataType());
    $this->assertArrayHasKey('Range', $keys['weight']['definition']->getConstraints());
    $id = $keys['id']['definition']->getConstraints();
    $this->assertArrayHasKey('Regex', $id);
    $this->assertArrayHasKey('Length', $id);
    // What the JSON route left to the schema, carried.
    $this->assertArrayHasKey('ExtensionExists', $keys['theme']['definition']->getConstraints());
    $this->assertArrayHasKey('Sequentially', $keys['region']['definition']->getConstraints());
    $this->assertArrayHasKey('PluginExists', $keys['plugin']['definition']->getConstraints());
    // A fully validatable entity holds its keys to a value.
    $this->assertTrue($keys['theme']['definition']->isRequired());
    $this->assertFalse($keys['provider']['definition']->isRequired(), 'Nullable.');
    // And the whole builds, and emits.
    $resources = $this->container->get('surface.resources');
    $built = $resources->buildStatic($reader->structure('block.block.*'), new OperationContext('configure'));
    $full = $this->container->get('surface.contract_emitter')->emitFull($built, 'block.block')->document['schema'];
    $this->assertSame('config_schema:condition.plugin.[id]', SchemaResolver::defName($full['properties']['visibility']['items']['$ref']));
  }

  /**
   * Tests a type chosen by its own key: the fallback's keys, then the rest.
   *
   * `condition.plugin.[id]` is one structure: the keys of
   * `condition.plugin`, which every condition holds, then, at
   * `configuration`, the variants the id chooses, each the condition less
   * those keys, stored flat beside them.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   */
  public function testOwnKeyTypeHoldsItsVariantsAtConfiguration(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    $inputs = new RecordingBlueprint();
    $reader->read('condition.plugin.[id]', $inputs);
    $fallback = array_keys($reader->keysOf('condition.plugin'));
    $this->assertSame([...$fallback, ConfigSchemaReader::CHOSEN], array_keys($inputs->keys), 'The keys every condition holds, then the key the chosen one is asked under.');
    $chosen = $inputs->keys[ConfigSchemaReader::CHOSEN];
    $this->assertSame('attachVariants', $chosen['verb']);
    $this->assertSame(['id', ConfigSchemaTypes::class], [$chosen['by'], $chosen['from']], 'The variants the id chooses.');
    $this->assertSame(['type' => 'condition.plugin.[id]'], $chosen['arguments'], 'Bound to the expression.');
    $this->assertSame(Variants::FLAT, $chosen['definition']->getSetting(Variants::STORED_AS), 'Stored flat beside the id.');

    $name = $reader->structure('condition.plugin.[id]');
    $this->assertSame('config_schema:condition.plugin.[id]', $this->container->get('surface.resource_registry')->getDefinition($name)->id);
    $full = $this->full($name);
    $this->assertSame([...$fallback, ConfigSchemaReader::CHOSEN], array_keys($full['properties']));
    $listed = $this->container->get(ConfigSchemaTypes::class)->listFor(['type' => 'condition.plugin.[id]', 'by' => 'id'])->entries();
    $this->assertNotEmpty($listed);
    $variants = $full['properties'][ConfigSchemaReader::CHOSEN]['x-surface']['variants'];
    $this->assertSame(array_keys($listed), array_map('strval', array_keys($variants)));
    $branches = [];
    foreach ($full['allOf'] as $conditional) {
      $branches[$conditional['if']['properties']['id']['const']] = SchemaResolver::defName($conditional['then']['properties'][ConfigSchemaReader::CHOSEN]['$ref']);
    }
    $this->assertCount(count($listed), $branches, 'One conditional per variant, on the id.');
    foreach (array_keys($listed) as $id) {
      $type = $reader->determine('condition.plugin.' . $id);
      $def = ConfigSchemaReader::id((string) $type, '', 'condition.plugin');
      $this->assertSame($def, $branches[(string) $id] ?? NULL, $id . ' is one conditional, its def the type less the fallback\'s keys.');
      $this->assertSame($def, SchemaResolver::defName($variants[$id]));
    }

    // A condition's own key under the key, accepted; under another, refused.
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    $this->assertTrue($built->getDefinitions()->entry(ConfigSchemaReader::CHOSEN)->variants->storedFlat());
    $refusals = function (array $value) use ($built): array {
      $accepted = $this->pipeline()->accept($built, $value, []);
      $messages = [];
      foreach ($this->pipeline()->validate($built, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $path = ['id' => 'request_path', 'negate' => FALSE, ConfigSchemaReader::CHOSEN => ['pages' => '/node']];
    $this->assertSame([], $refusals($path));
    $role = ['id' => 'user_role'] + $path;
    try {
      $refusals($role);
      $this->fail('Another condition\'s keys are accepted.');
    }
    catch (VariantMismatchException $e) {
      $this->assertSame('The value at configuration is shaped for another variant than "user_role", which id chose: pages.', $e->getMessage());
    }
    $this->assertTrue($this->validates($full, $path));
    $this->assertFalse($this->validates($full, $role), 'A validator refuses it too: the user role\'s def is closed.');
  }

  /**
   * Tests a type chosen by its own key may hold variants a key of it chooses.
   *
   * The test module's formatted handler holds `settings`, typed by its own
   * `format` (`[%parent.format]`), as views.field.field holds its
   * formatter's settings: the format and the settings are the formatted
   * variant's keys at `configuration`, the settings that variant's
   * variants at a key, settled when read.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   */
  public function testOwnKeyTypeHoldsVariants(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    $name = $reader->structure('surface_reader_test.handler.[plugin_id]');
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    $map = $built->getDefinitions();
    $this->assertSame(['plugin_id', ConfigSchemaReader::CHOSEN], $map->names());
    $handlers = $map->entry(ConfigSchemaReader::CHOSEN)->variants;
    $this->assertSame('plugin_id', $handlers?->by);
    $this->assertSame(['formatted', 'plain'], $handlers->variantIds());
    $formatted = $handlers->variant('formatted')->child->getDefinitions();
    $this->assertSame(['format', 'settings'], $formatted->names());
    $this->assertSame('format', $formatted->entry('settings')->variants?->by);
    $this->assertSame(['bold', 'italic'], $formatted->entry('settings')->variants->variantIds());
    $this->assertSame(['size'], $handlers->variant('plain')->child->getDefinitions()->names());
    $refusals = function (array $value) use ($built): array {
      $accepted = $this->pipeline()->accept($built, $value, []);
      $messages = [];
      foreach ($this->pipeline()->validate($built, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $handler = static fn (string $plugin_id, array $chosen): array => [
      'plugin_id' => $plugin_id,
      ConfigSchemaReader::CHOSEN => $chosen,
    ];
    $bold = ['format' => 'bold', 'settings' => ['weight' => 700]];
    $this->assertSame([], $refusals($handler('formatted', $bold)), 'A handler, its settings nested in its variant.');
    $this->assertSame([], $refusals($handler('formatted', ['format' => 'italic', 'settings' => ['slant' => 12]])));
    $this->assertSame([], $refusals($handler('plain', ['size' => 3])));
    $mismatches = [
      [
        'plain',
        ['settings' => ['weight' => 700]],
        'The value at configuration is shaped for another variant than "plain", which plugin_id chose: settings.',
      ],
      [
        'formatted',
        ['format' => 'italic'] + $bold,
        'The value at configuration.settings is shaped for another variant than "italic", which format chose: weight.',
      ],
    ];
    foreach ($mismatches as [$plugin_id, $chosen, $message]) {
      try {
        $refusals($handler($plugin_id, $chosen));
        $this->fail('Another variant\'s keys are accepted.');
      }
      catch (VariantMismatchException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests two types declaring one key unlike each keep their own definition.
   *
   * Each variant is whole in its own frame, so nothing holds the two
   * definitions together. views.field.field's `separator` is a `label`,
   * with core's Regex, and views.field.user_roles' (from
   * views.field.prerender_list) a plain `string`; the test module's signal
   * declares its pattern a string in one type and a mapping in the other,
   * as views.area.text and views.area.text_custom declare their content.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   */
  public function testOwnKeyTypesDeclaringKeyUnlikeKeepTheirOwn(): void {
    $this->enableModules(['views']);
    $this->container->get('config.typed')->clearCachedDefinitions();
    $reader = $this->container->get(ConfigSchemaReader::class);
    $resources = $this->container->get('surface.resources');
    $fields = $resources->buildStatic($reader->structure('views.field.[plugin_id]'), new OperationContext('configure'))
      ->getDefinitions()->entry(ConfigSchemaReader::CHOSEN)->variants;
    $field = $fields->variant('field')->child->getDefinition('separator');
    $roles = $fields->variant('user_roles')->child->getDefinition('separator');
    $this->assertSame(['string', 'string'], [$field->getDataType(), $roles->getDataType()]);
    $this->assertArrayHasKey('Regex', $field->getConstraints(), 'The field\'s separator is a label, held to its Regex.');
    $this->assertArrayNotHasKey('Regex', $roles->getConstraints(), 'The roles\' separator is a plain string.');

    $name = $reader->structure('surface_reader_test.signal.[kind]');
    $built = $resources->buildStatic($name, new OperationContext('configure'));
    $map = $built->getDefinitions();
    $this->assertSame(['kind', ConfigSchemaReader::CHOSEN], $map->names());
    $signals = $map->entry(ConfigSchemaReader::CHOSEN)->variants;
    $this->assertSame('string', $signals->variant('word')->child->getDefinition('pattern')->getDataType());
    $this->assertSame('map', $signals->variant('timed')->child->getDefinition('pattern')->getDataType(), 'A mapping in the timed signal.');
    $refusals = function (array $value) use ($built): array {
      try {
        $accepted = $this->pipeline()->accept($built, $value, []);
      }
      catch (TypeMismatchException $e) {
        return [$e->getPath() => $e->getMessage()];
      }
      $messages = [];
      foreach ($this->pipeline()->validate($built, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame([], $refusals(['kind' => 'word', ConfigSchemaReader::CHOSEN => ['pattern' => 'dot dash']]), 'Each shape, under its kind.');
    $timed = ['pattern' => ['on' => 2, 'off' => 1]];
    $this->assertSame([], $refusals(['kind' => 'timed', ConfigSchemaReader::CHOSEN => $timed]));
    $mismatch = ['configuration.pattern' => 'The value at "configuration.pattern" must be of type map, string given.'];
    $this->assertSame($mismatch, $refusals(['kind' => 'timed', ConfigSchemaReader::CHOSEN => ['pattern' => 'dot dash']]), 'The string under the mapping\'s kind.');
  }

  /**
   * Tests an own-key structure's values are stored flat, as core stores them.
   *
   * The test module's condition host attaches `condition.plugin.[id]` at
   * `condition`, in a config object typed by core's own-key form: the
   * request path's pages are stored beside its id, read under
   * `configuration`, and written back beside the id.
   *
   * Decision: see docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key.
   */
  public function testOwnKeyStructureIsStoredFlat(): void {
    $this->installConfig(['surface_reader_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
    $config = ConditionHostStorage::CONFIG;
    $this->assertSame(['id' => 'request_path', 'negate' => FALSE, 'pages' => '/node/*'], $this->config($config)->get('condition'), 'As installed: the pages beside the id.');

    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(ConditionHostResource::class, 'configure');
    $built = $resources->build(ConditionHostResource::class, $context);
    $this->assertSame(['condition.' . ConfigSchemaReader::CHOSEN => StoredSchemaInterface::SPREAD], FlatVariantsLayout::storedSchemaOf($built));
    $storage = $resources->storage(ConditionHostResource::class, $context, $built);
    $this->assertSame(['id' => 'request_path', 'negate' => FALSE, ConfigSchemaReader::CHOSEN => ['pages' => '/node/*']], $storage->load($built)['condition'], 'Read: the pages under configuration.');

    $condition = ['id' => 'request_path', 'negate' => TRUE, ConfigSchemaReader::CHOSEN => ['pages' => '/user/*']];
    $result = $this->pipeline()->submit($built, ['condition' => $condition], $storage);
    $this->assertTrue($result->isValid(), (string) json_encode(array_map(static fn ($violation): string => $violation->fullPath() . ': ' . strip_tags((string) $violation->message), iterator_to_array($result->violations))));
    $this->assertSame(['id' => 'request_path', 'negate' => TRUE, 'pages' => '/user/*'], $this->config($config)->get('condition'), 'Written beside the id, nothing at configuration.');
    $this->assertSame($condition, $resources->storage(ConditionHostResource::class, $context, $built)->load($built)['condition']);
    $typed = $this->container->get('config.typed');
    $this->assertCount(0, $typed->createFromNameAndData($config, $this->config($config)->getRawData())->validate(), 'Core reads it through the request path\'s own type.');
  }

  /**
   * Tests an own-key type whose fallback declares `configuration` is refused.
   *
   * Stored flat, the chosen type's keys asked for under `configuration`
   * and the fallback's own key of that name would be one key.
   */
  public function testOwnKeyFallbackDeclaringConfigurationIsRefused(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    try {
      $this->container->get('surface.resources')->buildStatic($reader->structure('surface_reader_test.setting.[kind]'), new OperationContext('configure'));
      $this->fail('The setting was read.');
    }
    catch (\LogicException $e) {
      $this->assertSame('ConfigSchemaReader reads surface_reader_test.setting.[kind] as the keys of surface_reader_test.setting.* and, at "configuration", the type its "kind" key chooses, stored flat beside them, and surface_reader_test.setting.* declares "configuration" itself: stored flat, the two would be one key.', $e->getMessage());
    }
  }

  /**
   * Tests a sequence's Count is carried onto the list, of values or mappings.
   *
   * The test module's schema declares one of each, since core's declares
   * none: the values' list holds the constraint, the mappings'
   * collection its fewest and most items (attachList(min:, max:)).
   */
  public function testSequenceCountIsCarried(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    $name = $reader->structure('surface_reader_test.counted');
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    $map = $built->getDefinitions();
    $this->assertSame(['min' => 1, 'max' => 3], $map->get('tags')->getConstraints()['Count']);
    $this->assertNotNull($map->entry('stops')->list, 'A collection.');
    $this->assertSame(['min' => 1, 'max' => 3], $map->get('stops')->getConstraints()['Count']);
    $refusals = function (array $value) use ($built): array {
      $accepted = $this->pipeline()->accept($built, $value, []);
      $messages = [];
      foreach ($this->pipeline()->validate($built, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $stop = ['name' => 'Ridge'];
    $this->assertSame([], $refusals(['tags' => ['a'], 'stops' => [$stop]]));
    $this->assertSame([
      'tags' => 'This collection should contain 1 element or more.',
      'stops' => 'This collection should contain 1 element or more.',
    ], $refusals(['tags' => [], 'stops' => []]));
    $this->assertSame([
      'tags' => 'This collection should contain 3 elements or less.',
      'stops' => 'This collection should contain 3 elements or less.',
    ], $refusals(['tags' => ['a', 'b', 'c', 'd'], 'stops' => array_fill(0, 4, $stop)]));
    $full = $this->full($name);
    foreach (['tags', 'stops'] as $key) {
      $property = $full['properties'][$key];
      $this->assertSame([1, 3], [$property['minItems'] ?? NULL, $property['maxItems'] ?? NULL], $key . ' says its count.');
    }
    $this->assertFalse($this->validates($full, ['tags' => [], 'stops' => [$stop]]));
    $this->assertFalse($this->validates($full, ['tags' => ['a'], 'stops' => array_fill(0, 4, $stop)]));
  }

  /**
   * Tests a key no shape is read for is declared open, never left out.
   *
   * The test module's schema declares one the schema checks nothing of
   * (`ignore`) and one whose type a key outside its frame chooses
   * (`[%parent.%parent.kind]`): the structure is closed, so each is a
   * value of any shape, holding what is stored there, and noted.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   */
  public function testKeyNoShapeIsReadForIsOpen(): void {
    $reader = $this->container->get(ConfigSchemaReader::class);
    $reading = $reader->read('surface_reader_test.open', new RecordingBlueprint());
    $left = $reading->left();
    $this->assertStringContainsString('the schema checks nothing here, so the key is declared open', $left['loose'][0] ?? '');
    $this->assertStringContainsString('a key outside this frame', $left['nested.options'][0] ?? '');
    $this->assertStringContainsString('so the key is declared open', $left['nested.options'][0] ?? '');

    $name = $reader->structure('surface_reader_test.open');
    $built = $this->container->get('surface.resources')->buildStatic($name, new OperationContext('configure'));
    $this->assertSame(['langcode', 'kind', 'loose', 'nested'], $built->getDefinitions()->names());
    $this->assertSame('any', $built->getDefinition('loose')->getDataType());
    $refusals = function (array $value) use ($built): array {
      $accepted = $this->pipeline()->accept($built, $value, []);
      $messages = [];
      foreach ($this->pipeline()->validate($built, $accepted, []) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $stored = [
      'kind' => 'wide',
      'loose' => ['anything' => [1, 'two', ['three' => TRUE]]],
      'nested' => ['options' => ['width' => 4, 'more' => 'kept']],
    ];
    $this->assertSame([], $refusals($stored), 'Whatever is stored at either.');
    $this->assertSame([], $refusals(['loose' => 'a string', 'nested' => ['options' => 7]] + $stored));
    try {
      $refusals(['stray' => 1] + $stored);
      $this->fail('A key the schema does not declare is accepted.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('Unknown key(s) stray.', $e->getMessage(), 'A key the schema does not declare is refused still.');
    }

    $full = $this->full($name);
    $this->assertSame(['title' => 'Loose'], $full['properties']['loose'], 'Open: a title and no type.');
    $this->assertSame(['title' => 'Options'], $full['properties']['nested']['properties']['options']);
    $this->assertTrue($this->validates($full, $stored));
    $this->assertFalse($this->validates($full, ['stray' => 1] + $stored));
    $schema = $this->container->get('surface.contract_emitter')->emitFull($built, $name)->document['schema'];
    $back = $this->readBack($schema, $name, 'surface', 'configure', NULL, NULL, []);
    $this->assertSame('same', $back['verdict'], (string) ($back['error'] ?? json_encode($back['again'])));
  }

  /**
   * Tests views' contextual filter declares its options keys, open.
   *
   * `summary_options` and `validate_options` are typed by a key of a
   * mapping inside the handler (`[%parent.summary.format]`,
   * `[%parent.validate.type]`), outside its frame: declared open, each
   * holds what a stored view holds there.
   */
  public function testViewsArgumentDeclaresItsOptions(): void {
    $this->enableModules(['views']);
    $this->container->get('config.typed')->clearCachedDefinitions();
    $reader = $this->container->get(ConfigSchemaReader::class);
    $inputs = new RecordingBlueprint();
    $reader->read('views.argument.[plugin_id]', $inputs);
    foreach (['summary_options', 'validate_options'] as $key) {
      $this->assertArrayHasKey($key, $inputs->keys, $key . ' is declared.');
      $this->assertSame('any', $inputs->keys[$key]['definition']->getDataType());
    }
  }

  /**
   * Emits a structure's full schema, as a client decodes it.
   *
   * @param string $id
   *   The structure.
   *
   * @return array
   *   The schema.
   */
  protected function full(string $id): array {
    $built = $this->container->get('surface.resources')->buildStatic($id, new OperationContext('configure'));
    return json_decode((string) json_encode($this->container->get('surface.contract_emitter')->emitFull($built, $id)->document['schema']), TRUE);
  }

  /**
   * Follows a def's pointer.
   *
   * @param array $schema
   *   The document.
   * @param string $pointer
   *   The def's reference, `#/$defs/<name>`.
   *
   * @return array
   *   The def.
   */
  protected function def(array $schema, string $pointer): array {
    return $schema['$defs'][substr($pointer, strlen('#/$defs/'))];
  }

  /**
   * Lists where two objects' properties differ, keyword by keyword.
   *
   * @param array $json
   *   The JSON route's object.
   * @param array $direct
   *   The direct route's.
   * @param string $at
   *   The path, for the list.
   * @param array $differences
   *   The differences, added to: path, side (`json` for what the JSON
   *   route alone says, `direct` for the direct route alone), what.
   */
  protected function compare(array $json, array $direct, string $at, array &$differences): void {
    $ignored = ['title', 'description'];
    foreach ((array) ($json['properties'] ?? []) as $key => $a) {
      $path = $at === '' ? (string) $key : $at . '.' . $key;
      $b = $direct['properties'][$key] ?? NULL;
      if ($b === NULL) {
        $differences[] = self::difference($path, 'json', 'the key');
        continue;
      }
      if (($a['type'] ?? NULL) === 'object' || in_array('object', (array) ($a['type'] ?? []), TRUE)) {
        $this->compare($a, $b, $path, $differences);
      }
      foreach ($a as $keyword => $value) {
        if (in_array($keyword, $ignored, TRUE) || in_array($keyword, ['properties', 'additionalProperties'], TRUE)) {
          continue;
        }
        if ($keyword === 'default') {
          if (!array_key_exists('default', $b)) {
            $differences[] = self::difference($path, 'json-default', 'default ' . json_encode($value));
          }
          continue;
        }
        if ($keyword === 'type' && array_diff((array) $value, ['null']) === array_diff((array) ($b['type'] ?? []), ['null']) && $value !== ($b['type'] ?? NULL)) {
          $differences[] = self::difference($path, in_array('null', (array) $value, TRUE) ? 'json-null' : 'direct-null', 'type ' . json_encode($value) . ' beside ' . json_encode($b['type'] ?? NULL));
          continue;
        }
        if ($keyword === 'items' && is_array($value) && is_array($b['items'] ?? NULL)) {
          $a_items = array_diff_key($value, array_flip($ignored));
          $b_items = array_diff_key($b['items'], array_flip($ignored));
          if ($a_items !== $b_items) {
            $differences[] = self::difference($path . '.*', array_diff_key($a_items, $b_items) === [] ? 'direct' : 'json', 'items ' . json_encode($a_items) . ' beside ' . json_encode($b_items));
          }
          continue;
        }
        if (!array_key_exists($keyword, $b) || $b[$keyword] !== $value) {
          $differences[] = self::difference($path, 'json', $keyword . ' ' . json_encode($value) . ' beside ' . json_encode($b[$keyword] ?? NULL));
        }
      }
      foreach ($b as $keyword => $value) {
        if (!in_array($keyword, $ignored, TRUE) && !array_key_exists($keyword, $a) && $keyword !== 'properties') {
          $differences[] = self::difference($path, 'direct', $keyword . ' ' . json_encode($value));
        }
      }
      foreach ($ignored as $keyword) {
        if (($a[$keyword] ?? NULL) !== ($b[$keyword] ?? NULL)) {
          $differences[] = self::difference($path, 'wording', $keyword . ' ' . json_encode($a[$keyword] ?? NULL) . ' beside ' . json_encode($b[$keyword] ?? NULL));
        }
      }
    }
    foreach ((array) ($direct['properties'] ?? []) as $key => $b) {
      if (!isset($json['properties'][$key])) {
        $differences[] = self::difference($at === '' ? (string) $key : $at . '.' . $key, 'direct', 'the key');
      }
    }
    foreach (['required'] as $keyword) {
      $a = (array) ($json[$keyword] ?? []);
      $b = (array) ($direct[$keyword] ?? []);
      if (array_diff($a, $b) !== []) {
        $differences[] = self::difference($at, 'json', 'required ' . json_encode(array_values(array_diff($a, $b))));
      }
      if (array_diff($b, $a) !== []) {
        $differences[] = self::difference($at, 'direct', 'required ' . json_encode(array_values(array_diff($b, $a))));
      }
    }
  }

  /**
   * Makes one row of the differences.
   *
   * @param string $path
   *   The key's dotted path in the variant.
   * @param string $side
   *   Which route says it alone, or how they differ.
   * @param string $what
   *   What is said.
   *
   * @return array{path: string, side: string, what: string}
   *   The row.
   */
  protected static function difference(string $path, string $side, string $what): array {
    return ['path' => $path, 'side' => $side, 'what' => $what];
  }

  /**
   * Holds a built structure to a config schema type.
   *
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed
   *   The typed config manager.
   * @param string $type
   *   The type.
   * @param \Drupal\surface\SurfaceInterface $built
   *   The structure, built.
   *
   * @return array<string, string>
   *   Where it is wider, by key.
   */
  protected function wider(TypedConfigManagerInterface $typed, string $type, SurfaceInterface $built): array {
    $check = new class($typed, $type) {

      use HeldToConfigSchemaTrait;

      public function __construct(
        protected TypedConfigManagerInterface $typedConfig,
        protected string $type,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function configSchemaType(): string {
        return $this->type;
      }

    };
    return $check->widerThanSchema($built);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraint;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Surface;
use Drupal\surface_examples\Storage\VenueStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the built node of a definition, and its projection to a document.
 *
 * The rules: a built node holds each check's options as the definition
 * holds them and text as the definition holds it, a TranslatableMarkup
 * kept; published() of it says what the served document says of the key
 * (the committed baselines, for every plain key the examples ship); the
 * node of a definition a sealed data surface holds is built once, and a
 * refined key's, a new definition, afresh.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-node
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefinitionSchemaTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * What the emitter adds to a key for the request, which no node holds.
   */
  protected const DECORATION = ['oneOf', 'const', 'readOnly', 'x-portable-ui', 'x-portable-links', 'x-surface'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Gets the reader.
   *
   * @return \Drupal\surface\Contract\DefinitionSchema
   *   The service.
   */
  protected function definitions(): DefinitionSchema {
    return $this->container->get('surface.definition_schema');
  }

  /**
   * Gives SurfaceUnique's options as a venue's machine name holds them.
   *
   * @return array
   *   The options.
   */
  protected static function unique(): array {
    return [
      'storage' => VenueStorage::class,
      'key' => 'id',
      'thing' => 'venue',
      'reason' => UniqueConstraint::reason('venue'),
    ];
  }

  /**
   * Writes a node as the document's JSON.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return array
   *   The node decoded.
   */
  protected static function json(Schema $node): array {
    return json_decode((string) json_encode(Schemas::arrayed($node)), TRUE);
  }

  /**
   * Tests a leaf: its checks' own options, its text kept lazy.
   */
  public function testLeaf(): void {
    $leaf = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setDescription('Two <em>letters</em> &amp; no digits.')
      ->addConstraint('Length', ['max' => 2])
      ->addConstraint('Regex', ['pattern' => '/^[a-z]+$/', 'message' => 'Lowercase letters only.'])
      ->addConstraint('SurfaceUnique', static::unique());
    $built = $this->definitions()->of($leaf, FALSE);
    $this->assertInstanceOf(TranslatableMarkup::class, $built->get('title'), 'A lazy title stays lazy.');
    $held = $leaf->getConstraints();
    $this->assertSame([
      PortableKeywords::PROVIDER . 'Regex' => $held['Regex'],
      PortableKeywords::PROVIDER . 'SurfaceUnique' => $held['SurfaceUnique'],
    ], $built->get(PortableKeywords::CHECK), 'Each check holds the options the definition holds.');
    $messages = $built->get(PortableKeywords::MESSAGES);
    $this->assertInstanceOf(TranslatableMarkup::class, $messages['check.drupal:Regex'] ?? NULL, 'A lazy message stays lazy.');
    $model = DefinitionSchema::model($built);
    $this->assertSame(DefinitionSchema::MEMBERS, array_keys($model));
    $this->assertFalse(Secrets::marked($built), 'A node says it is secret by writeOnly alone.');
    $this->assertStringNotContainsString('DataDefinition', serialize($model), 'A node holds no reference to its definition.');
    $this->assertSame(array_keys($leaf->getConstraints()), array_keys($model['constraints']), 'Every declared constraint, the ones keywords state among them.');
    $this->assertSame(['max' => 2], $model['constraints']['Length']);
    $this->assertSame($held['SurfaceUnique'], DefinitionSchema::options($built, 'SurfaceUnique'));
    $this->assertSame(['max' => 2], DefinitionSchema::options($built, 'Length'), 'A keyword-stated constraint runs with its own options.');

    $published = static::json(DefinitionSchema::published($built));
    $this->assertSame([
      'title' => 'Code',
      'description' => 'Two letters & no digits.',
      'type' => ['string', 'null'],
      'maxLength' => 2,
      // A Regex the table does not state as `pattern` is the server's.
      'portable-check' => ['drupal:Regex' => [], 'drupal:SurfaceUnique' => []],
      'x-portable-messages' => [
        'check.drupal:Regex' => 'Lowercase letters only.',
        'check.drupal:SurfaceUnique' => (string) UniqueConstraint::reason('venue'),
      ],
    ], $published);
  }

  /**
   * Tests a list and a map, a nested map among its properties.
   */
  public function testListAndMaps(): void {
    $tags = ListDataDefinition::create('string')
      ->setLabel('Tags')
      ->addConstraint('Count', ['max' => 3])
      ->addConstraint('LanguageExists', []);
    $built = $this->definitions()->of($tags, FALSE);
    $this->assertSame([PortableKeywords::PROVIDER . 'LanguageExists' => []], $built->get(PortableKeywords::CHECK));
    $this->assertSame(['type' => ['string', 'null']], static::json(DefinitionSchema::published($built->get('items'))), 'The item is a node of its own.');
    $this->assertSame(DefinitionSchema::MEMBERS, array_keys(DefinitionSchema::model($built->get('items'))));
    $this->assertSame([
      'title' => 'Tags',
      'type' => ['array', 'null'],
      'items' => ['type' => ['string', 'null']],
      'maxItems' => 3,
      'portable-check' => ['drupal:LanguageExists' => []],
      'x-portable-messages' => ['check.drupal:LanguageExists' => 'Language exists'],
    ], static::json(DefinitionSchema::published($built)));

    $inner = MapDataDefinition::create()->setLabel('Where')
      ->setPropertyDefinition('city', DataDefinition::create('string')->setLabel('City')->setRequired(TRUE));
    $schema = ['type' => 'object', 'required' => ['name']];
    $map = MapDataDefinition::create()
      ->setLabel('Person')
      ->setPropertyDefinition('name', DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE))
      ->setPropertyDefinition('gone', DoesNotApply::marked(DataDefinition::create('string')))
      ->setPropertyDefinition('where', $inner)
      ->addConstraint('JsonSchema', ['schema' => $schema]);
    $built = $this->definitions()->of($map, FALSE);
    $this->assertSame([PortableKeywords::PROVIDER . 'JsonSchema' => ['schema' => $schema]], $built->get(PortableKeywords::CHECK));
    $this->assertSame(['name', 'where'], array_keys($built->get('properties')), 'A key ruled out is no property.');
    $this->assertSame(static::json(DefinitionSchema::published($this->definitions()->of($inner, FALSE))), static::json(DefinitionSchema::published($built->get('properties')['where'])), 'The nested map is a node of its own.');
    $published = static::json(DefinitionSchema::published($built));
    $this->assertSame([
      'title' => 'Person',
      'type' => ['object', 'null'],
      'properties' => [
        'name' => ['title' => 'Name', 'type' => 'string'],
        'where' => [
          'title' => 'Where',
          'type' => ['object', 'null'],
          'properties' => ['city' => ['title' => 'City', 'type' => 'string']],
          'additionalProperties' => FALSE,
          'required' => ['city'],
        ],
      ],
      'additionalProperties' => FALSE,
      'required' => ['name'],
      'portable-check' => ['drupal:JsonSchema' => ['schema' => $schema]],
      'x-portable-messages' => ['check.drupal:JsonSchema' => 'Checked on the server against the schema it was read from: type, required.'],
    ], $published);
    $this->assertStringNotContainsString(DefinitionSchema::MODEL, (string) json_encode(Schemas::arrayed(DefinitionSchema::published($built))));
  }

  /**
   * Tests a format's twin, a composite and a reference.
   */
  public function testTwinCompositeAndReference(): void {
    $email = DataDefinition::create('email')->setLabel('Email');
    $built = $this->definitions()->of($email, FALSE);
    $this->assertSame([PortableKeywords::PROVIDER . 'Email' => $email->getConstraints()['Email']], $built->get(PortableKeywords::CHECK));
    $published = static::json(DefinitionSchema::published($built));
    $this->assertSame('email', $published['format']);
    $this->assertSame(['drupal:Email' => []], $published['portable-check']);
    $this->assertArrayNotHasKey('x-portable-messages', $published, 'A twin says no message of its own.');

    $parts = ['constraints' => [['Length' => ['min' => 2]], ['SurfaceUnique' => static::unique()]]];
    $composite = DataDefinition::create('string')->setLabel('Code')->addConstraint('Sequentially', $parts);
    $built = $this->definitions()->of($composite, FALSE);
    $this->assertSame($parts, $built->get(PortableKeywords::CHECK)[PortableKeywords::PROVIDER . 'Sequentially'], 'A composite holds its parts.');
    $this->assertSame([], static::json(DefinitionSchema::published($built))['portable-check']['drupal:Sequentially']);

    $target = [
      'entityTypeId' => 'node',
      'bundles' => ['article'],
      'handler' => 'default:node',
      'handlerSettings' => ['auto_create' => FALSE],
    ];
    $reference = DataDefinition::create('string')->setLabel('Article')->addConstraint('SurfaceValidReference', $target);
    $built = $this->definitions()->of($reference, FALSE);
    $this->assertSame($target, DefinitionSchema::options($built, 'SurfaceValidReference'), 'The handler and its settings are on the node.');
    $this->assertSame(['entityTypeId' => 'node', 'bundles' => ['article']], static::json(DefinitionSchema::published($built))['portable-check']['drupal:SurfaceValidReference']);
  }

  /**
   * Tests a sealed definition's node is built once, a refined key's afresh.
   */
  public function testMemo(): void {
    $definitions = $this->definitions();
    $loose = DataDefinition::create('string')->setLabel('Loose');
    $this->assertNotSame($definitions->of($loose, FALSE), $definitions->of($loose, FALSE), 'A definition no data surface holds is read afresh.');
    $label = NULL;
    foreach ($this->examples() as [, , $surface]) {
      foreach ($surface->getDefinitions()->entries() as $entry) {
        if (!$entry->isNested() && $entry->list === NULL && $entry->definition->getDataType() === 'string') {
          $label = $entry->definition;
          break;
        }
      }
      if ($label === NULL) {
        continue;
      }
      $definitions->seal($surface);
      $node = $definitions->of($label, FALSE);
      $this->assertSame($node, $definitions->of($label, FALSE), 'A sealed definition is read once.');
      $this->assertNotSame($node, $definitions->of($label, TRUE), 'By $listed.');
      $refined = Surface::deepClone($label);
      $again = $definitions->of($refined, FALSE);
      $this->assertNotSame($node, $again, 'A refined key is a definition of its own.');
      $this->assertSame(static::json(DefinitionSchema::published($node)), static::json(DefinitionSchema::published($again)));
      break;
    }
    $this->assertNotNull($label, 'An example holds a string key.');
    foreach ($this->examples() as [, , $surface]) {
      foreach ($surface->getDefinitions()->entries() as $entry) {
        $map = $entry->definition;
        if ($entry->isNested() || $entry->list !== NULL || !$map instanceof MapDataDefinition || $map->getPropertyDefinitions() === [] || $definitions->listed($map)) {
          continue;
        }
        $definitions->seal($surface);
        $built = $definitions->of($map, FALSE);
        $properties = array_filter($map->getPropertyDefinitions(), static fn (DataDefinitionInterface $property): bool => !DoesNotApply::marks($property));
        $first = (string) array_key_first($properties);
        $this->assertSame($built->get('properties')[$first], $definitions->of($properties[$first], $definitions->listed($properties[$first])), 'A sealed map\'s properties are read once with it.');
        return;
      }
    }
    $this->fail('An example holds a map.');
  }

  /**
   * Tests a held definition written after its node is built fails.
   *
   * The memo's precondition (DefinitionSchema): a definition a sealed data
   * surface holds is never written after its node is built. Where
   * assertions run, a read of the kept node after a write fails the
   * assertion, naming the definition and the rule: a label, a constraint
   * or `required` set on it, a property set below a held map, and a key of
   * an example written between two emissions.
   */
  public function testWrittenHeldDefinitionFailsTheAssertion(): void {
    if (ini_get('zend.assertions') !== '1') {
      $this->markTestSkipped('Assertions do not run.');
    }
    $definitions = $this->definitions();
    $writes = [
      'setLabel' => static fn (DataDefinition $definition): DataDefinition => $definition->setLabel('Street address'),
      'addConstraint' => static fn (DataDefinition $definition): DataDefinition => $definition->addConstraint('Length', ['max' => 4]),
      'setRequired' => static fn (DataDefinition $definition): DataDefinition => $definition->setRequired(TRUE),
    ];
    foreach ($writes as $write => $change) {
      $held = DataDefinition::create('string')->setLabel('Address');
      $definitions->hold($held);
      $node = $definitions->of($held, FALSE);
      $this->assertSame($node, $definitions->of($held, FALSE), "$write: unchanged, the kept node is read.");
      $change($held);
      $this->assertFailsTheAssertion(static fn () => $definitions->of($held, FALSE), '(string)', $write);
    }

    $map = MapDataDefinition::create()->setLabel('Where')
      ->setPropertyDefinition('city', DataDefinition::create('string')->setLabel('City'));
    $definitions->hold($map);
    $definitions->of($map, FALSE);
    $city = $map->getPropertyDefinition('city');
    $this->assertInstanceOf(DataDefinition::class, $city);
    $city->setRequired(TRUE);
    $this->assertFailsTheAssertion(static fn () => $definitions->of($map, FALSE), '"Where" (map)', 'a property below a held map');

    $emitter = $this->container->get('surface.contract_emitter');
    foreach ($this->examples() as [$id, $operation, $surface]) {
      foreach ($surface->getDefinitions()->entries() as $name => $entry) {
        if ($entry->isNested() || $entry->list !== NULL || !$entry->definition instanceof DataDefinition || $entry->definition->getDataType() !== 'string') {
          continue;
        }
        $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation);
        $entry->definition->setLabel('Written after');
        $this->assertFailsTheAssertion(static fn () => $emitter->emit($surface, $surface->getDefaultValues(), $id, $operation), '"Written after" (string)', "$id:$operation/$name between two emissions");
        return;
      }
    }
    $this->fail('An example holds a string key.');
  }

  /**
   * Asserts a read fails the memo's assertion.
   *
   * @param \Closure $read
   *   The read.
   * @param string $named
   *   What the message names of the definition.
   * @param string $what
   *   What was written, for the message.
   */
  protected function assertFailsTheAssertion(\Closure $read, string $named, string $what): void {
    try {
      $read();
    }
    catch (\AssertionError $error) {
      $this->assertStringContainsString($named, $error->getMessage(), $what);
      $this->assertStringContainsString('is never written after its node is built', $error->getMessage(), $what);
      return;
    }
    $this->fail("$what: the kept node was read after its definition was written.");
  }

  /**
   * Tests published() says what each shipped key's baseline states inline.
   *
   * Plain keys and the maps inside them, a collection's own keywords
   * (head(); its items are the child's def, and its default the
   * emitter's), and a key with an attached child where the baseline
   * states it inline (no pinned baseline does today). Keys the baseline
   * shares as `$defs` (a `$ref`, an `allOf` around one) or decorates per
   * request (variants, a key refined or
   * grouped) stay with the side-by-side comparison of every document.
   */
  public function testPublishedIsTheBaseline(): void {
    $baselines = $this->container->get('surface.contract_baselines');
    $committed = $baselines->read($baselines->directory('surface'));
    $definitions = $this->definitions();
    $compared = 0;
    $collections = 0;
    foreach ($this->examples() as [$id, $operation, $surface, $index]) {
      $document = $committed[$id]['documents'][$index] ?? NULL;
      $this->assertIsArray($document, $id);
      $this->assertSame($operation, $document['operation'] ?? NULL, $id);
      $properties = $document['schema']['properties'] ?? [];
      foreach ($surface->getDefinitions()->entries() as $name => $entry) {
        $served = $properties[$name] ?? NULL;
        if (!is_array($served)) {
          continue;
        }
        if ($entry->list !== NULL) {
          // Its items are the child's def; its default is the emitter's.
          $theirs = ['items', 'default'];
          $head = $definitions->head($entry->definition);
          $counted = $this->compare($definitions, $entry->definition, $served, "$id:$operation/$name", $head, $theirs);
          $collections += $counted;
          $compared += $counted;
          continue;
        }
        if ($entry->isNested() && !isset($served['properties'])) {
          continue;
        }
        $compared += $this->compare($definitions, $entry->definition, $served, "$id:$operation/$name");
      }
    }
    $this->assertGreaterThan(100, $compared, 'The examples\' keys are compared.');
    $this->assertGreaterThan(2, $collections, 'The collections\' own keywords are compared.');
  }

  /**
   * Compares a definition's projected node with what a baseline serves.
   *
   * @param \Drupal\surface\Contract\DefinitionSchema $definitions
   *   The reader.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param array $served
   *   The key's node in the baseline.
   * @param string $at
   *   Where, for the message.
   * @param \PortableSchema\Builder\Schema|null $node
   *   The built node to compare; NULL for the definition's own, as the
   *   emitter reads it.
   * @param string[] $theirs
   *   Keywords the emitter writes for this key beside DECORATION.
   *
   * @return int
   *   How many nodes were compared: 0 for one the request decorates past
   *   what a node holds (a shape's reference, variants, a key refined or
   *   grouped).
   */
  protected function compare(DefinitionSchema $definitions, DataDefinitionInterface $definition, array $served, string $at, ?Schema $node = NULL, array $theirs = []): int {
    $extension = $served['x-surface'] ?? [];
    if (isset($served['$ref']) || isset($served['allOf']) || isset($served['anyOf']) || !empty($extension['refined']) || !empty($extension['group'])) {
      return 0;
    }
    $published = static::json(DefinitionSchema::published($node ?? $definitions->of($definition, $definitions->listed($definition))));
    $static = array_diff_key($served, array_flip([...self::DECORATION, ...$theirs]));
    $this->assertSame(array_keys($static), array_keys($published), $at . ': the same keywords, in order.');
    $compared = 1;
    foreach ($published as $keyword => $said) {
      if ($keyword === 'items') {
        // An item is said as never null where the list is drawn.
        continue;
      }
      if ($keyword === 'properties' && $definition instanceof MapDataDefinition) {
        $this->assertSame(array_keys($static['properties']), array_keys($said), $at);
        foreach ($definition->getPropertyDefinitions() as $name => $property) {
          if (is_array($static['properties'][$name] ?? NULL)) {
            $compared += $this->compare($definitions, $property, $static['properties'][$name], "$at/$name");
          }
        }
        continue;
      }
      $this->assertSame($static[$keyword], $said, "$at: $keyword");
    }
    return $compared;
  }

}

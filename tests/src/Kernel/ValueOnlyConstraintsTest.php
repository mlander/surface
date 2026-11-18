<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Pipeline\ViolationMessage;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraint;
use Drupal\surface\Validation\ConstraintRunner;
use Drupal\surface_examples\Storage\VenueStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Constraints\Unique;

/**
 * Tests Surface's own constraint plugins validate a plain value.
 *
 * The rule: none of the eight plugins Surface ships (JsonSchema,
 * LabeledChoice, LanguageExists, SurfaceLinkUri, SurfaceReferenceableType,
 * SurfaceUnique, UniqueItems, SurfaceValidReference) needs a typed value, so
 * ConstraintRunner::check() runs each on the plain value, through its
 * plain validator (ConstraintRunner::VALUE_ONLY). Each runs here on plain
 * PHP values, valid and invalid, with the option sets the shipped examples
 * give it, on both of the runner's paths, and the plain path gives the
 * violations the typed path (ConstraintRunner::typed()) gives for the same
 * value: the same message template, parameters, property path and code,
 * the same message, its class and its words, and the same sentence a door
 * says (ViolationMessage::of()).
 *
 * @see docs/decisions.md#one-adapter-runs-a-constraint-the-typed-data-footprint-is-pinned
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ValueOnlyConstraintsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    // SponsorshipResource's reference to an article: node 1 is one, node 2
    // is a page.
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Node::create(['type' => 'article', 'title' => 'Article'])->save();
    Node::create(['type' => 'page', 'title' => 'Page'])->save();
  }

  /**
   * Lists every plugin with an option set the examples use and its values.
   *
   * @return list<array{0: string, 1: mixed, 2: list<mixed>, 3: list<mixed>, 4: \Drupal\Core\TypedData\DataDefinitionInterface|null}>
   *   The plugin id, its options, values it passes, values it refuses, and
   *   the definition the runner makes the typed value from (NULL for
   *   `any`).
   */
  protected static function cases(): array {
    $string = DataDefinition::create('string');
    $person = [
      'required' => ['name'],
      'properties' => ['name' => ['type' => 'string']],
    ];
    $days = ['fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
    $periods = [0 => 'No caching', 60 => '1 min', 3600 => '1 hour'];
    $formats = [
      'choices' => ['plain', 'basic'],
      'labels' => ['plain' => 'Plain', 'basic' => 'Basic'],
    ];
    $settings = [
      'interface' => ConfigEntityInterface::class,
      'targets' => ['event_venue' => [], 'node' => [], 'user_role' => []],
    ];
    $venue = [
      'storage' => VenueStorage::class,
      'key' => 'id',
      'thing' => 'venue',
      'reason' => UniqueConstraint::reason('venue'),
    ];
    return [
      // SchemaReader keeps what no keyword states as a JsonSchema
      // constraint (AddSchemaTest's `regex`), on a leaf and on a map.
      ['JsonSchema', ['schema' => ['pattern' => '^a']], ['abc'], ['xyz'], $string],
      ['JsonSchema', ['schema' => $person], [['name' => 'x']], [['name' => 1], ['other' => 'x']], NULL],
      // DoorsResource: a map of choices to labels.
      ['LabeledChoice', ['choices' => $days], ['fri', 'sun'], ['mon', 'Friday'], $string],
      // FieldItemDerivation: choices and labels apart.
      ['LabeledChoice', $formats, ['basic'], ['full'], $string],
      // PerformanceResource and NodeTypeResource: labels alone, integer
      // values.
      ['LabeledChoice', ['labels' => $periods], [0, 3600], [30, '60'], DataDefinition::create('integer')],
      // ExampleFormatterAlter: no choice left.
      ['LabeledChoice', ['choices' => []], [], ['ribbon', ''], $string],
      // AddressFieldSettingsResource.
      ['LanguageExists', [], ['en'], ['xx', 'und'], $string],
      ['SurfaceLinkUri', [], ['https://example.com', 'route:<nolink>', 'internal:/'], ['/node/1', 'node', 42], $string],
      // SettingStructure, of surface_examples_references.
      ['SurfaceReferenceableType', $settings, ['event_venue', 'user_role'], ['user', 'nothing'], $string],
      // What Declaring::holdIdentityOnce() adds to a venue's machine name.
      ['SurfaceUnique', $venue, ['deja_vu'], ['riverside'], $string],
      // NodeTypeAlter, on a list of strings; and on a list of maps, of
      // type `any` and as a list of maps.
      ['UniqueItems', [], [['a', 'b'], []], [['a', 'a']], ListDataDefinition::create('string')],
      ['UniqueItems', [], [[['x' => 1], ['x' => 2]]], [[['x' => 1], ['x' => 1]]], NULL],
      ['UniqueItems', [], [[['x' => 1], ['x' => 2]]], [[['x' => 1], ['x' => 1]]], ListDataDefinition::create('map')],
      // A constraint outside the Default group still runs.
      ['UniqueItems', ['groups' => ['other']], [['a', 'b']], [['a', 'a']], ListDataDefinition::create('string')],
      // RegistrationStep2Resource.
      ['SurfaceValidReference', ['entityTypeId' => 'event_venue'], ['riverside', 'harbour'], ['nowhere'], $string],
      // SponsorshipResource: an article, by bundle.
      ['SurfaceValidReference', ['entityTypeId' => 'node', 'bundles' => ['article']], ['1'], ['2', '9'], $string],
    ];
  }

  /**
   * Writes violations as what they say and where.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $violations
   *   The violations.
   *
   * @return list<array<string, mixed>>
   *   One row per violation: its template, parameters, path and code, its
   *   message's class and words, and what a door says of it.
   */
  protected static function rows(ConstraintViolationListInterface $violations): array {
    $rows = [];
    $plain = static fn (mixed $value): mixed => $value instanceof \Stringable ? (string) $value : $value;
    $type = static fn (mixed $value): string => is_object($value) ? $value::class : gettype($value);
    foreach ($violations as $violation) {
      $message = $violation->getMessage();
      $said = ViolationMessage::of($violation);
      $rows[] = [
        'template' => (string) $violation->getMessageTemplate(),
        'parameters' => array_map($plain, $violation->getParameters()),
        'path' => (string) $violation->getPropertyPath(),
        'code' => $violation->getCode(),
        'message class' => $type($message),
        'message' => (string) $message,
        'said class' => $type($said),
        'said' => (string) $said,
      ];
    }
    return $rows;
  }

  /**
   * Tests each plugin says the same of a plain value on both paths.
   */
  public function testPluginsReadThePlainValue(): void {
    $constraints = $this->container->get('validation.constraint');
    $runner = $this->container->get('surface.constraint_runner');
    $seen = [];
    foreach (static::cases() as [$id, $options, $passes, $refuses, $definition]) {
      $seen[$id] = TRUE;
      foreach ([...$passes, ...$refuses] as $index => $value) {
        $label = sprintf('%s %s on %s', $id, json_encode($options), json_encode($value));
        $alone = static::rows($runner->check($id, $options, $value, $definition));
        $this->assertSame(static::rows($runner->typed($id, $options, $value, $definition)), $alone, $label . ': the plain path says what the typed path says.');
        if ($index < count($passes)) {
          $this->assertSame([], $alone, $label . ': passes.');
        }
        else {
          $this->assertNotSame([], $alone, $label . ': refused.');
        }
      }
    }
    $shipped = array_keys(array_filter($constraints->getDefinitions(), static fn (array $definition): bool => ($definition['provider'] ?? NULL) === 'surface'));
    sort($shipped);
    $held = array_keys($seen);
    sort($held);
    $this->assertSame([
      'JsonSchema',
      'LabeledChoice',
      'LanguageExists',
      'SurfaceLinkUri',
      'SurfaceReferenceableType',
      'SurfaceUnique',
      'SurfaceValidReference',
      'UniqueItems',
    ], $held);
    $this->assertSame($shipped, $held, 'Every constraint plugin Surface ships is held here.');
    $this->assertSame($held, array_keys(ConstraintRunner::VALUE_ONLY), 'The runner runs each on the plain value.');
    foreach (ConstraintRunner::VALUE_ONLY as $id => $class) {
      $this->assertSame($class, $constraints->getDefinition($id)['class'], "$id: the runner names the class Surface ships.");
    }
  }

  /**
   * Tests UniqueItems turns a typed value into its plain value first.
   *
   * Typed Data hands a validator the list object where the item type does
   * not unwrap, a field item list, and a caller can hand in a typed list
   * itself; the duplicates in either are refused, as in a plain array.
   */
  public function testUniqueItemsUnwrapsTypedValues(): void {
    $runner = $this->container->get('surface.constraint_runner');
    $typed = $this->container->get('typed_data_manager');
    $fieldItems = ListDataDefinition::create('field_item:string');
    $cases = [
      'a field item list' => [[['value' => 'a'], ['value' => 'a']], [['value' => 'a'], ['value' => 'b']], $fieldItems],
      'a typed list of strings' => [
        $typed->create(ListDataDefinition::create('string'), ['a', 'a']),
        $typed->create(ListDataDefinition::create('string'), ['a', 'b']),
        NULL,
      ],
      'a typed list of maps' => [
        $typed->create(ListDataDefinition::create('map'), [['k' => 1], ['k' => 1]]),
        $typed->create(ListDataDefinition::create('map'), [['k' => 1], ['k' => 2]]),
        NULL,
      ],
    ];
    foreach ($cases as $label => [$repeated, $distinct, $definition]) {
      $refused = $runner->check('UniqueItems', [], $repeated, $definition);
      $this->assertCount(1, $refused, $label . ': a repeated item is refused.');
      $this->assertSame(Unique::IS_NOT_UNIQUE, $refused[0]->getCode(), $label);
      $this->assertCount(0, $runner->check('UniqueItems', [], $distinct, $definition), $label . ': distinct items pass.');
    }
  }

}

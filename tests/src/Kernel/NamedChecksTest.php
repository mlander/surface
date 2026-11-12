<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMap;
use Drupal\surface\Surface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the document names every check the server runs.
 *
 * A map's own constraints are named under `portable-check` at the map: a
 * key's map, a map in a map, the map each item of a list is (its
 * `items`); a map with none names nothing. Drupal's Email and Uuid are
 * named beside their format, after it, with no message of their own; a
 * Uuid no format states keeps its label as its message. A twin travels
 * with its format into a narrowing; a composite holding Email is named
 * beside its parts; a message the server fills in is not written.
 *
 * @see docs/decisions.md#the-checks-the-document-names
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class NamedChecksTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
  ];

  /**
   * Emits the properties of a data surface built of these definitions.
   *
   * @param array<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The keys.
   *
   * @return array
   *   The document's properties, decoded.
   */
  protected function emitted(array $definitions): array {
    $surface = new Surface(DefinitionMap::fromArrays(definitions: $definitions));
    $schema = $this->container->get('surface.contract_emitter')->emit($surface, [], 'test')->document['schema'];
    return json_decode((string) json_encode($schema['properties']), TRUE);
  }

  /**
   * Makes a map with a constraint of its own.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  protected static function deadline(): MapDataDefinition {
    $deadline = MapDataDefinition::create()->setLabel('Deadline');
    $deadline->setPropertyDefinition('amount', DataDefinition::create('integer')->setLabel('Amount'));
    $deadline->addConstraint('Callback', ['callback' => 'is_array']);
    return $deadline;
  }

  /**
   * Tests a map's own constraints are named at the map, at any depth.
   */
  public function testMapChecksAreNamed(): void {
    $outer = MapDataDefinition::create()->setLabel('Outer');
    $outer->setPropertyDefinition('inner', static::deadline());
    $lines = ListDataDefinition::create('map')->setLabel('Lines');
    $lines->setItemDefinition(static::deadline());
    $plain = MapDataDefinition::create()->setLabel('Plain');
    $plain->setPropertyDefinition('n', DataDefinition::create('integer')->setLabel('N'));
    $properties = $this->emitted([
      'map' => static::deadline(),
      'outer' => $outer,
      'lines' => $lines,
      'plain' => $plain->setRequired(TRUE),
    ]);
    $checked = ['drupal:Callback' => []];
    $messages = ['check.drupal:Callback' => 'Callback'];
    $nodes = [
      'map' => $properties['map'],
      'outer.inner' => $properties['outer']['properties']['inner'],
      'lines.*' => $properties['lines']['items'],
    ];
    foreach ($nodes as $at => $node) {
      $this->assertSame($checked, $node['portable-check'], $at);
      $this->assertSame($messages, $node['x-portable-messages'], $at . ': the plugin label, as every check with no message of its own.');
      $keys = array_keys($node);
      $this->assertSame(['additionalProperties', 'portable-check', 'x-portable-messages'], array_slice($keys, (int) array_search('additionalProperties', $keys, TRUE)), $at . ': after the standard keywords.');
    }
    $this->assertArrayNotHasKey('portable-check', $properties['outer'], 'A map with no constraint of its own names none.');
    $this->assertArrayNotHasKey('portable-check', $properties['plain'], 'NotNull is `required`, never a check.');
  }

  /**
   * Tests a twin travels with its format into a narrowing.
   *
   * The press resource, event.press, refines its contact's email
   * (required while listed): the narrowing at `allOf/1` drops the
   * `format`, which the event.person def states, and so drops the def's
   * `drupal:Email` too, in the full and the hints document.
   */
  public function testTwinIsNotNarrowedWithoutItsFormat(): void {
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    $run = $this->container->get('surface.runner')->run('event.press', 'configure', values: [], dryRun: TRUE);
    $surface = $run->surface();
    $current = ['public' => TRUE] + $run->storage()->load($surface);
    $emitter = $this->container->get('surface.contract_emitter');
    foreach (['full' => FALSE, 'hints' => TRUE] as $kind => $hints) {
      $document = json_decode((string) json_encode($emitter->emit($surface, $current, 'event.press', 'configure', NULL, $hints)->document['schema']), TRUE);
      $email = $document['properties']['contact']['allOf'][1]['properties']['email'];
      $narrowed = ['description' => 'Required while this person is listed publicly.', 'type' => 'string'];
      $this->assertSame($narrowed + ($hints ? ['x-portable-ui' => ['widget' => 'email']] : []), $email, $kind);
      $def = $document['$defs']['event.person']['properties']['email'];
      $twin = ['format' => 'email', 'portable-check' => ['drupal:Email' => []]];
      $this->assertSame($twin, array_intersect_key($def, $twin), $kind . ': the def names it beside its format.');
    }
  }

  /**
   * Tests Email and Uuid are named beside their format, with no message.
   */
  public function testHostFormatsAreNamed(): void {
    $properties = $this->emitted([
      'mail' => DataDefinition::create('email')->setLabel('Mail'),
      'address' => DataDefinition::create('string')->setLabel('Address')->addConstraint('Email', []),
      'id' => DataDefinition::create('string')->setLabel('Id')->addConstraint('Uuid', []),
      'v4' => DataDefinition::create('string')->setLabel('V4')->addConstraint('Uuid', ['versions' => [4]]),
      'loose' => DataDefinition::create('string')->setLabel('Loose')->addConstraint('Uuid', ['strict' => FALSE]),
    ]);
    $node = static fn (string $title, string $format, string $check): array => [
      'title' => $title,
      'type' => ['string', 'null'],
      'format' => $format,
      'portable-check' => ['drupal:' . $check => []],
    ];
    $this->assertSame($node('Mail', 'email', 'Email'), $properties['mail']);
    $this->assertSame($node('Address', 'email', 'Email'), $properties['address']);
    $this->assertSame($node('Id', 'uuid', 'Uuid'), $properties['id']);
    $this->assertSame($node('V4', 'uuid', 'Uuid'), $properties['v4'], 'Its versions are the server\'s.');
    // No format states a Uuid that is not strict: a check like any other.
    $this->assertSame(['drupal:Uuid' => []], $properties['loose']['portable-check']);
    $this->assertArrayNotHasKey('format', $properties['loose']);
    $this->assertSame(['check.drupal:Uuid' => 'Universally Unique Identifier'], $properties['loose']['x-portable-messages']);
  }

  /**
   * Tests a composite holding Email is written as its parts and named.
   *
   * Email's format admits what the constraint refuses, so the composite
   * is named beside the keyword its parts are written as.
   */
  public function testCompositesHoldingEmailAreNamed(): void {
    $properties = $this->emitted([
      'seq' => DataDefinition::create('string')->setLabel('Seq')->addConstraint('Sequentially', [
        'constraints' => [['NotBlank' => []], ['Email' => []]],
      ]),
      'one' => DataDefinition::create('string')->setLabel('One')->addConstraint('AtLeastOneOf', [
        'constraints' => [['Email' => []], ['Length' => ['max' => 2]]],
      ]),
    ]);
    $this->assertSame([
      'title' => 'Seq',
      'type' => ['string', 'null'],
      'allOf' => [['minLength' => 1], ['format' => 'email']],
      'portable-check' => ['drupal:Sequentially' => []],
      'x-portable-messages' => ['check.drupal:Sequentially' => 'Sequentially validate multiple constraints'],
    ], $properties['seq']);
    $this->assertSame([
      'title' => 'One',
      'type' => ['string', 'null'],
      'anyOf' => [['format' => 'email'], ['maxLength' => 2]],
      'portable-check' => ['drupal:AtLeastOneOf' => []],
      'x-portable-messages' => ['check.drupal:AtLeastOneOf' => 'At least one of'],
    ], $properties['one'], 'The parts, then the composite named, with its label as every check with no message of its own.');
  }

  /**
   * Tests a check's message the server fills in is not written.
   *
   * The dialect's messages are plain sentences: an Email whose message
   * holds `%value` names no message, and the server's refusal says the
   * sentence with the value in it.
   */
  public function testPlaceholderMessageIsNotWritten(): void {
    $definitions = [
      'mail' => DataDefinition::create('string')->setLabel('Mail')->addConstraint('Email', ['message' => '%value is no address here.']),
    ];
    $properties = $this->emitted($definitions);
    $this->assertSame(['drupal:Email' => []], $properties['mail']['portable-check']);
    $this->assertArrayNotHasKey('x-portable-messages', $properties['mail']);
    $surface = new Surface(DefinitionMap::fromArrays(definitions: $definitions));
    $pipeline = $this->container->get('surface.pipeline');
    $refused = [];
    foreach ($pipeline->validate($surface, $pipeline->accept($surface, ['mail' => 'a@b'])) as $violation) {
      $refused[] = $violation->fullPath() . ' ' . html_entity_decode(strip_tags((string) $violation->message));
    }
    $this->assertSame(['mail "a@b" is no address here.'], $refused);
  }

}

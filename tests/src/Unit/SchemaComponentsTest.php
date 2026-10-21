<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface_openapi\SchemaComponents;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests full schemas split into OpenAPI components, and their patches.
 *
 * Each def becomes a component named by its def id, the root the
 * operation's values component, and every def pointer, in a `$ref` or an
 * `x-surface.variants` map, points at its component. Two documents share
 * a def they describe the same way and keep one each where they differ.
 * A patch drops `required` only where dropping it widens.
 *
 * @see docs/decisions.md#an-openapi-document-is-one-more-door
 */
#[Group('data_surface')]
class SchemaComponentsTest extends UnitTestCase {

  /**
   * A full schema with a def, a key with variants and a conditional.
   *
   * @param string $label
   *   The contact def's title, to make two documents differ.
   *
   * @return array
   *   The schema.
   */
  protected static function schema(string $label = 'Name'): array {
    return [
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      'title' => 'Configure',
      'type' => 'object',
      'properties' => [
        'kind' => ['type' => 'string'],
        'contact' => ['$ref' => '#/$defs/test.contact', 'title' => 'Contact'],
        'ticket' => [
          'anyOf' => [['$ref' => '#/$defs/test.ticket.free'], ['type' => 'null']],
          'x-surface' => [
            'by' => 'kind',
            'variants' => ['free' => '#/$defs/test.ticket.free'],
            'ports' => ['p' => ['required' => TRUE]],
          ],
        ],
        'rows' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/test.contact'], 'x-surface' => ['list' => TRUE]],
      ],
      'required' => ['kind'],
      'additionalProperties' => FALSE,
      'allOf' => [
        [
          'if' => ['properties' => ['kind' => ['const' => 'free']], 'required' => ['kind']],
          'then' => ['required' => ['ticket']],
        ],
        ['not' => ['required' => ['rows']]],
        ['oneOf' => [['required' => ['kind']], ['required' => ['contact']]]],
      ],
      '$defs' => [
        'test.contact' => [
          'title' => 'test.contact',
          'type' => 'object',
          'properties' => ['name' => ['type' => 'string', 'title' => $label]],
          'required' => ['name'],
        ],
        'test.ticket.free' => [
          'title' => 'test.ticket.free',
          'type' => 'object',
          'properties' => ['holder' => ['$ref' => '#/$defs/test.contact']],
          'required' => ['holder'],
        ],
      ],
    ];
  }

  /**
   * Tests the defs become components and every pointer follows them.
   */
  public function testDefsBecomeComponents(): void {
    $components = new SchemaComponents();
    $root = $components->add(self::schema(), 'test.thing:configure');
    $this->assertSame('test.thing_configure', $root, 'A character a component name does not allow is written _.');
    $schemas = $components->all();
    $this->assertSame(['test.contact', 'test.thing_configure', 'test.ticket.free'], array_keys($schemas));
    $this->assertArrayNotHasKey('$defs', $schemas[$root]);
    $this->assertSame('#/components/schemas/test.contact', $schemas[$root]['properties']['contact']['$ref']);
    $this->assertSame('#/components/schemas/test.ticket.free', $schemas[$root]['properties']['ticket']['x-surface']['variants']['free'], 'A variants pointer follows its def.');
    $this->assertSame('#/components/schemas/test.contact', $schemas['test.ticket.free']['properties']['holder']['$ref'], 'A def pointing at a def.');
    $expected = [
      'by' => 'kind',
      'variants' => ['free' => '#/components/schemas/test.ticket.free'],
      'ports' => ['p' => ['required' => TRUE]],
    ];
    $this->assertSame($expected, $schemas[$root]['properties']['ticket']['x-surface'], 'Nothing else in x-surface moves.');
  }

  /**
   * Tests a def described the same way is shared, and one that differs not.
   */
  public function testTheSameDefIsSharedAndAnotherKeepsItsOwn(): void {
    $components = new SchemaComponents();
    $components->add(self::schema(), 'one');
    $components->add(self::schema(), 'two');
    $components->add(self::schema('Full name'), 'three');
    $schemas = $components->all();
    $names = ['one', 'test.contact', 'test.contact-2', 'test.ticket.free', 'test.ticket.free-2', 'three', 'two'];
    $this->assertSame($names, array_keys($schemas));
    $this->assertSame('#/components/schemas/test.contact', $schemas['two']['properties']['contact']['$ref']);
    $this->assertSame('#/components/schemas/test.contact-2', $schemas['three']['properties']['contact']['$ref']);
    $this->assertSame('test.contact', $schemas['test.contact-2']['title'], 'Its title is still its def id.');
    $this->assertSame('#/components/schemas/test.contact-2', $schemas['test.ticket.free-2']['properties']['holder']['$ref'], 'A def whose def differs differs too.');
  }

  /**
   * Tests a patch drops required only where that widens.
   */
  public function testPatchDropsOnlyWhatWidens(): void {
    $components = new SchemaComponents();
    $root = $components->add(self::schema(), 'thing');
    $patch = $components->patch($root);
    $this->assertSame('thing.patch', $patch);
    $schema = $components->get($patch);
    $this->assertArrayNotHasKey('required', $schema);
    $this->assertSame('#/components/schemas/test.contact.patch', $schema['properties']['contact']['$ref']);
    $this->assertSame('#/components/schemas/test.ticket.free.patch', $schema['properties']['ticket']['anyOf'][0]['$ref']);
    $this->assertSame('#/components/schemas/test.ticket.free', $schema['properties']['ticket']['x-surface']['variants']['free'], 'x-surface is not touched.');
    $this->assertSame('#/components/schemas/test.contact', $schema['properties']['rows']['items']['$ref'], 'An item is sent whole.');
    $this->assertSame(['kind'], $schema['allOf'][0]['if']['required'], 'Kept inside an if, where dropping would narrow.');
    $this->assertEquals(new \stdClass(), $schema['allOf'][0]['then'], 'A then that only required allows anything, written {}.');
    $this->assertSame(['not' => ['required' => ['rows']]], $schema['allOf'][1], 'Kept inside a not.');
    $this->assertSame(self::schema()['allOf'][2], $schema['allOf'][2], 'Kept inside a oneOf.');
    $this->assertArrayNotHasKey('required', $components->get('test.contact.patch'));
    $this->assertSame('#/components/schemas/test.contact.patch', $components->get('test.ticket.free.patch')['properties']['holder']['$ref']);
    $this->assertSame(['name'], $components->get('test.contact')['required'], 'The full component keeps its own.');
    $this->assertSame($patch, $components->patch($root), 'Written once.');
  }

}

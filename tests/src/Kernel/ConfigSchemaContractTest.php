<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface_examples\ConfigSchemaContract;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what config schema alone can say about an example's settings.
 *
 * The before picture under each example: what core answers when asked
 * for a JSON Schema of the config object, and the plain mapping of its
 * config schema. The examples' schema is written from their resources
 * now (drush surface:config-schema), so it carries types, bounds
 * and what is always held; the lists of values, what depends on what and
 * the ticket's shape it does not say. Both are asserted
 * whole, so a schema written otherwise, a cleverer mapping, or core
 * starting to answer shows up here first.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaContractTest extends SurfaceKernelTestBase {

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
    'surface_examples',
  ];

  /**
   * The keywords the examples' config schema has nothing to say with.
   */
  protected const UNSAID = ['enum', 'oneOf', 'default', 'allOf', 'if'];

  /**
   * The comment a type that needs a value is given.
   */
  protected const NEEDS_A_VALUE = 'Declared as the type %s, which can only be resolved against a stored value.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['surface_examples']);
  }

  /**
   * Gets the service under test.
   *
   * @return \Drupal\surface_examples\ConfigSchemaContract
   *   The service.
   */
  protected function contract(): ConfigSchemaContract {
    return $this->container->get('surface_examples.config_schema_contract');
  }

  /**
   * Lists every keyword a schema uses, at any depth.
   *
   * @param array $schema
   *   The schema.
   *
   * @return string[]
   *   The keys of every object in it. A property named like a keyword
   *   would be listed too; the examples have none.
   */
  protected function keywordsIn(array $schema): array {
    $keywords = [];
    foreach ($schema as $key => $value) {
      $keywords[(string) $key] = TRUE;
      if (is_array($value)) {
        $keywords += array_fill_keys($this->keywordsIn($value), TRUE);
      }
    }
    return array_keys($keywords);
  }

  /**
   * Tests example 1: types, titles, the capacity's bounds and every key held.
   *
   * Fully validatable, so every key is in `required`; the capacity and the
   * open flag are optional in the resource, so null clears them.
   */
  public function testExampleOneIsTypesAndTitles(): void {
    $derived = $this->contract()->derived('surface_examples.registration_step1');
    $this->assertSame([
      '$schema' => ContractEmitter::DIALECT,
      'title' => 'Registration settings, example 1',
      'type' => 'object',
      'properties' => [
        'title' => ['title' => 'Event title', 'type' => 'string'],
        'capacity' => ['title' => 'Capacity', 'type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 1000],
        'open' => ['title' => 'Registration open', 'type' => ['boolean', 'null']],
      ],
      'additionalProperties' => FALSE,
      'required' => ['title', 'capacity', 'open'],
    ], $derived);
    $this->assertSame([], array_values(array_intersect(self::UNSAID, $this->keywordsIn($derived))));

    // The examples' README quotes it, as the panel prints it.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/surface_examples/README.md');
    $printed = json_encode($derived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $this->assertStringContainsString("<!-- json: surface_examples.registration_step1 -->\n\n```json\n" . $printed . "\n```", $readme);
  }

  /**
   * Tests example 3: no lists, and still no shape for the ticket.
   *
   * The keys come in the resource's order. The venue, the room and the
   * pricing are strings with no list, since a generated schema leaves the
   * values to the resource; the ticket's type needs the pricing's value, so it
   * is named and not resolved; and what other modules mount is named the
   * same way, by its `[%key]`.
   */
  public function testExampleThreeHasNoListsNoBoundsAndNoTicketShape(): void {
    $derived = $this->contract()->derived('surface_examples.registration_step3');
    $expected = [
      '$schema' => ContractEmitter::DIALECT,
      'title' => 'Registration settings, example 3',
      'type' => 'object',
      'properties' => [
        'title' => ['title' => 'Event title', 'type' => 'string'],
        'open' => ['title' => 'Registration open', 'type' => ['boolean', 'null']],
        'venue' => ['title' => 'Venue', 'type' => 'string'],
        'room' => ['title' => 'Room', 'type' => 'string'],
        'capacity' => ['title' => 'Capacity', 'type' => ['integer', 'null'], 'minimum' => 1],
        'pricing' => ['title' => 'Pricing', 'type' => 'string'],
        'ticket' => [
          'title' => 'Ticket',
          '$comment' => sprintf(self::NEEDS_A_VALUE, 'surface_examples.registration_step3.ticket.[%parent.pricing]'),
        ],
        'contact' => [
          'title' => 'Contact',
          'type' => 'object',
          'properties' => [
            'email' => ['title' => 'Email', 'type' => 'string', 'format' => 'email'],
            'phone' => ['title' => 'Phone', 'type' => ['string', 'null']],
          ],
          'additionalProperties' => FALSE,
          'required' => ['email', 'phone'],
        ],
        'third_party_settings' => [
          'title' => 'Third party settings',
          'type' => 'object',
          'additionalProperties' => [
            '$comment' => sprintf(self::NEEDS_A_VALUE, 'surface_examples.registration_step3.third_party.[%key]'),
          ],
        ],
      ],
      'additionalProperties' => FALSE,
      'required' => ['title', 'open', 'venue', 'room', 'capacity', 'pricing', 'ticket', 'contact'],
    ];
    $this->assertSame($expected, $derived);
    $this->assertSame([], array_values(array_intersect(self::UNSAID, $this->keywordsIn($derived))));
    $this->assertArrayNotHasKey('type', $derived['properties']['ticket']);

    // No stored value is read: paid, the mapping says the same.
    $this->config('surface_examples.registration_step3')
      ->set('pricing', 'paid')
      ->set('ticket', ['price' => 12.5, 'currency' => 'EUR'])
      ->save();
    $this->assertSame($expected, $this->contract()->derived('surface_examples.registration_step3'));

    // Nor is what another module mounts guessed at: example 4's module
    // describes its keys under its own name, which only a stored key
    // would lead to.
    $this->enableModules(['surface_examples_compliance']);
    $this->assertTrue($this->container->get('config.typed')->hasConfigSchema('surface_examples.registration_step3.third_party.surface_examples_compliance'));
    $this->assertSame($expected, $this->contract()->derived('surface_examples.registration_step3'));
  }

  /**
   * Tests what core answers: a comment, and no property.
   *
   * Asked of core, not written down, so the day core derives a schema
   * from typed config this fails and the panel's first block has
   * something to show.
   */
  public function testCoreAnswersWithOneComment(): void {
    foreach (['surface_examples.registration_step1', 'surface_examples.registration_step3'] as $name) {
      $core = $this->contract()->core($name);
      $this->assertSame(
        $this->container->get('serializer')->normalize($this->container->get('config.typed')->get($name), 'json_schema'),
        $core,
      );
      $this->assertArrayHasKey('$comment', $core);
      $this->assertStringContainsString('No schema is defined', $core['$comment']);
      $this->assertArrayNotHasKey('properties', $core);
      $this->assertArrayNotHasKey('type', $core);
      $this->assertSame(['core' => $core, 'derived' => $this->contract()->derived($name)], $this->contract()->describe($name));
    }
  }

  /**
   * Tests the mapping says what a schema does declare.
   *
   * What keeps it fair: core's maintenance settings are fully
   * validatable and their message is a `text`, whose type refuses
   * control characters, so there the mapping says the key is required
   * and gives the pattern.
   */
  public function testDeclaredConstraintsAreSaid(): void {
    $derived = $this->contract()->derived('system.maintenance');
    $this->assertSame(['message'], $derived['required']);
    $this->assertFalse($derived['additionalProperties']);
    $this->assertSame(['message'], array_keys($derived['properties']));
    $message = $derived['properties']['message'];
    $this->assertSame('string', $message['type']);
    $this->assertArrayHasKey('pattern', $message['not']);
  }

}

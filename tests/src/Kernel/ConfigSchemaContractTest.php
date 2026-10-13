<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface_examples\ConfigSchemaContract;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what config schema alone can say about an example's settings.
 *
 * The before picture under each example: what core answers when asked
 * for a JSON Schema of the config object, and the plain mapping of its
 * config schema. Both are asserted whole, so a constraint added to the
 * examples' schema, a cleverer mapping, or core starting to answer shows
 * up here first.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ConfigSchemaContractTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * The keywords the examples' config schema has nothing to say with.
   */
  protected const UNSAID = ['required', 'enum', 'oneOf', 'minimum', 'maximum', 'default', 'allOf', 'if'];

  /**
   * The comment a type that needs a value is given.
   */
  protected const NEEDS_A_VALUE = 'Declared as the type %s, which can only be resolved against a stored value.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['data_surface_examples']);
  }

  /**
   * Gets the service under test.
   *
   * @return \Drupal\data_surface_examples\ConfigSchemaContract
   *   The service.
   */
  protected function contract(): ConfigSchemaContract {
    return $this->container->get('data_surface_examples.config_schema_contract');
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
   * Tests example 1: three types and three titles, and nothing else.
   */
  public function testExampleOneIsTypesAndTitles(): void {
    $derived = $this->contract()->derived('data_surface_examples.registration_step1');
    $this->assertSame([
      '$schema' => ContractEmitter::DIALECT,
      'title' => 'Registration settings, example 1',
      'type' => 'object',
      'properties' => [
        'title' => ['title' => 'Event title', 'type' => 'string'],
        'capacity' => ['title' => 'Capacity', 'type' => 'integer'],
        'open' => ['title' => 'Registration open', 'type' => 'boolean'],
      ],
      'additionalProperties' => FALSE,
    ], $derived);
    $this->assertSame([], array_values(array_intersect(self::UNSAID, $this->keywordsIn($derived))));

    // The examples' README quotes it, as the panel prints it.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    $printed = json_encode($derived, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $this->assertStringContainsString("<!-- json: data_surface_examples.registration_step1 -->\n\n```json\n" . $printed . "\n```", $readme);
  }

  /**
   * Tests example 3: the inherited keys, and no shape for the ticket.
   *
   * The type is example 2's, which is example 1's, so the keys come in
   * that order. The venue, the room and the pricing are strings with no
   * list; the capacity an integer with no bounds; the ticket's type
   * needs the pricing's value, so it is named and not resolved; and
   * what other modules mount is named the same way, by its `[%key]`.
   */
  public function testExampleThreeHasNoListsNoBoundsAndNoTicketShape(): void {
    $derived = $this->contract()->derived('data_surface_examples.registration_step3');
    $expected = [
      '$schema' => ContractEmitter::DIALECT,
      'title' => 'Registration settings, example 3',
      'type' => 'object',
      'properties' => [
        'title' => ['title' => 'Event title', 'type' => 'string'],
        'capacity' => ['title' => 'Capacity', 'type' => 'integer'],
        'open' => ['title' => 'Registration open', 'type' => 'boolean'],
        'venue' => ['title' => 'Venue', 'type' => 'string'],
        'room' => ['title' => 'Room', 'type' => 'string'],
        'pricing' => ['title' => 'Pricing', 'type' => 'string'],
        'ticket' => [
          'title' => 'Ticket',
          '$comment' => sprintf(self::NEEDS_A_VALUE, 'data_surface_examples.ticket.[%parent.pricing]'),
        ],
        'contact' => [
          'title' => 'Contact',
          'type' => 'object',
          'properties' => [
            'email' => ['title' => 'Email', 'type' => 'string', 'format' => 'email'],
            'phone' => ['title' => 'Phone', 'type' => 'string'],
          ],
          'additionalProperties' => FALSE,
        ],
        'third_party_settings' => [
          'title' => 'Third party settings',
          'type' => 'object',
          'additionalProperties' => [
            '$comment' => sprintf(self::NEEDS_A_VALUE, 'data_surface_examples.registration.third_party.[%key]'),
          ],
        ],
      ],
      'additionalProperties' => FALSE,
    ];
    $this->assertSame($expected, $derived);
    $this->assertSame([], array_values(array_intersect(self::UNSAID, $this->keywordsIn($derived))));
    $this->assertArrayNotHasKey('type', $derived['properties']['ticket']);

    // No stored value is read: paid, the mapping says the same.
    $this->config('data_surface_examples.registration_step3')
      ->set('pricing', 'paid')
      ->set('ticket', ['price' => 12.5, 'currency' => 'EUR'])
      ->save();
    $this->assertSame($expected, $this->contract()->derived('data_surface_examples.registration_step3'));

    // Nor is what another module mounts guessed at: example 4's module
    // describes its keys under its own name, which only a stored key
    // would lead to.
    $this->enableModules(['data_surface_examples_compliance']);
    $this->assertTrue($this->container->get('config.typed')->hasConfigSchema('data_surface_examples.registration.third_party.data_surface_examples_compliance'));
    $this->assertSame($expected, $this->contract()->derived('data_surface_examples.registration_step3'));
  }

  /**
   * Tests what core answers: a comment, and no property.
   *
   * Asked of core, not written down, so the day core derives a schema
   * from typed config this fails and the panel's first block has
   * something to show.
   */
  public function testCoreAnswersWithOneComment(): void {
    foreach (['data_surface_examples.registration_step1', 'data_surface_examples.registration_step3'] as $name) {
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

<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\Computed;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface_resource_test\Compute\BadgeCode;
use Drupal\surface_resource_test\Compute\Broken\ClockStamp;
use Drupal\surface_resource_test\Resource\Badge\BadgeResource;
use Drupal\surface_resource_test\Storage\BadgeStorage;
use Drupal\surface_resource_test\Structure\ThresholdStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a key computed from others: declared, computed, judged and stated.
 *
 * The badge fixture computes its code from the first name once, while
 * nothing is stored for it, its handle from both names again on every
 * write that changes one of them, held to twelve characters, and its tag,
 * a machine name, from the last name unless one is sent.
 *
 * @see docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ComputedKeysTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the badge for its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built badge.
   */
  protected function badge(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(BadgeResource::class, $resources->operation(BadgeResource::class, 'configure'));
  }

  /**
   * Writes values to the badge, or rehearses them.
   *
   * @param array $input
   *   The values sent.
   * @param bool $dry_run
   *   TRUE to write nothing.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function submit(array $input, bool $dry_run = FALSE): SurfaceResult {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(BadgeResource::class, 'configure');
    $built = $resources->build(BadgeResource::class, $context);
    return $this->pipeline()->submit($built, $input, $resources->storage(BadgeResource::class, $context, $built), $dry_run);
  }

  /**
   * Reads what the badge stores.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    return $this->container->get('state')->get(BadgeStorage::KEY, []);
  }

  /**
   * Lists a result's violations, or notes, by path, code and message.
   *
   * @param iterable<\Drupal\surface\Pipeline\Violation> $violations
   *   The violations.
   *
   * @return list<array{0: string, 1: string, 2: string}>
   *   Each as its path, its code and its message, rendered for a person
   *   as plain text.
   */
  protected static function said(iterable $violations): array {
    $said = [];
    foreach ($violations as $violation) {
      $said[] = [$violation->fullPath(), $violation->code, strip_tags((string) $violation->message)];
    }
    return $said;
  }

  /**
   * Tests the key builds readOnly with its marker, in both documents.
   */
  public function testBothDocumentsStateItReadOnlyAndComputed(): void {
    $built = $this->badge();
    $this->assertSame(['first', 'last', 'code', 'handle', 'tag', 'color'], $built->getDefinitions()->names());
    $emitter = $this->container->get('surface.contract_emitter');
    $documents = [
      'full' => $emitter->emitFull($built, 'resource_test.badge', 'configure')->document['schema'],
      'these' => $emitter->emit($built, ['first' => 'Ada'] + $built->getDefaultValues(), 'resource_test.badge', 'configure')->document['schema'],
    ];
    foreach ($documents as $kind => $schema) {
      $this->assertSame(['first'], $schema['required'], $kind . ': a computed key is never asked of a caller.');
      $this->assertTrue($schema['properties']['code']['readOnly'], $kind);
      $this->assertArrayNotHasKey('const', $schema['properties']['code'], $kind . ': what it holds follows its sources.');
      $this->assertSame(['from' => ['first'], 'when' => 'create'], $schema['properties']['code']['x-surface']['computed'], $kind);
      $this->assertSame(['from' => ['first', 'last'], 'when' => 'change'], $schema['properties']['handle']['x-surface']['computed'], $kind);
      $this->assertSame(12, $schema['properties']['handle']['maxLength'], $kind . ': its own constraints are stated.');
      // Read only as a computed key, not locked: `computed` says why, and
      // there is no `const`.
      $this->assertTrue($schema['properties']['handle']['readOnly'], $kind);
      $this->assertArrayNotHasKey('const', $schema['properties']['handle'], $kind);
      $this->assertArrayNotHasKey('readOnly', $schema['properties']['first'], $kind);
      $this->assertArrayNotHasKey('computed', $schema['properties']['first']['x-surface'] ?? [], $kind);
      // Computed unless sent: offered, and still marked, the mode named.
      $this->assertArrayNotHasKey('readOnly', $schema['properties']['tag'], $kind . ': a value may be typed over it.');
      $this->assertSame(['from' => ['last'], 'when' => 'unless_sent'], $schema['properties']['tag']['x-surface']['computed'], $kind);
      $this->assertSame('^[a-z0-9_]+$', $schema['properties']['tag']['pattern'], $kind . ': the machine_name type\'s own Regex.');
      $this->assertSame(8, $schema['properties']['tag']['maxLength'], $kind . ': narrowed by the key.');
    }
  }

  /**
   * Tests both are computed while nothing is stored, and written.
   */
  public function testComputedWhenNothingIsStored(): void {
    $result = $this->submit(['first' => 'Ada', 'last' => 'Byron']);
    $this->assertTrue($result->isValid(), print_r(static::said($result->violations), TRUE));
    $this->assertSame([
      'first' => 'Ada',
      'last' => 'Byron',
      'code' => 'ADA',
      'handle' => 'ada.byron',
      'tag' => 'byron',
      'color' => 'blue',
    ], $this->stored());
    $this->assertContains('/code', array_map(static fn (string $path): string => '/' . $path, $result->changes));
  }

  /**
   * Tests an update: kept unless `when` says so and a source changed.
   */
  public function testAnUpdateKeepsWhatIsStoredUnlessOneOfItsSourcesChanged(): void {
    $this->submit(['first' => 'Ada', 'last' => 'Byron']);
    $this->assertTrue($this->submit(['color' => 'red'])->isValid());
    $stored = $this->stored();
    $this->assertSame(['ADA', 'ada.byron', 'red'], [$stored['code'], $stored['handle'], $stored['color']], 'No source changed: both kept.');
    $this->assertTrue($this->submit(['first' => 'Ann'])->isValid());
    $this->assertSame('ADA', $this->stored()['code'], 'On create: set once, kept after.');
    $this->assertSame('ann.byron', $this->stored()['handle'], 'On every change: computed again.');
    $this->assertTrue($this->submit(['first' => 'Ann', 'color' => 'green'])->isValid());
    $this->assertSame('ann.byron', $this->stored()['handle'], 'Sent as it is stored is no change.');
  }

  /**
   * Tests a value sent for it: noted when it is the one held, else refused.
   */
  public function testValueSentIsNotedOrRefused(): void {
    $this->submit(['first' => 'Ada', 'last' => 'Byron']);
    $equal = $this->submit(['handle' => 'ada.byron', 'code' => 'ADA', 'color' => 'red']);
    $this->assertTrue($equal->isValid());
    $this->assertSame([
      ['code', Violation::COMPUTED, 'Code is computed from First name; the value sent was not taken.'],
      [
        'handle',
        Violation::COMPUTED,
        'Handle is computed from First name and Last name; the value sent was not taken.',
      ],
    ], static::said($equal->notes));
    $this->assertSame('red', $this->stored()['color']);
    $equal_after = $this->submit(['first' => 'Ann', 'handle' => 'ann.byron']);
    $this->assertTrue($equal_after->isValid(), 'Equal to the value computed from the sources sent.');
    $other = $this->submit(['handle' => 'ada', 'color' => 'black']);
    $this->assertFalse($other->isValid());
    $this->assertSame([
      [
        'handle',
        Violation::COMPUTED,
        'Handle is computed from First name and Last name and takes no value of its own; leave it out.',
      ],
    ], static::said($other->violations));
    $this->assertSame(['ann.byron', 'red'], [$this->stored()['handle'], $this->stored()['color']], 'Nothing refused was written.');
  }

  /**
   * Tests a computed value its own constraint refuses names its sources.
   */
  public function testRefusalOfTheComputedValueNamesItsSources(): void {
    $long = $this->submit(['first' => 'Ada', 'last' => 'Montgomery']);
    $this->assertSame([
      [
        'handle',
        'too_long',
        'This value is too long. It should have 12 characters or less. This is computed from First name and Last name.',
      ],
    ], static::said($long->violations));
    $this->assertSame([], $this->stored());
    $source = $this->submit(['first' => 'Bartholomew', 'last' => 'Byron']);
    $this->assertSame([
      ['first', 'too_long', 'This value is too long. It should have 10 characters or less.'],
    ], static::said($source->violations), 'While a source is refused, what follows from it is not said.');
  }

  /**
   * Tests the narrowing answers the value a write of the values would store.
   */
  public function testTheNarrowingAnswersTheComputedValue(): void {
    $built = $this->badge();
    $forms = $this->container->get('surface.form_builder');
    $overlay = $forms->refinementOverlay($built, [], ['first' => 'Grace', 'last' => 'Hop']);
    $this->assertSame(['GRA', 'grace.hop'], [$overlay['code'], $overlay['handle']]);
    $stored = [
      'first' => 'Ada',
      'last' => 'Byron',
      'code' => 'ADA',
      'handle' => 'ada.byron',
      'tag' => 'byron',
      'color' => 'blue',
    ];
    $overlay = $forms->refinementOverlay($built, $stored, ['first' => 'Grace']);
    $this->assertSame(['ADA', 'grace.byron'], [$overlay['code'], $overlay['handle']], 'As a write would: the code kept, the handle computed again.');
    $document = $this->container->get('surface.contract_emitter')->emit($built, $overlay, 'resource_test.badge', 'configure')->document;
    $this->assertSame('grace.byron', $document['values']['handle']);
  }

  /**
   * Tests a key computed unless sent: left out, computed; sent, kept.
   */
  public function testUnlessSentComputesWhatIsLeftOutAndKeepsWhatIsSent(): void {
    // cspell:ignore abernath
    $left_out = $this->submit(['first' => 'Al', 'last' => 'Abernathy']);
    $this->assertTrue($left_out->isValid(), print_r(static::said($left_out->violations), TRUE));
    $this->assertSame([], static::said($left_out->notes));
    $this->assertSame('abernath', $this->stored()['tag'], 'Left out: computed, cut to its Length.');

    \Drupal::state()->delete(BadgeStorage::KEY);
    $sent = $this->submit(['first' => 'Ada', 'last' => 'Byron', 'tag' => 'countess']);
    $this->assertTrue($sent->isValid(), print_r(static::said($sent->violations), TRUE));
    $this->assertSame([], static::said($sent->notes), 'Kept as sent, with no note.');
    $this->assertSame('countess', $this->stored()['tag']);

    \Drupal::state()->delete(BadgeStorage::KEY);
    $empty = $this->submit(['first' => 'Ada', 'last' => 'Byron', 'tag' => '']);
    $this->assertTrue($empty->isValid());
    $this->assertSame('byron', $this->stored()['tag'], 'Sent empty: read as left out.');

    $this->assertTrue($this->submit(['last' => 'King'])->isValid());
    $this->assertSame('byron', $this->stored()['tag'], 'Once stored, kept when left out, as on create.');
    $this->assertTrue($this->submit(['tag' => 'ada_king'])->isValid());
    $this->assertSame('ada_king', $this->stored()['tag'], 'Sent on a later write: kept as sent.');

    $refused = $this->submit(['tag' => 'Ada King']);
    $said = array_map(static fn (array $said): array => [$said[0], $said[1], html_entity_decode($said[2])], static::said($refused->violations));
    $this->assertSame([
      ['tag', 'regex_failed', 'The "Ada King" machine name is not valid.'],
    ], $said, 'A value sent is judged as any key, and its refusal names no source: the value is the caller\'s.');
    $this->assertSame('ada_king', $this->stored()['tag']);
  }

  /**
   * Tests the narrowing answers a value sent for a key computed unless sent.
   */
  public function testTheNarrowingKeepsWhatIsTypedOverTheSuggestion(): void {
    $built = $this->badge();
    $forms = $this->container->get('surface.form_builder');
    $suggested = $forms->refinementOverlay($built, [], ['first' => 'Grace', 'last' => 'Hopper']);
    $this->assertSame('hopper', $suggested['tag']);
    $typed = $forms->refinementOverlay($built, [], [
      'first' => 'Grace',
      'last' => 'Hopper',
      'tag' => 'admiral',
    ]);
    $this->assertSame('admiral', $typed['tag']);
  }

  /**
   * Tests the guard refuses a compute() that asks for a service.
   */
  public function testTheGuardRefusesComputeAskingForService(): void {
    $this->assertSame('ADA', (new Computed(['first'], BadgeCode::class))->compute(['first' => 'Ada']));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The computed key\'s ' . ClockStamp::class . '::compute() asked for the service datetime.time. A computed key is a pure function of the keys it is computed from');
    (new Computed(['first'], ClockStamp::class))->compute(['first' => 'Ada']);
  }

  /**
   * Tests what compute() refuses where it is declared.
   */
  public function testTheBlueprintRefusesWhatIsNoComputedKey(): void {
    $typed = $this->container->get('typed_data_manager');
    $registry = $this->container->get('surface.resource_registry');
    $blueprint = static fn (bool $outputs = FALSE, ?string $owner = NULL): ResourceBlueprint => new ResourceBlueprint(new SurfaceBuilder(), $typed, $outputs, $registry, $owner);
    $refused = function (callable $declare, string $message): void {
      try {
        $declare();
        $this->fail('Refused: ' . $message);
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    };
    $refused(fn () => $blueprint(TRUE)->compute('code', 'string', 'Code', ['first'], BadgeCode::class), 'declares a computed output');
    $refused(fn () => $blueprint(owner: ThresholdStructure::class)->compute('code', 'string', 'Code', ['first'], BadgeCode::class), 'is in a structure');
    $inputs = $blueprint();
    $inputs->add('first', 'string', 'First name');
    $refused(fn () => $inputs->compute('code', 'string', 'Code', ['nickname'], BadgeCode::class), 'is computed from "nickname", which is no key declared before it');
    $refused(fn () => $inputs->compute('code', 'string', 'Code', [], BadgeCode::class), 'names no key it is computed from');
    $refused(fn () => $inputs->compute('code', 'string', 'Code', ['first'], DataDefinition::class), 'which implements no');
    $refused(fn () => $inputs->compute('code', 'string', 'Code', ['first'], BadgeCode::class, 'always'), 'says when: "always"; it is Compute::ON_CREATE, Compute::ON_CHANGE or Compute::UNLESS_SENT.');
    $refused(fn () => $inputs->compute('codes', 'map', 'Codes', ['first'], BadgeCode::class), 'is a map');
    $definition = $inputs->compute('code', 'string', 'Code', ['first'], BadgeCode::class);
    $this->assertEquals(new Computed(['first'], BadgeCode::class), Computed::of($definition));
  }

}

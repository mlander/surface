<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\Omitted;
use Drupal\data_surface\Pipeline\ViolationSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The output half of the contract: declaring it and keeping it.
 *
 * The input half says what a caller may send; this says what the host
 * emits, in the same vocabulary, on the same collection type, and is never
 * refined. What is new is the third state: an output key
 * is present with a value, present with NULL — which is a value — or
 * **absent**, which is what a producer says with the Omitted sentinel
 * and what conformance judges against the required flag.
 *
 * @see \Drupal\data_surface\DataSurfaceInterface::getOutputDefinitions()
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::conformOutput()
 * @see docs/outputs.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OutputSurfaceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_test',
  ];

  /**
   * Builds the fixture: one input, four outputs.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The unsealed builder, so a test can add to it before it seals.
   */
  protected function outputBuilder(): DataSurfaceBuilderInterface {
    $builder = new DataSurfaceBuilder([
      'mode' => DataDefinition::create('string')
        ->setLabel('Mode')
        ->setRequired(TRUE)
        ->addConstraint('Choice', ['choices' => ['plain', 'rich']]),
    ]);
    $meta = MapDataDefinition::create()->setLabel('Meta');
    $meta->setPropertyDefinition('count', DataDefinition::create('integer')
      ->setLabel('Count')
      ->setRequired(TRUE));
    $meta->setPropertyDefinition('tag', DataDefinition::create('string')
      ->setLabel('Tag'));
    $builder
      ->setOutputDefinition('text', DataDefinition::create('string')
        ->setLabel('Text')
        ->setRequired(TRUE))
      ->setOutputDefinition('note', DataDefinition::create('string')
        ->setLabel('Note')
        ->addConstraint('Choice', ['choices' => ['short', 'long']]))
      ->setOutputDefinition('meta', $meta)
      ->setOutputDefinition('tags', ListDataDefinition::create('string')
        ->setLabel('Tags'));
    return $builder;
  }

  /**
   * The module the fixture's mounted output is answered for under.
   */
  protected const PROVIDER = 'data_surface_test';

  /**
   * Seals the fixture, optionally with an alter's mounted output.
   *
   * @param bool $mounted
   *   TRUE to mount a badge output under this module, as an alter's
   *   alterOutputs() would.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function outputSurface(bool $mounted = FALSE): DataSurfaceInterface {
    $builder = $this->outputBuilder();
    if ($mounted) {
      $builder->setThirdPartyOutputDefinition(
        self::PROVIDER,
        'badge',
        DataDefinition::create('string')
          ->setLabel('Badge')
          ->addConstraint('Choice', ['choices' => ['star', 'flame']]),
      );
    }
    return $builder->seal();
  }

  /**
   * Reads the values an output's choice constraint allows.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to read.
   * @param string $name
   *   The output key.
   *
   * @return array
   *   The allowed values.
   */
  protected function choices(DataSurfaceInterface $surface, string $name): array {
    return $surface->getOutputDefinitions()->get($name)->getConstraints()['Choice']['choices'] ?? [];
  }

  /**
   * Tests that the outputs seal into a map of their own.
   */
  public function testOutputsSealIntoTheirOwnMap(): void {
    $surface = $this->outputSurface();

    $outputs = $surface->getOutputDefinitions();
    $this->assertSame(['text', 'note', 'meta', 'tags'], $outputs->names());
    $this->assertTrue($outputs->get('text')->isRequired());
    $this->assertFalse($outputs->get('note')->isRequired());
    // No output refines against anything.
    $this->assertSame([], $outputs->refinements());

    // The two halves do not leak into each other. The inputs know
    // nothing of the outputs, and the declared defaults — which only
    // inputs have — cover the inputs alone.
    $this->assertSame(['mode'], $surface->getDefinitions()->names());
    $this->assertSame(['mode' => NULL], $surface->getDefaultValues());

    // Nothing an output declares is locked, and nothing can be.
    foreach ($outputs->entries() as $entry) {
      $this->assertFalse($entry->locked);
    }
  }

  /**
   * Tests that a surface declaring no outputs answers with an empty map.
   *
   * The compatibility claim, and the reason nothing had to change for
   * the surfaces that came before this: a caller can ask any surface
   * what it emits without asking first whether it knows.
   */
  public function testSurfaceWithoutOutputsAnswersEmpty(): void {
    $surface = $this->casingVariantSurface();

    $this->assertCount(0, $surface->getOutputDefinitions());
    $this->assertSame([], $surface->getOutputDefinitions()->names());
    $this->assertCount(0, $this->pipeline()->conformOutput($surface, []));
  }

  /**
   * Tests that an output declaring a default value is refused.
   */
  public function testOutputsCannotDeclareDefaults(): void {
    $builder = new DataSurfaceBuilder();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "text" output declares a default value.');
    $builder->setOutputDefinition('text', new DataDefinition([
      'type' => 'string',
      'default_value' => 'nothing to say',
    ]));
  }

  /**
   * Tests that a default hidden inside a map output is refused too.
   */
  public function testOutputsCannotHideDefaultsInProperties(): void {
    $meta = MapDataDefinition::create();
    $meta->setPropertyDefinition('tag', new DataDefinition([
      'type' => 'string',
      'default_value' => 'nothing to say',
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "meta.tag" output declares a default value.');
    (new DataSurfaceBuilder())->setOutputDefinition('meta', $meta);
  }

  /**
   * Tests that an output declared in a constructor is checked at seal.
   *
   * The path a builder handed a whole set of outputs takes, which never
   * passes through the setter, so the refusal has to be asked again
   * where the surface is assembled.
   */
  public function testConstructorDeclaredDefaultIsRefusedAtSeal(): void {
    $builder = new DataSurfaceBuilder(
      definitions: ['mode' => DataDefinition::create('string')],
      outputs: ['text' => new DataDefinition(['type' => 'string', 'default_value' => 'x'])],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "text" output declares a default value.');
    $builder->seal();
  }

  /**
   * Tests that an output cannot be locked.
   */
  public function testOutputsCannotBeLocked(): void {
    $builder = new DataSurfaceBuilder();
    $builder->setOutputDefinition('text', DataDefinition::create('string'));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "text" output cannot be locked');
    $builder->lock('text');
  }

  /**
   * Tests that the mounting key belongs to the contributors, not the owner.
   */
  public function testTheThirdPartyOutputKeyIsReserved(): void {
    $builder = new DataSurfaceBuilder();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('is reserved for the outputs other modules mount');
    $builder->setOutputDefinition(DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS, MapDataDefinition::create());
  }

  /**
   * Tests that a contributor mounts an output under its own namespace.
   *
   * The output side of the contribution model. A module that owns
   * neither the host nor its execution adds to what the host emits, and
   * the addition is addressed under the name of whoever answers for it,
   * so it can collide with nothing the owner declared and nothing
   * another contributor mounted.
   */
  public function testThirdPartyMountsOutputUnderItsNamespace(): void {
    $outputs = $this->outputSurface(TRUE)->getOutputDefinitions();

    $this->assertSame(
      ['text', 'note', 'meta', 'tags', DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS],
      $outputs->names(),
    );
    $mounted = $outputs->get(DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS);
    $this->assertInstanceOf(MapDataDefinition::class, $mounted);
    $provider = $mounted->getPropertyDefinitions()[self::PROVIDER] ?? NULL;
    $this->assertInstanceOf(MapDataDefinition::class, $provider);
    $badge = $provider->getPropertyDefinitions()['badge'] ?? NULL;
    $this->assertNotNull($badge);
    $this->assertSame(['star', 'flame'], $badge->getConstraints()['Choice']['choices']);

    // And what is mounted is conformance checked like anything else, at
    // its own path, by the key nobody else can claim.
    $emitted = [
      'text' => 'hi',
      DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS => [
        self::PROVIDER => ['badge' => 'sash'],
      ],
    ];
    $violations = $this->pipeline()->conformOutput($this->outputSurface(TRUE), $emitted);
    $this->assertSame(
      [DataSurfaceBuilderInterface::THIRD_PARTY_OUTPUTS . '.' . self::PROVIDER . '.badge'],
      $this->paths($violations),
    );
  }

  /**
   * Tests that a mounted output is named by a module, not by anybody.
   */
  public function testMountedOutputNeedsProviderName(): void {
    $builder = new DataSurfaceBuilder();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must be named by its module name');
    $builder->setThirdPartyOutputDefinition('', 'badge', DataDefinition::create('string'));
  }

  /**
   * Tests that refining the inputs leaves the outputs as advertised.
   *
   * Outputs are never refined: a refinement narrows what may be sent,
   * and nobody sends an output. An output whose shape depends on an
   * input value is a variant, declared with attachBy() on that input.
   */
  public function testOutputsAreNeverRefined(): void {
    $surface = $this->outputSurface();
    $this->assertSame([], $surface->getOutputDefinitions()->refinements());
    $this->assertSame(['short', 'long'], $this->choices($surface->refine(['mode' => 'plain']), 'note'));
  }

  /**
   * Tests every rule conformance applies, one row at a time.
   *
   * @param array $emitted
   *   What the producer emitted, Omitted included.
   * @param array $expected
   *   The full paths expected to be refused, in order.
   */
  #[DataProvider('conformanceRules')]
  public function testConformance(array $emitted, array $expected): void {
    $violations = $this->pipeline()->conformOutput($this->outputSurface(), $emitted);

    $this->assertSame($expected, $this->paths($violations));
  }

  /**
   * The conformance matrix, as rows rather than as prose.
   *
   * @return array<string, array{0: array, 1: array}>
   *   Emitted values and the paths expected to refuse.
   */
  public static function conformanceRules(): array {
    return [
      // Presence: absent, omitted and NULL, against required and not.
      'a required output that is emitted' => [['text' => 'hi'], []],
      'a required output that is absent' => [[], ['text']],
      'a required output that is omitted' => [['text' => Omitted::value()], ['text']],
      'a required output emitted as NULL' => [['text' => NULL], ['text']],
      'an optional output that is absent' => [['text' => 'hi'], []],
      'an optional output that is omitted' => [['text' => 'hi', 'note' => Omitted::value()], []],
      'an optional output emitted as NULL' => [['text' => 'hi', 'note' => NULL], []],
      // Keys nobody declared, at the top and inside a map.
      'an output nobody declared' => [['text' => 'hi', 'extra' => 1], ['extra']],
      'a property nobody declared' => [
        ['text' => 'hi', 'meta' => ['count' => 1, 'extra' => TRUE]],
        ['meta.extra'],
      ],
      // Types, which are never cast: a producer had the declaration in
      // front of it, so the wrong type is a bug and not a notation.
      'a number where a string was declared' => [['text' => 42], ['text']],
      'a numeric string where a number was declared' => [
        ['text' => 'hi', 'meta' => ['count' => '2']],
        ['meta.count'],
      ],
      'a string where a map was declared' => [['text' => 'hi', 'meta' => 'nope'], ['meta']],
      'a string where a list was declared' => [['text' => 'hi', 'tags' => 'nope'], ['tags']],
      'a list of the declared item type' => [['text' => 'hi', 'tags' => ['a', 'b']], []],
      // Constraints, at the top and at depth.
      'a value outside the declared choices' => [['text' => 'hi', 'note' => 'epic'], ['note']],
      'a map with everything it declares' => [
        ['text' => 'hi', 'meta' => ['count' => 2, 'tag' => 'x']],
        [],
      ],
      'a map missing a property it declares as required' => [
        ['text' => 'hi', 'meta' => ['tag' => 'x']],
        ['meta.count'],
      ],
    ];
  }

  /**
   * Reads a violation set as the paths it refused.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $violations
   *   The violations.
   *
   * @return string[]
   *   The full dotted paths, in the order they were reported.
   */
  protected function paths(ViolationSet $violations): array {
    $paths = [];
    foreach ($violations as $violation) {
      $paths[] = $violation->fullPath();
    }
    return $paths;
  }

}

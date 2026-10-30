<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Pipeline\ViolationSet;
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
 * **absent**, which is what a producer says with the NotEmitted sentinel
 * and what conformance judges against the required flag.
 *
 * @see \Drupal\surface\SurfaceInterface::getOutputDefinitions()
 * @see \Drupal\surface\Pipeline\SurfacePipelineInterface::conformOutput()
 * @see docs/outputs.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OutputsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_test',
  ];

  /**
   * Builds the fixture: one input, four outputs.
   *
   * @return \Drupal\surface\SurfaceBuilderInterface
   *   The unsealed builder, so a test can add to it before it seals.
   */
  protected function outputBuilder(): SurfaceBuilderInterface {
    $builder = new SurfaceBuilder([
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
   * Seals the fixture.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected function outputSurface(): SurfaceInterface {
    return $this->outputBuilder()->seal();
  }

  /**
   * Reads the values an output's choice constraint allows.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface to read.
   * @param string $name
   *   The output key.
   *
   * @return array
   *   The allowed values.
   */
  protected function choices(SurfaceInterface $surface, string $name): array {
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
   * Tests that a data surface declaring no outputs answers with an empty map.
   *
   * The compatibility claim, and the reason nothing had to change for
   * the data surfaces that came before this: a caller can ask any data
   * surface what it emits without asking first whether it knows.
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
    $builder = new SurfaceBuilder();

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
    (new SurfaceBuilder())->setOutputDefinition('meta', $meta);
  }

  /**
   * Tests that an output declared in a constructor is checked at seal.
   *
   * The path a builder handed a whole set of outputs takes, which never
   * passes through the setter, so the refusal has to be asked again
   * where the data surface is assembled.
   */
  public function testConstructorDeclaredDefaultIsRefusedAtSeal(): void {
    $builder = new SurfaceBuilder(
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
    $builder = new SurfaceBuilder();
    $builder->setOutputDefinition('text', DataDefinition::create('string'));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The "text" output cannot be locked');
    $builder->lock('text');
  }

  /**
   * Tests that refining the inputs leaves the outputs as advertised.
   *
   * Outputs are never refined: a refinement narrows what may be sent,
   * and nobody sends an output. An output whose shape depends on an
   * input value is a variant, declared with attachVariants() on that input.
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
   *   What the producer emitted, NotEmitted included.
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
      'a required output that is omitted' => [['text' => NotEmitted::value()], ['text']],
      'a required output emitted as NULL' => [['text' => NULL], ['text']],
      'an optional output that is absent' => [['text' => 'hi'], []],
      'an optional output that is omitted' => [['text' => 'hi', 'note' => NotEmitted::value()], []],
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
   * @param \Drupal\surface\Pipeline\ViolationSet $violations
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

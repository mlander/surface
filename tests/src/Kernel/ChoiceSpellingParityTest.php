<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Refinement\Narrowing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that LabeledChoice's two spellings are one vocabulary.
 *
 * `LabeledChoice` takes a list of values with a map of labels beside it,
 * and it takes the same thing written once as a value-to-label map. Two
 * spellings are only a convenience while every consumer reads them as
 * the same declaration; the moment one of them reads a map's keys as
 * values, or its values as values, a declaration means something
 * different depending on how it was typed — and it would mean it
 * quietly, because the constraint itself would go on validating
 * correctly.
 *
 * So this holds the three places that read a declared value list to
 * exactly that: the option resolver, which answers what a form offers;
 * `Narrowing` and the `ChoiceSet` it reads through, which answer whether
 * a refinement is legal; and `DataSurfaceBuilder::extendChoices()`,
 * which merges a contribution into what the owner declared. Each is
 * asked the same question in both spellings and has to give the same
 * answer.
 *
 * @see \Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ChoiceSpellingParityTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface'];

  /**
   * One vocabulary, written canonically.
   *
   * The descriptions are here so the spellings are compared carrying
   * something neither of them touches: whatever else a constraint said
   * has to survive being read and written back.
   */
  protected const CANONICAL = [
    'choices' => ['star', 'flame'],
    'labels' => ['star' => 'Star', 'flame' => 'Flame'],
    'descriptions' => ['flame' => 'It burns.'],
  ];

  /**
   * The same vocabulary, written as a map.
   */
  protected const MAP = [
    'choices' => ['star' => 'Star', 'flame' => 'Flame'],
    'descriptions' => ['flame' => 'It burns.'],
  ];

  /**
   * Tests that ChoiceSet reads one set out of either spelling.
   *
   * The narrowing check never reads a constraint itself; it reads this,
   * which is why this is where the two spellings have to meet.
   */
  public function testChoiceSetReadsBothSpellingsAsOneSet(): void {
    $canonical = ChoiceSet::of($this->definition(self::CANONICAL));
    $map = ChoiceSet::of($this->definition(self::MAP));
    $this->assertNotNull($canonical);
    $this->assertNotNull($map);

    $this->assertSame(['star', 'flame'], $canonical->values);
    $this->assertSame($canonical->values, $map->values);
    $this->assertSame(['star' => 'Star', 'flame' => 'Flame'], $canonical->labels);
    $this->assertSame($canonical->labels, $map->labels);
    // And what the constraint said beyond its values, kept by both so
    // that writing the set back cannot drop it.
    $this->assertSame(['descriptions' => ['flame' => 'It burns.']], $canonical->options);
    $this->assertSame($canonical->options, $map->options);
  }

  /**
   * Tests that the resolver answers with one option set either way.
   */
  public function testTheResolverReadsBothSpellingsAsOneOptionSet(): void {
    $canonical = $this->options()->resolve($this->definition(self::CANONICAL));
    $map = $this->options()->resolve($this->definition(self::MAP));
    $this->assertNotNull($canonical);
    $this->assertNotNull($map);

    $this->assertSame(['star' => 'Star', 'flame' => 'Flame'], $canonical->options);
    $this->assertSame($canonical->options, $map->options);
    $this->assertSame(['flame' => 'It burns.'], $canonical->descriptions);
    $this->assertSame($canonical->descriptions, $map->descriptions);
    // A list read from the constraint alone cannot go stale, whichever
    // way the constraint was typed.
    $this->assertSame($canonical->getCacheMaxAge(), $map->getCacheMaxAge());

    // And the list that is offered is the list that validates, which is
    // the whole reason the labels were put on the Choice in the first
    // place: the map spelling's keys are its allowed values, not its
    // labels.
    foreach (['canonical' => self::CANONICAL, 'map' => self::MAP] as $spelling => $declared) {
      $surface = $this->surface($declared);
      $this->assertCount(0, $this->pipeline()->validate($surface, ['badge' => 'star']), $spelling);
      $this->assertContains(
        'badge',
        $this->pipeline()->validate($surface, ['badge' => 'Star'])->keys(),
        $spelling,
      );
      $this->assertContains(
        'badge',
        $this->pipeline()->validate($surface, ['badge' => 'sash'])->keys(),
        $spelling,
      );
    }
  }

  /**
   * Tests that the narrowing check gives one verdict for both spellings.
   *
   * Four pairings, because a refinement crosses the spellings in
   * practice: a declaration keeps the spelling it was written in, and
   * anything written back by a `ChoiceSet` is canonical, so a refiner
   * handed a map-spelled definition routinely answers canonically.
   */
  public function testTheNarrowingCheckGivesOneVerdictForBothSpellings(): void {
    $before = ['canonical' => self::CANONICAL, 'map' => self::MAP];
    $narrower = [
      'canonical' => ['choices' => ['star'], 'labels' => ['star' => 'Star']],
      'map' => ['choices' => ['star' => 'Star']],
    ];
    $wider = [
      'canonical' => [
        'choices' => ['star', 'flame', 'sash'],
        'labels' => ['star' => 'Star', 'flame' => 'Flame', 'sash' => 'Sash'],
      ],
      'map' => ['choices' => ['star' => 'Star', 'flame' => 'Flame', 'sash' => 'Sash']],
    ];

    $accepted = [];
    $refused = [];
    foreach ($before as $before_spelling => $declared) {
      foreach ($narrower as $after_spelling => $after) {
        $pairing = $before_spelling . ' to ' . $after_spelling;
        Narrowing::assertNarrows(
          'badge',
          'the test',
          $this->definition($declared),
          $this->definition($after),
        );
        $accepted[] = $pairing;
      }
      foreach ($wider as $after_spelling => $after) {
        $pairing = $before_spelling . ' to ' . $after_spelling;
        try {
          Narrowing::assertNarrows(
            'badge',
            'the test',
            $this->definition($declared),
            $this->definition($after),
          );
          $this->fail(sprintf('Widening %s was accepted.', $pairing));
        }
        catch (\LogicException $e) {
          $refused[$pairing] = $e->getMessage();
        }
      }
    }

    // Dropping a value narrows in all four pairings, adding one widens
    // in all four, and the refusal names the same value every time: the
    // verdict is about the vocabulary, never about how it was typed.
    $this->assertSame(
      ['canonical to canonical', 'canonical to map', 'map to canonical', 'map to map'],
      $accepted,
    );
    $this->assertSame(array_keys($refused), $accepted);
    $this->assertCount(1, array_unique($refused));
    $this->assertStringContainsString(
      'the LabeledChoice constraint gained the values sash',
      (string) reset($refused),
    );
  }

  /**
   * Tests that a contribution merges the same into either spelling.
   */
  public function testExtendChoicesMergesTheSameWhicheverSpellingTheBaseUsed(): void {
    $from_canonical = $this->extended(self::CANONICAL);
    $from_map = $this->extended(self::MAP);

    // Not merely equivalent: identical. Both are written back in the one
    // canonical spelling, with the contributed value and its label in
    // the list and everything the constraint said beside it kept.
    $this->assertSame($from_canonical, $from_map);
    $this->assertSame([
      'descriptions' => ['flame' => 'It burns.'],
      'choices' => ['star', 'flame', 'sash'],
      'labels' => ['star' => 'Star', 'flame' => 'Flame', 'sash' => 'Sash'],
    ], $from_canonical);

    // And the merged list reads back as one list, so a contributor
    // cannot tell which spelling the host it extended was written in.
    $merged = $this->options()->resolve($this->definition($from_canonical));
    $this->assertNotNull($merged);
    $this->assertSame(['star' => 'Star', 'flame' => 'Flame', 'sash' => 'Sash'], $merged->options);
  }

  /**
   * Tests that a contribution is refused the same way for both.
   *
   * One value has one owner, and who owns it is read out of whichever
   * spelling the owner declared.
   */
  public function testExtendChoicesRefusesAnOwnedValueInBothSpellings(): void {
    $messages = [];
    foreach (['canonical' => self::CANONICAL, 'map' => self::MAP] as $spelling => $declared) {
      try {
        $this->builderFor($declared)->extendChoices('badge', ['flame' => 'Flame'], 'other');
        $this->fail(sprintf('A %s declaration let its own value be contributed.', $spelling));
      }
      catch (\LogicException $e) {
        $messages[$spelling] = $e->getMessage();
      }
    }
    $this->assertCount(1, array_unique($messages));
    $this->assertStringContainsString(
      'already contributed by the surface owner',
      (string) reset($messages),
    );
  }

  /**
   * Builds the one-key definition the tests read.
   *
   * @param array $options
   *   The constraint options, in one spelling or the other.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function definition(array $options): DataDefinitionInterface {
    return DataDefinition::create('string')
      ->setLabel('Badge')
      ->addConstraint('LabeledChoice', $options);
  }

  /**
   * Seals the one-key definition into a surface.
   *
   * @param array $options
   *   The constraint options, in one spelling or the other.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function surface(array $options): DataSurfaceInterface {
    return new DataSurface(DefinitionMap::fromArrays(
      definitions: ['badge' => $this->definition($options)],
    ));
  }

  /**
   * Builds an unsealed builder over the one-key definition.
   *
   * @param array $options
   *   The constraint options, in one spelling or the other.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilder
   *   The builder.
   */
  protected function builderFor(array $options): DataSurfaceBuilder {
    return new DataSurfaceBuilder(['badge' => $this->definition($options)]);
  }

  /**
   * Contributes one value and reads the merged constraint back.
   *
   * @param array $options
   *   The constraint options the owner declared, in one spelling or the
   *   other.
   *
   * @return array
   *   The merged constraint's options.
   */
  protected function extended(array $options): array {
    $surface = $this->builderFor($options)
      ->extendChoices('badge', ['sash' => 'Sash'], 'other')
      ->seal();
    $definition = $surface->getDefinition('badge');
    $this->assertNotNull($definition);
    return $definition->getConstraints()['LabeledChoice'];
  }

}

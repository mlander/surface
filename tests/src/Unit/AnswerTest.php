<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\ConstraintKeywords;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Refinement\Answer;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests the applier of a refiner's answer, one rule at a time.
 *
 * Each rule is held to what the definition path gives: the key's schema
 * with the answer applied is the schema DefinitionSchema::of() reads from
 * the key's definition with the same change made to it, compared with
 * DefinitionSchema::same(). Each refusal is held to its sentence.
 *
 * @see \Drupal\surface\Refinement\Answer
 */
#[Group('surface')]
class AnswerTest extends UnitTestCase {

  /**
   * The start of a widening refusal, for the declaring class.
   */
  protected const WIDENED = 'Refining "k" for the declaring class widened what it was given: ';

  /**
   * The end of a widening refusal.
   */
  protected const ONLY_NARROW = '. Refinement may only narrow; widening is a build-time act, and the build is over.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A type's defaults as core gives them for the types the module
    // declares: its row's, and NotNull where the key is required.
    $typed = $this->createMock(TypedDataManagerInterface::class);
    $typed->method('getDefaultConstraints')->willReturnCallback(static function (DataDefinitionInterface $definition): array {
      $constraints = LeafTypes::DEFAULT_CONSTRAINTS[$definition->getDataType()] ?? [];
      if ($definition->isRequired()) {
        $constraints['NotNull'] = [];
      }
      return $constraints;
    });
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $typed);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that an answer that adds nothing leaves the key's own schema.
   */
  public function testNothingLeavesTheKey(): void {
    $key = DefinitionSchema::of(DataDefinition::create('string')->setLabel('Name'));
    $this->assertSame($key, Answer::apply($key, Schema::any(), 'k', 'the declaring class'));
    $this->assertSame($key, Answer::apply($key, Schema::boolean(TRUE), 'k', 'the declaring class'));
    $this->assertTrue(Answer::isNothing(Schema::any()));
    $this->assertFalse(Answer::isNothing(Schema::any()->required()));
    $this->assertFalse(Answer::isNothing(DoesNotApply::here()));
  }

  /**
   * Tests that DoesNotApply, and `false`, mark the key.
   */
  public function testDoesNotApplyMarks(): void {
    $definition = DataDefinition::create('string')->addConstraint('Length', ['max' => 9]);
    $key = DefinitionSchema::of($definition);
    $expected = DefinitionSchema::of(DoesNotApply::marked($definition));
    $this->assertSameSchema($expected, Answer::apply($key, DoesNotApply::here(), 'k', 'the declaring class'));
    $this->assertSameSchema($expected, Answer::apply($key, Schema::boolean(FALSE), 'k', 'the declaring class'));
    $this->assertTrue(Answer::rulesOut(Schema::boolean(FALSE)));
    // Nothing is narrower than a key that does not apply.
    $marked = Answer::apply($key, DoesNotApply::here(), 'k', 'the declaring class');
    $this->assertSame($marked, Answer::apply($marked, Schema::any()->maxLength(2), 'k', 'the declaring class'));
  }

  /**
   * Tests that of a key's bound and an answer's, the stricter stands.
   */
  public function testStricterBoundStands(): void {
    $definition = DataDefinition::create('integer')->addConstraint('Range', ['min' => 1, 'max' => 100]);
    $key = DefinitionSchema::of($definition);
    $this->assertSameSchema(
      DefinitionSchema::of((clone $definition)->addConstraint('Range', ['min' => 1, 'max' => 50])),
      Answer::apply($key, Schema::any()->maximum(50), 'k', 'the declaring class'),
    );
    // A looser bound leaves the key's.
    $this->assertSame($key, Answer::apply($key, Schema::any()->maximum(200)->minimum(0), 'k', 'the declaring class'));
    // A bound where the key has none is added.
    $plain = DataDefinition::create('integer');
    $this->assertSameSchema(
      DefinitionSchema::of((clone $plain)->addConstraint('Range', ['max' => 5])),
      Answer::apply(DefinitionSchema::of($plain), Schema::any()->maximum(5), 'k', 'the declaring class'),
    );
  }

  /**
   * Tests a bound said as its check, with a message of its own.
   */
  public function testBoundAsCheckCarriesItsOptions(): void {
    $definition = DataDefinition::create('string')->addConstraint('Length', ['max' => 40]);
    $answer = Schema::any()->check('drupal:Length', ['max' => 12, 'maxMessage' => 'Twelve at most.']);
    $this->assertSameSchema(
      DefinitionSchema::of((clone $definition)->addConstraint('Length', ['max' => 12, 'maxMessage' => 'Twelve at most.'])),
      Answer::apply(DefinitionSchema::of($definition), $answer, 'k', 'the declaring class'),
    );
  }

  /**
   * Tests a string's length and a list's count read from their keywords.
   */
  public function testLengthAndCountKeywords(): void {
    $string = DataDefinition::create('string');
    $this->assertSameSchema(
      DefinitionSchema::of((clone $string)->addConstraint('Length', ['min' => 2, 'max' => 8])),
      Answer::apply(DefinitionSchema::of($string), Schema::any()->minLength(2)->maxLength(8), 'k', 'the declaring class'),
    );
    $list = static::list();
    $expected = static::list()->addConstraint('Count', ['max' => 3]);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply(DefinitionSchema::of($list), Schema::any()->maxItems(3), 'k', 'the declaring class'));
  }

  /**
   * Tests that a pattern is added where the key holds none, and only there.
   */
  public function testPatternWhereNoneStands(): void {
    $string = DataDefinition::create('string');
    $read = ConstraintKeywords::read(['pattern' => '^[a-z]+$'], ['string']);
    $this->assertSame('Regex', $read[0]->constraint);
    $this->assertSameSchema(
      DefinitionSchema::of((clone $string)->addConstraint('Regex', $read[0]->options)),
      Answer::apply(DefinitionSchema::of($string), Schema::any()->pattern('^[a-z]+$'), 'k', 'the declaring class'),
    );
    // Two patterns cannot be compared: the key's stands.
    $held = DataDefinition::create('string')->addConstraint('Regex', ['pattern' => '/^\d+$/']);
    $key = DefinitionSchema::of($held);
    $this->assertSame($key, Answer::apply($key, Schema::any()->pattern('^[a-z]+$'), 'k', 'the declaring class'));
  }

  /**
   * Tests that an answer may require, and requires as the definition does.
   */
  public function testRequired(): void {
    $definition = DataDefinition::create('string')->addConstraint('NotBlank');
    $expected = (clone $definition)->setRequired(TRUE);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply(DefinitionSchema::of($definition), Schema::any()->required(), 'k', 'the declaring class'));
    // Required already: nothing changes.
    $key = DefinitionSchema::of($expected);
    $this->assertSame($key, Answer::apply($key, Schema::any()->required(), 'k', 'the declaring class'));
  }

  /**
   * Tests that `enum` filters the key's list in its order with its labels.
   */
  public function testEnumFiltersInTheKeysOrder(): void {
    $definition = static::labeled();
    $expected = clone $definition;
    ChoiceSet::of($definition)->withValues(['a', 'c'])->applyTo($expected);
    $key = DefinitionSchema::of($definition);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply($key, Schema::enum(['c', 'a', 'z']), 'k', 'the declaring class'));
    // A key with no list gains a Choice of the values.
    $plain = DataDefinition::create('string');
    $this->assertSameSchema(
      DefinitionSchema::of((clone $plain)->addConstraint('Choice', ['choices' => ['x', 'y']])),
      Answer::apply(DefinitionSchema::of($plain), Schema::enum(['x', 'y']), 'k', 'the declaring class'),
    );
  }

  /**
   * Tests that `options()` is the list in its own order with its labels.
   */
  public function testOptionsInTheirOwnOrder(): void {
    $definition = static::labeled();
    $expected = clone $definition;
    // Its labels are held in the order the key holds its labels in.
    (new ChoiceSet('LabeledChoice', ['c', 'a'], ['a' => 'Ay', 'c' => 'Sea']))->applyTo($expected);
    $answer = Schema::options(['c' => 'Sea', 'a' => 'Ay']);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply(DefinitionSchema::of($definition), $answer, 'k', 'the declaring class'));
  }

  /**
   * Tests that `options()` holding a value the key does not offer is refused.
   */
  public function testOptionsMayNotAddValue(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(self::WIDENED . 'the LabeledChoice constraint gained the values z' . self::ONLY_NARROW);
    Answer::apply(DefinitionSchema::of(static::labeled()), Schema::options(['a' => 'A', 'z' => 'Zed']), 'k', 'the declaring class');
  }

  /**
   * Tests that `Schema::enum([])` and `Schema::options([])` are no value.
   */
  public function testEmptyListsAreTheEmptyList(): void {
    $definition = static::labeled();
    $expected = clone $definition;
    ChoiceSet::of($definition)->withValues([])->applyTo($expected);
    $key = DefinitionSchema::of($definition);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply($key, Schema::enum([]), 'k', 'the declaring class'));
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply($key, Schema::options([]), 'k', 'the declaring class'));
  }

  /**
   * Tests that a title and a description are written as given.
   */
  public function testTitleAndDescription(): void {
    $definition = DataDefinition::create('integer')->setLabel('Capacity');
    $expected = (clone $definition)->setLabel('Seats')->setDescription('Up to 40 for the hall.');
    $answer = Schema::any()->description('Up to 40 for the hall.')->title('Seats');
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply(DefinitionSchema::of($definition), $answer, 'k', 'the declaring class'));
  }

  /**
   * Tests that a named check is added, and the same one leaves the key.
   */
  public function testNamedCheckAdded(): void {
    $definition = DataDefinition::create('string');
    $options = ['targets' => ['node']];
    $expected = (clone $definition)->addConstraint('SurfaceValidReference', $options);
    $refined = Answer::apply(DefinitionSchema::of($definition), Schema::any()->check('drupal:SurfaceValidReference', $options), 'k', 'the declaring class');
    $this->assertSameSchema(DefinitionSchema::of($expected), $refined);
    $this->assertSame($refined, Answer::apply($refined, Schema::any()->check('drupal:SurfaceValidReference', $options), 'k', 'the declaring class'));
  }

  /**
   * Tests that a named check the key holds with other options is refused.
   *
   * The door corpus pins this sentence (doors-references.json).
   */
  public function testNamedCheckWithOtherOptionsRefused(): void {
    $key = DefinitionSchema::of(DataDefinition::create('string')->addConstraint('SurfaceValidReference', ['targets' => ['node']]));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Refining "narrowed" for the declaring class widened what it was given: the options of the SurfaceValidReference constraint were replaced' . self::ONLY_NARROW);
    Answer::apply($key, Schema::any()->check('drupal:SurfaceValidReference', ['targets' => ['user']]), 'narrowed', 'the declaring class');
  }

  /**
   * Tests that an answer naming another type is refused in Narrowing's words.
   */
  public function testTypeRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(self::WIDENED . 'the data type changed from string to integer' . self::ONLY_NARROW);
    Answer::apply(DefinitionSchema::of(DataDefinition::create('string')), Schema::integer(), 'k', 'the declaring class');
  }

  /**
   * Tests the keywords no answer says.
   *
   * @param \PortableSchema\Builder\Schema $answer
   *   The answer.
   * @param string $said
   *   How the refusal names what it said.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('providerUnread')]
  public function testUnreadRefused(Schema $answer, string $said): void {
    $key = DefinitionSchema::of(MapDataDefinition::create()->setPropertyDefinition('x', DataDefinition::create('string')));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(sprintf(Answer::UNREAD, 'k', 'the declaring class', $said));
    Answer::apply($key, $answer, 'k', 'the declaring class');
  }

  /**
   * Provides answers no #[RefinesInput] method may give.
   *
   * @return array<string, array{0: \PortableSchema\Builder\Schema, 1: string}>
   *   The answer and how the refusal names it.
   */
  public static function providerUnread(): array {
    return [
      'the same type' => [Schema::object(), '`type`'],
      'a default' => [Schema::any()->default('a'), '`default`'],
      'properties' => [Schema::any()->properties(['x' => Schema::any()->maxLength(2)]), '`properties`'],
      'items' => [Schema::any()->items(Schema::any()->maxLength(2)), '`items`'],
      'a oneOf that is no list' => [Schema::oneOf([Schema::string()]), 'a `oneOf` that is not a list of options'],
      'a check of another provider' => [Schema::any()->check('portable:x'), 'the check `portable:x`'],
    ];
  }

  /**
   * Tests that the engine's own links reach inside a map, and only they.
   */
  public function testInsideMap(): void {
    $map = static::map();
    $key = DefinitionSchema::of($map);
    $expected = static::map();
    $expected->setPropertyDefinition('code', DataDefinition::create('string')->addConstraint('Length', ['max' => 4])->setRequired(TRUE));
    $answer = Schema::any()->properties(['code' => Schema::any()->maxLength(4)->required()]);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply($key, $answer, 'k', 'the declaring class', TRUE));
    $ruled = static::map();
    $ruled->setPropertyDefinition('note', DoesNotApply::marked($ruled->getPropertyDefinition('note')));
    $this->assertSameSchema(DefinitionSchema::of($ruled), Answer::apply($key, Schema::any()->properties(['note' => FALSE]), 'k', 'the declaring class', TRUE));
  }

  /**
   * Tests a refusal inside a map, in Narrowing's words.
   */
  public function testInsideMapRefused(): void {
    $map = static::map();
    $map->getPropertyDefinition('code')->addConstraint('Uuid', ['strict' => TRUE]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(self::WIDENED . 'inside the code property, the options of the Uuid constraint were replaced' . self::ONLY_NARROW);
    Answer::apply(DefinitionSchema::of($map), Schema::any()->properties(['code' => Schema::any()->check('drupal:Uuid', ['strict' => FALSE])]), 'k', 'the declaring class', TRUE);
  }

  /**
   * Tests that a property the map does not hold is refused.
   */
  public function testInsideMapNoSuchProperty(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(self::WIDENED . 'the map gained the property other' . self::ONLY_NARROW);
    Answer::apply(DefinitionSchema::of(static::map()), Schema::any()->properties(['other' => Schema::any()->maxLength(4)]), 'k', 'the declaring class', TRUE);
  }

  /**
   * Tests that the engine's own links reach a list's item.
   */
  public function testInsideList(): void {
    $list = static::list();
    $expected = static::list();
    $expected->getItemDefinition()->addConstraint('Length', ['max' => 3]);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::apply(DefinitionSchema::of($list), Schema::any()->items(Schema::any()->maxLength(3)), 'k', 'the declaring class', TRUE));
  }

  /**
   * Tests the one answer a parent gives about a key holding a child.
   */
  public function testKeyHoldingChild(): void {
    $key = DefinitionSchema::of(static::map());
    $this->assertTrue(NodeFacts::doesNotApply(Answer::forKeyHoldingChild($key, DoesNotApply::here(), 'address', 'the declaring class')));
    $this->assertNull(Answer::forKeyHoldingChild($key, Schema::any(), 'address', 'the declaring class'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(sprintf(Answer::HOLDS_A_CHILD, 'address', 'the required flag was turned off'));
    Answer::forKeyHoldingChild($key, Schema::any()->required(), 'address', 'the declaring class');
  }

  /**
   * Tests that a parent's answer that reached a collection's items is refused.
   */
  public function testItemsUntouched(): void {
    $list = static::list();
    $key = DefinitionSchema::of($list);
    Answer::assertItemsUntouched('racks', $key, Answer::apply($key, Schema::any()->maxItems(2), 'racks', 'the declaring class'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(sprintf(Answer::ITEMS, 'racks', 'the Length constraint was removed'));
    Answer::assertItemsUntouched('racks', $key, Answer::apply($key, Schema::any()->items(Schema::any()->maxLength(3)), 'racks', 'the declaring class', TRUE));
  }

  /**
   * Tests that a collection that is no longer a list is said so.
   */
  public function testItemsNoLongerList(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(sprintf(Answer::ITEMS, 'racks', 'the list is no longer a list'));
    Answer::assertItemsUntouched('racks', DefinitionSchema::of(static::list()), DefinitionSchema::of(DataDefinition::create('string')));
  }

  /**
   * Tests what narrowed() says, in Narrowing's words.
   */
  public function testNarrowedWords(): void {
    $definition = DataDefinition::create('integer')->addConstraint('Range', ['max' => 100]);
    $key = DefinitionSchema::of($definition);
    $this->assertSame('the max of the Range constraint moved from 50 to 100', Answer::narrowed($key, Answer::apply($key, Schema::any()->maximum(50), 'k', 'the declaring class')));
    $this->assertNull(Answer::narrowed($key, $key));
  }

  /**
   * Tests that a list written on a schema is the list applyTo() writes.
   */
  public function testWithChoices(): void {
    $definition = static::labeled();
    $set = ChoiceSet::of($definition)->withValues(['b']);
    $expected = $set->applyTo(clone $definition);
    $this->assertSameSchema(DefinitionSchema::of($expected), Answer::withChoices(DefinitionSchema::of($definition), $set));
  }

  /**
   * Builds a key with a labeled list.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   A string with a LabeledChoice of a, b and c.
   */
  protected static function labeled(): DataDefinition {
    return DataDefinition::create('string')->addConstraint('LabeledChoice', [
      'choices' => ['a', 'b', 'c'],
      'labels' => ['a' => 'A', 'b' => 'B', 'c' => 'C'],
    ]);
  }

  /**
   * Builds a list of strings.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The list.
   */
  protected static function list(): ListDataDefinition {
    return new ListDataDefinition(['type' => 'list'], DataDefinition::create('string'));
  }

  /**
   * Builds a map with two properties.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  protected static function map(): MapDataDefinition {
    return MapDataDefinition::create()
      ->setPropertyDefinition('code', DataDefinition::create('string'))
      ->setPropertyDefinition('note', DataDefinition::create('string'));
  }

  /**
   * Asserts two schemas say the same, naming the first part that differs.
   *
   * @param \PortableSchema\Builder\Schema $expected
   *   The schema the definition path gives.
   * @param \PortableSchema\Builder\Schema $actual
   *   The schema the applier gives.
   */
  protected function assertSameSchema(Schema $expected, Schema $actual): void {
    $this->assertTrue(DefinitionSchema::same($expected, $actual), implode("\n", static::differences($expected, $actual)));
  }

  /**
   * Lists where two schemas differ, for a failure message.
   *
   * @param mixed $expected
   *   A schema, or a value inside one.
   * @param mixed $actual
   *   The other.
   * @param string $at
   *   Where they are.
   *
   * @return string[]
   *   One line per place that differs.
   */
  protected static function differences(mixed $expected, mixed $actual, string $at = ''): array {
    if (DefinitionSchema::same($expected, $actual)) {
      return [];
    }
    if ($expected instanceof Schema && $actual instanceof Schema && !$expected->isBoolean() && !$actual->isBoolean()) {
      $lines = $expected->isRequired() === $actual->isRequired() ? [] : [$at . ': required() differs'];
      return [...$lines, ...static::differences($expected->all(), $actual->all(), $at)];
    }
    if (is_array($expected) && is_array($actual)) {
      $lines = array_keys($expected) === array_keys($actual) ? [] : [$at . ': keys ' . json_encode(array_keys($expected)) . ' against ' . json_encode(array_keys($actual))];
      foreach (array_intersect_key($expected, $actual) as $key => $value) {
        array_push($lines, ...static::differences($value, $actual[$key], $at . '/' . $key));
      }
      return $lines;
    }
    return [$at . ': ' . var_export($expected, TRUE) . ' against ' . var_export($actual, TRUE)];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Refinement\Answer;
use Drupal\surface\Refinement\AnswerFromDefinition;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Surface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests the adapter that reads an old-shape refiner's change as an answer.
 *
 * For each kind of change a #[RefinesInput] method makes to the
 * definition it is handed, the answer the adapter reads is held to its
 * shape, and the key's schema with that answer applied is held to the
 * schema DefinitionSchema::of() reads from the returned definition.
 *
 * @see \Drupal\surface\Refinement\AnswerFromDefinition
 */
#[Group('surface')]
class AnswerFromDefinitionTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(AnswerFromDefinition::LOG);
    parent::tearDown();
  }

  /**
   * Tests the answer each kind of change is read as, and what it applies to.
   *
   * @param callable $handed
   *   Builds the definition the refiner is handed.
   * @param callable $refine
   *   The refiner: takes a copy and returns it tightened.
   * @param array|string $shape
   *   The answer's keywords, or `DoesNotApply`.
   */
  #[DataProvider('providerChanges')]
  public function testChangeReadAsAnswer(callable $handed, callable $refine, array|string $shape): void {
    $definition = $handed();
    $key = DefinitionSchema::of($definition);
    $returned = $refine(Surface::deepClone($definition));
    $answer = AnswerFromDefinition::between($key, $returned, $definition);
    if ($shape === 'DoesNotApply') {
      $this->assertInstanceOf(DoesNotApply::class, $answer);
    }
    else {
      $this->assertInstanceOf(Schema::class, $answer);
      $this->assertSame($shape, array_keys($answer->all()));
    }
    $applied = Answer::apply($key, $answer, 'k', 'the declaring class', TRUE);
    $this->assertTrue(DefinitionSchema::same(DefinitionSchema::of($returned), $applied));
  }

  /**
   * Provides changes an old-shape refiner makes, and the answer read.
   *
   * @return array<string, array{0: callable, 1: callable, 2: array|string}>
   *   The handed definition, the refiner, and the answer's keywords.
   */
  public static function providerChanges(): array {
    $labeled = static fn (): DataDefinition => DataDefinition::create('string')->addConstraint('LabeledChoice', ['choices' => ['a', 'b', 'c'], 'labels' => ['a' => 'A', 'b' => 'B', 'c' => 'C']]);
    $narrowed = static function (array $values, array $labels = []): callable {
      return static function (DataDefinition $definition) use ($values, $labels): DataDefinition {
        $set = ChoiceSet::of($definition);
        (new ChoiceSet($set->constraint, $values, $labels === [] ? $set->labels : $labels, $set->options))->applyTo($definition);
        return $definition;
      };
    };
    $map = static fn (): MapDataDefinition => MapDataDefinition::create()->setPropertyDefinition('code', DataDefinition::create('string'));
    return [
      'nothing' => [
        static fn (): DataDefinition => DataDefinition::create('string'),
        static fn (DataDefinition $definition): DataDefinition => $definition,
        [],
      ],
      'does not apply' => [
        static fn (): DataDefinition => DataDefinition::create('string'),
        static fn (DataDefinition $definition): DataDefinitionInterface => DoesNotApply::marked($definition),
        'DoesNotApply',
      ],
      'a label and a description' => [
        static fn (): DataDefinition => DataDefinition::create('string')->setLabel('Code'),
        static fn (DataDefinition $definition): DataDefinition => $definition->setLabel('Room code')->setDescription('Two letters.'),
        ['title', 'description'],
      ],
      'required' => [
        static fn (): DataDefinition => DataDefinition::create('string'),
        static fn (DataDefinition $definition): DataDefinition => $definition->setRequired(TRUE),
        [],
      ],
      'a bound tightened' => [
        static fn (): DataDefinition => DataDefinition::create('integer')->addConstraint('Range', ['min' => 1, 'max' => 100]),
        static fn (DataDefinition $definition): DataDefinition => $definition->addConstraint('Range', ['min' => 1, 'max' => 40]),
        ['portable-check'],
      ],
      'a check added' => [
        static fn (): DataDefinition => DataDefinition::create('string'),
        static fn (DataDefinition $definition): DataDefinition => $definition->addConstraint('SurfaceValidReference', ['targets' => ['node']]),
        ['portable-check'],
      ],
      'a list in the key\'s order' => [$labeled, $narrowed(['a', 'c']), ['enum']],
      'a list reordered' => [$labeled, $narrowed(['c', 'a']), ['oneOf']],
      'a list relabeled' => [$labeled, $narrowed(['a'], ['a' => 'Ay']), ['oneOf']],
      'inside a map' => [
        $map,
        static function (MapDataDefinition $definition): MapDataDefinition {
          $definition->getPropertyDefinition('code')->addConstraint('Length', ['max' => 4]);
          return $definition;
        },
        ['properties'],
      ],
      'inside a list' => [
        static fn (): ListDataDefinition => new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')),
        static function (ListDataDefinition $definition): ListDataDefinition {
          $definition->getItemDefinition()->addConstraint('Length', ['max' => 4]);
          return $definition;
        },
        ['items'],
      ],
    ];
  }

  /**
   * Tests the log: what needed options(), a title, a description or a bound.
   */
  public function testLog(): void {
    $file = tempnam(sys_get_temp_dir(), 'answers');
    putenv(AnswerFromDefinition::LOG . '=' . $file);
    $definition = DataDefinition::create('integer')->addConstraint('Range', ['max' => 100]);
    $returned = Surface::deepClone($definition)->addConstraint('Range', ['max' => 10])->setDescription('Ten at most.');
    AnswerFromDefinition::between(DefinitionSchema::of($definition), $returned, $definition, 'Example::capacity()', 'capacity');
    // Nothing logged for an answer that needed none of those.
    AnswerFromDefinition::between(DefinitionSchema::of($definition), Surface::deepClone($definition)->setRequired(TRUE), $definition, 'Example::required()', 'capacity');
    $this->assertSame("Example::capacity()\tcapacity\tdescription,bound\n", file_get_contents($file));
    unlink($file);
  }

}

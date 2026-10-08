<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\data_surface\Refinement\Narrowing;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which changes of data type the narrowing check accepts.
 *
 * Two transitions narrow: away from `any`, which advertises nothing, and
 * from a data type to one of its derivatives (`entity` to `entity:node`,
 * `entity:node` to `entity:node:article`), whose values are the base
 * type's values held to more. That second rule is the Tool API's,
 * adopted; the Tool API's acceptance of a list item becoming `any` is
 * not, because that accepts every value the item refused. Each row is
 * one transition.
 *
 * @see \Drupal\data_surface\Refinement\Narrowing
 * @see \Drupal\tool\TypedInputsTrait::assertRefinementNarrows()
 */
#[Group('data_surface')]
class NarrowingTypesTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $typed_data = $this->createMock(TypedDataManagerInterface::class);
    $typed_data->method('getDefaultConstraints')->willReturn([]);
    $container = new ContainerBuilder();
    $container->set('typed_data_manager', $typed_data);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that a narrower data type is accepted.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $before
   *   What the key advertised.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $after
   *   What a refiner returned.
   */
  #[DataProvider('accepted')]
  public function testAccepted(DataDefinitionInterface $before, DataDefinitionInterface $after): void {
    Narrowing::assertNarrows('key', 'the test', $before, $after);
    $this->addToAssertionCount(1);
  }

  /**
   * The transitions that narrow.
   *
   * @return array<string, array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: \Drupal\Core\TypedData\DataDefinitionInterface}>
   *   Before and after.
   */
  public static function accepted(): array {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('width', DataDefinition::create('integer'));
    return [
      'any to a primitive' => [DataDefinition::create('any'), DataDefinition::create('string')],
      'any to a map' => [DataDefinition::create('any'), $map],
      'a type to its derivative' => [DataDefinition::create('entity'), DataDefinition::create('entity:node')],
      'a derivative to its own derivative' => [
        DataDefinition::create('entity:node'),
        DataDefinition::create('entity:node:article'),
      ],
      'a list item to its derivative' => [
        new ListDataDefinition([], DataDefinition::create('entity')),
        new ListDataDefinition([], DataDefinition::create('entity:node')),
      ],
      'a derivative that adds a constraint' => [
        DataDefinition::create('entity'),
        DataDefinition::create('entity:node')->addConstraint('Bundle', ['article']),
      ],
    ];
  }

  /**
   * Tests that a wider or unrelated data type is refused.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $before
   *   What the key advertised.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $after
   *   What a refiner returned.
   * @param string $message
   *   What the refusal says.
   */
  #[DataProvider('refused')]
  public function testRefused(DataDefinitionInterface $before, DataDefinitionInterface $after, string $message): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage($message);
    Narrowing::assertNarrows('key', 'the test', $before, $after);
  }

  /**
   * The transitions that do not narrow.
   *
   * @return array<string, array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: \Drupal\Core\TypedData\DataDefinitionInterface, 2: string}>
   *   Before, after, and the refusal.
   */
  public static function refused(): array {
    return [
      'an unrelated primitive' => [
        DataDefinition::create('string'),
        DataDefinition::create('integer'),
        'the data type changed from string to integer',
      ],
      'a derivative back to its base' => [
        DataDefinition::create('entity:node'),
        DataDefinition::create('entity'),
        'the data type changed from entity:node to entity',
      ],
      'a sibling derivative' => [
        DataDefinition::create('entity:node'),
        DataDefinition::create('entity:user'),
        'the data type changed from entity:node to entity:user',
      ],
      'a name that only starts the same' => [
        DataDefinition::create('string'),
        DataDefinition::create('string_long'),
        'the data type changed from string to string_long',
      ],
      'anything to any' => [
        DataDefinition::create('string'),
        DataDefinition::create('any'),
        'the data type changed from string to any',
      ],
      'a list item to any' => [
        new ListDataDefinition([], DataDefinition::create('string')),
        new ListDataDefinition([], DataDefinition::create('any')),
        'inside the list items, the data type changed from string to any',
      ],
      'a derivative that drops a constraint' => [
        DataDefinition::create('entity')->addConstraint('NotNull', []),
        DataDefinition::create('entity:node'),
        'the NotNull constraint was removed',
      ],
    ];
  }

}

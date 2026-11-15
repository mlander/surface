<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\LeafTypes;
use Drupal\surface\Contract\NodeDefinition;
use Drupal\surface\Contract\NodeFacts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the table of each type's default constraints and the merged list.
 *
 * Each row of LeafTypes::DEFAULT_CONSTRAINTS is what the typed data
 * manager gives a definition of its type, so a module that changes a
 * type's defaults fails here with the type's name. A node's merged list
 * (NodeFacts::constraints()) is getConstraints() of the definition it was
 * read from, `===`; the read writes the `constraints` member only where
 * NodeFacts::derived() does not give that list, and the inverse derives a
 * definition that gives it again.
 *
 * @see docs/decisions.md#the-merged-constraint-list-is-derived
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefaultConstraintsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * Tests every row of the table against the typed data manager.
   */
  public function testEveryRowIsTheManagers(): void {
    $manager = $this->container->get('typed_data_manager');
    foreach (LeafTypes::DEFAULT_CONSTRAINTS as $type => $row) {
      $this->assertTrue($manager->hasDefinition($type), "The typed data manager defines $type.");
      $this->assertSame($manager->getDefaultConstraints(new DataDefinition(['type' => $type])), $row, "The default constraints of $type are the table's row.");
    }
    $this->assertSame([], array_diff_key(LeafTypes::ROWS, LeafTypes::DEFAULT_CONSTRAINTS), 'Every leaf type has a row.');
    foreach (['map', 'list', LeafTypes::ANY] as $type) {
      $this->assertArrayHasKey($type, LeafTypes::DEFAULT_CONSTRAINTS, "$type has a row.");
    }
  }

  /**
   * Tests the merged list of each case, and where the member is written.
   */
  public function testTheMergedListIsGetConstraints(): void {
    foreach ($this->cases() as $case => [$definition, $member]) {
      $node = DefinitionSchema::of($definition);
      $this->assertSame($definition->getConstraints(), NodeFacts::constraints($node), "$case: the merged list is getConstraints().");
      $this->assertSame($member, array_key_exists('constraints', DefinitionSchema::model($node)), "$case: whether the node holds the constraints member.");
      if ($member) {
        $this->assertNotSame($definition->getConstraints(), NodeFacts::derived(DefinitionSchema::model($node)), "$case: the member is written only where the derivation does not give the list.");
      }
      $this->assertSame($definition->getConstraints(), NodeDefinition::of($node)->getConstraints(), "$case: the derived definition gives the list again.");
    }
  }

  /**
   * Tests the nodes below a list and a map hold no member either.
   */
  public function testNodesBelowHoldNoMember(): void {
    $list = ListDataDefinition::create('string')->setLabel('Tags');
    $list->getItemDefinition()->addConstraint('Length', ['max' => 9]);
    $map = MapDataDefinition::create()->setLabel('Pair')
      ->setPropertyDefinition('a', DataDefinition::create('email')->setRequired(TRUE))
      ->setPropertyDefinition('b', DataDefinition::create('integer'));
    $items = DefinitionSchema::of($list)->get('items');
    $properties = DefinitionSchema::of($map)->get('properties');
    $this->assertArrayNotHasKey('constraints', DefinitionSchema::model($items), 'A list\'s item.');
    $this->assertSame($list->getItemDefinition()->getConstraints(), NodeFacts::constraints($items));
    foreach (['a', 'b'] as $name) {
      $this->assertArrayNotHasKey('constraints', DefinitionSchema::model($properties[$name]), "Property $name.");
      $this->assertSame($map->getPropertyDefinition($name)->getConstraints(), NodeFacts::constraints($properties[$name]));
    }
  }

  /**
   * Builds the cases: a definition, and whether its node holds the member.
   *
   * @return array<string, array{0: \Drupal\Core\TypedData\DataDefinitionInterface, 1: bool}>
   *   The cases, by name.
   */
  protected function cases(): array {
    $string = static fn (): DataDefinition => DataDefinition::create('string')->setLabel('Name');
    $cases = [];
    $cases['an optional string'] = [$string(), FALSE];
    $cases['a required string'] = [$string()->setRequired(TRUE), FALSE];
    $cases['an optional key with a declared NotNull'] = [$string()->addConstraint('NotNull', []), FALSE];
    $both = $string()->addConstraint('Length', ['max' => 3])->addConstraint('NotNull', [])->setRequired(TRUE);
    $cases['a required key with a declared NotNull'] = [$both, FALSE];
    $cases['a required key with NotBlank'] = [$string()->addConstraint('NotBlank', [])->setRequired(TRUE), FALSE];
    $cases['an optional key with NotBlank'] = [$string()->addConstraint('NotBlank', []), FALSE];
    $primitive = DataDefinition::create('integer')->setLabel('Count')
      ->addConstraint('Range', ['min' => 1])
      ->addConstraint('PrimitiveType', ['message' => 'A number.']);
    $cases['a declared PrimitiveType with options'] = [$primitive, FALSE];
    $mail = DataDefinition::create('email')->setLabel('Mail')->addConstraint('Length', ['max' => 60]);
    $cases['email'] = [$mail, FALSE];
    $own = DataDefinition::create('email')->setLabel('Mail')
      ->addConstraint('Email', ['message' => 'Not a mail.'])
      ->setRequired(TRUE);
    $cases['email with its Email declared'] = [$own, FALSE];
    $cases['uri'] = [DataDefinition::create('uri')->setLabel('Link'), FALSE];
    $cases['timestamp'] = [DataDefinition::create('timestamp')->setLabel('When'), FALSE];
    $tags = ListDataDefinition::create('string')->setLabel('Tags')->addConstraint('Count', ['max' => 3]);
    $cases['a list'] = [$tags, FALSE];
    $cases['a required list'] = [ListDataDefinition::create('integer')->setLabel('Counts')->setRequired(TRUE), FALSE];
    $pair = MapDataDefinition::create()->setLabel('Pair')->setPropertyDefinition('a', DataDefinition::create('string'));
    $cases['a map'] = [$pair, FALSE];
    $cases['an empty map'] = [MapDataDefinition::create()->setLabel('Empty'), FALSE];
    $cases['any'] = [DataDefinition::create('any')->setLabel('Anything'), FALSE];
    $cases['a type with no row'] = [DataDefinition::create('decimal')->setLabel('Amount'), TRUE];
    $language = DataDefinition::create('language_reference')->setLabel('Language');
    $cases['a type with no row and no constraint of its own'] = [$language, TRUE];
    $cases['a class key'] = [$string()->setClass(StringData::class), TRUE];
    return $cases;
  }

  /**
   * Tests a type with no row keeps the member even where it is empty.
   */
  public function testAnEmptyListIsKeptWhereItIsNotDerived(): void {
    $definition = DataDefinition::create('language_reference')->setLabel('Language');
    $this->assertSame([], $definition->getConstraints());
    $node = DefinitionSchema::of($definition);
    $this->assertNull(NodeFacts::derived(DefinitionSchema::model($node)), 'No row: no derivation.');
    $this->assertSame([], DefinitionSchema::model($node)['constraints'] ?? NULL, 'The member holds the empty list.');
    $this->assertInstanceOf(DataDefinitionInterface::class, NodeDefinition::of($node));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;

/**
 * Three keys, each narrowing the next, with deliberate overlaps.
 *
 * The fixture the discard rule is held to, and it exists because the
 * other chain fixtures in this module cannot express the cases the rule
 * turns on. Casing and variant is two links, and its lists are disjoint,
 * so every change orphans everything: it can show that an orphaned value
 * goes away and nothing else.
 *
 * The lists here overlap on purpose. Tier one's `a` and `b` both offer
 * `two`, so a value can survive a change of its parent — which is the
 * only way to prove that the fall-back is the stored value rather than
 * an empty select. `c` offers neither `one` nor `two`, so a stored value
 * can be orphaned alongside an in-progress one, which is the branch that
 * hands over to the stale placeholder. And there is a third link, so a
 * discard at the second can be seen to reach the third inside one
 * rebuild rather than one rebuild later.
 *
 * @see \Drupal\Tests\data_surface\Kernel\RefinementDiscardTest
 */
#[Block(
  id: 'data_surface_chain_test_block',
  admin_label: new TranslatableMarkup('Data surface chain test block'),
)]
final class DataSurfaceChainTestBlock extends DataSurfaceBlockBase {

  /**
   * What each tier one value offers the second tier.
   */
  public const SECOND = [
    'a' => ['one', 'two'],
    'b' => ['two', 'three'],
    'c' => ['three', 'four'],
  ];

  /**
   * What each tier two value offers the third tier.
   */
  public const THIRD = [
    'one' => ['x', 'y'],
    'two' => ['y', 'z'],
    'three' => ['z', 'w'],
    'four' => ['w', 'v'],
  ];

  /**
   * {@inheritdoc}
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $builder->setDefinition('tier_one', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tier one'))
      ->setRequired(FALSE)
      ->addConstraint('Choice', ['choices' => array_keys(self::SECOND)]));
    $builder->setDefault('tier_one', 'a');

    $builder->setDefinition('tier_two', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tier two'))
      ->setRequired(FALSE));
    $builder->addRefinement('tier_two', ['tier_one']);

    $builder->setDefinition('tier_three', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tier three'))
      ->setRequired(FALSE));
    $builder->addRefinement('tier_three', ['tier_two']);

    // A key that refines against nothing, so that a rebuild can be seen
    // to leave the rest of the form alone.
    $builder->setDefinition('note', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Note'))
      ->setRequired(FALSE));
    $builder->setDefault('note', 'stored note');
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $choices = match ($name) {
      'tier_two' => self::SECOND[$values['tier_one']] ?? NULL,
      'tier_three' => self::THIRD[$values['tier_two']] ?? NULL,
      default => NULL,
    };
    return $choices === NULL
      ? $definition
      : $definition->addConstraint('Choice', ['choices' => $choices]);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#markup' => 'chain'];
  }

}

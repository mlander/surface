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
 * The fixture the discard rule is held to. The lists overlap on purpose:
 * `a` and `b` both offer `two`, so a value can survive a change of its
 * parent, while `c` offers neither `one` nor `two`, so a stored value can
 * be orphaned alongside an in-progress one. The third link is there so a
 * discard at the second can be seen to reach it inside one rebuild.
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
      ->addConstraint('Choice', ['choices' => array_keys(self::SECOND)]));
    $builder->setDefault('tier_one', 'a');

    $builder->setDefinition('tier_two', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tier two')));
    $builder->addRefinement('tier_two', ['tier_one']);

    $builder->setDefinition('tier_three', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tier three')));
    $builder->addRefinement('tier_three', ['tier_two']);

    // A key that refines against nothing, so that a rebuild can be seen
    // to leave the rest of the form alone.
    $builder->setDefinition('note', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Note')));
    $builder->setDefault('note', 'stored note');
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    return match ($name) {
      'tier_two' => $this->refineTier($definition, self::SECOND[$values['tier_one']] ?? NULL),
      'tier_three' => $this->refineTier($definition, self::THIRD[$values['tier_two']] ?? NULL),
      default => $definition,
    };
  }

  /**
   * Narrows one tier to what the tier above it offers.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The advertised definition.
   * @param array|null $choices
   *   The values on offer, or NULL when the tier above offers none.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The narrowed definition.
   */
  protected function refineTier(DataDefinitionInterface $definition, ?array $choices): DataDefinitionInterface {
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

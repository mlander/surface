<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

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
#[Surface('test.chain_block')]
final class ChainBlockSurface implements SurfaceInterface {

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
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('tier_one', 'string', new TranslatableMarkup('Tier one'), default: 'a')
      ->addConstraint('Choice', ['choices' => array_keys(self::SECOND)]);
    $inputs->add('tier_two', 'string', new TranslatableMarkup('Tier two'));
    $inputs->add('tier_three', 'string', new TranslatableMarkup('Tier three'));
    // A key that refines against nothing, so that a rebuild can be seen
    // to leave the rest of the form alone.
    $inputs->add('note', 'string', new TranslatableMarkup('Note'), default: 'stored note');
  }

  /**
   * The second tier offers what the first tier's value names.
   */
  #[RefinesInput('tier_two')]
  public function secondOfFirst(DataDefinitionInterface $tier_two, string $tier_one): DataDefinitionInterface {
    return static::tier($tier_two, self::SECOND[$tier_one] ?? NULL);
  }

  /**
   * The third tier offers what the second tier's value names.
   */
  #[RefinesInput('tier_three')]
  public function thirdOfSecond(DataDefinitionInterface $tier_three, string $tier_two): DataDefinitionInterface {
    return static::tier($tier_three, self::THIRD[$tier_two] ?? NULL);
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
  protected static function tier(DataDefinitionInterface $definition, ?array $choices): DataDefinitionInterface {
    return $choices === NULL
      ? $definition
      : $definition->addConstraint('Choice', ['choices' => $choices]);
  }

}

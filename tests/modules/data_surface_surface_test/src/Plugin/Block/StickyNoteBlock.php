<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A block that is its own surface, and names its id with #[Surface].
 *
 * #[UsesSurface] with no argument: the shape and the refiner are static
 * methods of the block. The #[Surface] beside it gives the id, which a
 * plugin that is its own surface otherwise takes from its plugin
 * definition (block:data_surface_surface_test_sticky_note).
 */
#[Block(
  id: 'data_surface_surface_test_sticky_note',
  admin_label: new TranslatableMarkup('Sticky note (surface test)'),
)]
#[UsesSurface]
#[Surface('surface_test.sticky_note')]
final class StickyNoteBlock extends DataSurfaceBlockBase implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('color', 'string', t('Color'), default: 'yellow')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['yellow', 'pink']]);
    $inputs->add('note', 'string', t('Note'), default: 'Sticky')
      ->addConstraint('Length', ['max' => 40]);
  }

  /**
   * A pink note is a short one.
   */
  #[RefinesInput('note')]
  public static function shortOnPink(DataDefinitionInterface $note, string $color): DataDefinitionInterface {
    return $color === 'pink' ? $note->addConstraint('Length', ['max' => 10]) : $note;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}

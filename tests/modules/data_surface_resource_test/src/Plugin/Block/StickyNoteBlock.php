<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A block that is its own structure, and names its id with #[Structure].
 *
 * #[UsesStructure] with no argument: defineInputs() and the refiner are
 * static methods of the block. The #[Structure] beside it gives the id, which a
 * plugin that is its own structure otherwise takes from its plugin
 * definition (block:data_surface_resource_test_sticky_note).
 */
#[Block(
  id: 'data_surface_resource_test_sticky_note',
  admin_label: new TranslatableMarkup('Sticky note (resource test)'),
)]
#[UsesStructure]
#[Structure('resource_test.sticky_note')]
final class StickyNoteBlock extends DataSurfaceBlockBase implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
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

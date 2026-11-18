<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A block that is its own structure, and names its id with #[Structure].
 *
 * #[UsesStructure] with no argument: defineInputs() and the refiner are
 * static methods of the block. The #[Structure] beside it gives the id, which a
 * plugin that is its own structure otherwise takes from its plugin
 * definition (block:surface_resource_test_sticky_note).
 */
#[Block(
  id: 'surface_resource_test_sticky_note',
  admin_label: new TranslatableMarkup('Sticky note (resource test)'),
)]
#[UsesStructure]
#[Structure('resource_test.sticky_note')]
final class StickyNoteBlock extends TypedBlockBase implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('color', Schema::enum(['yellow', 'pink'])
      ->title(t('Color'))
      ->default('yellow')
      ->required());
    $inputs->add('note', Schema::string()
      ->title(t('Note'))
      ->default('Sticky')
      ->maxLength(40));
  }

  /**
   * A pink note is a short one.
   */
  #[RefinesInput('note')]
  public static function shortOnPink(string $color): Schema {
    return $color === 'pink' ? Schema::any()->maxLength(10) : Schema::any();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}

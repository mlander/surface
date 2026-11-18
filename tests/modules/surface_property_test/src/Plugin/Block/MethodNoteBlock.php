<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A block that is its own structure, with calls: PropertyNoteBlock's twin.
 */
#[Block(
  id: 'surface_property_test_method_note',
  admin_label: new TranslatableMarkup('Note, declared with calls (property test)'),
)]
#[UsesStructure]
#[Structure('property_test.note')]
final class MethodNoteBlock extends TypedBlockBase implements StructureInterface {

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
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}

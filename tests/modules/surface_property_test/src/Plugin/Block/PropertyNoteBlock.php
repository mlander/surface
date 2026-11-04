<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Plugin\Block\TypedBlockBase;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * A block that is its own structure, as properties: MethodNoteBlock's twin.
 *
 * #[UsesStructure] with no argument: the keys are public properties of
 * the plugin class, and the reader leaves the plugin's own protected
 * properties alone. The block never sets them: its configuration is the
 * plugin's, as for any block.
 */
#[Block(
  id: 'surface_property_test_property_note',
  admin_label: new TranslatableMarkup('Note, declared as properties (property test)'),
)]
#[UsesStructure]
#[Structure('property_test.note.properties')]
final class PropertyNoteBlock extends TypedBlockBase implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Color', required: TRUE)]
  #[Constraint('Choice', ['choices' => ['yellow', 'pink']])]
  public string $color = 'yellow';

  #[Input('Note')]
  #[Constraint('Length', ['max' => 40])]
  public string $note = 'Sticky';

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#plain_text' => (string) ($this->configuration['note'] ?? '')];
  }

}

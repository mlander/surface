<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The test block's configuration: five keys, one refined by another.
 *
 * Named by SurfaceTestBlock with #[UsesStructure]. The variant a
 * casing offers is the one dependent key, so the block host's refinement
 * rebuild, the plugin form and the pipeline all have something to
 * narrow.
 */
#[Structure('test.block')]
final class TestBlockStructure implements StructureInterface {

  /**
   * The variant choices each casing offers.
   *
   * A method rather than a class constant because the labels are
   * translatable markup, and PHP allows no object in a constant.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Variant labels keyed by value, keyed by the casing offering them.
   */
  public static function variants(): array {
    return [
      'uppercase' => [
        'bold' => t('Bold'),
        'strong' => t('Strong'),
      ],
      'lowercase' => [
        'quiet' => t('Quiet'),
        'muted' => t('Muted'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('headline', Schema::string()
      ->title(t('Headline'))
      ->description(t('Shown above the items.'))
      ->default('Featured')
      ->maxLength(20)
      ->examples(['Quarterly report'])
      ->required());
    $inputs->addSchema('limit', Schema::integer()
      ->title(t('Number of items'))
      ->default(10)
      ->minimum(1)
      ->maximum(50));
    $inputs->addSchema('show_summary', Schema::bool()
      ->title(t('Show summaries'))
      ->default(TRUE));
    $inputs->addSchema('casing', Schema::options([
      'none' => t('As written'),
      'uppercase' => t('Upper case'),
      'lowercase' => t('Lower case'),
    ])
      ->title(t('Casing'))
      ->default('none'));
    $inputs->addSchema('variant', Schema::string()
      ->title(t('Variant'))
      ->description(t('Pick a casing other than none to see its variants.')));
  }

  /**
   * A casing other than none offers its own variants, and no others.
   */
  #[RefinesInput('variant')]
  public static function variantOfCasing(string $casing): Schema {
    $offered = static::variants()[$casing] ?? NULL;
    if ($offered === NULL) {
      return Schema::any();
    }
    return Schema::options($offered)->description(t('A @casing variant.', ['@casing' => $casing]));
  }

}

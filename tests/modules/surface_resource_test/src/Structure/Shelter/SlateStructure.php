<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Shelter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Slate: the hut's covering for the "slate" roof, its tiles required.
 */
#[Structure('resource_test.shelter.slate')]
#[Variant(of: HutStructure::class, at: 'covering', for: 'slate', label: new TranslatableMarkup('Slate'))]
final class SlateStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('tiles', Schema::integer()
      ->title(t('Tiles'))
      ->minimum(1)
      ->required());
  }

}

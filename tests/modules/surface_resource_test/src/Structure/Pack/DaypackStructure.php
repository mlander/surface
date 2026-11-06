<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pack;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Pack\PackResource;

/**
 * A daypack: the pack's variant for "day", holding a map with a child.
 *
 * Its capacity is the frame pack's but for the label and a description,
 * so the two share one key, which wears this one's label and description,
 * since discovery finds this variant first. Its rain cover is a map
 * holding a pouch, a child of its own, which makes it a structure with no
 * id, the pack's child at "cover".
 */
#[Structure('resource_test.pack.day')]
#[Variant(of: PackResource::class, at: 'carry', for: 'day', label: new TranslatableMarkup('Daypack'))]
final class DaypackStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('capacity', 'integer', t('Volume'))
      ->setDescription(t('In liters.'))
      ->addConstraint('Range', ['min' => 10]);

    $inputs->addMap('cover', t('Rain cover'), static function (BlueprintInterface $cover): void {
      $cover->add('color', 'string', t('Color'));
      $cover->attach('pouch', PocketStructure::class)
        ->setLabel(t('Pouch'));
    });
  }

}

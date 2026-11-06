<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Pack;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Access\FramePackAccess;
use Drupal\surface_resource_test\Resource\Pack\PackResource;

/**
 * A frame pack: the pack's variant for "frame", holding two children.
 *
 * Its hip belt is a child at a fixed key, its pockets a collection of at
 * most three; both are the pack's own children at those keys. It declares
 * the capacity as the daypack does but for the label. Its access class
 * omits it, by permission, and its children with it.
 */
#[Structure('resource_test.pack.frame', access: FramePackAccess::class)]
#[Variant(of: PackResource::class, at: 'carry', for: 'frame', label: new TranslatableMarkup('Frame pack'))]
final class FramePackStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('capacity', 'integer', t('Capacity'))
      ->addConstraint('Range', ['min' => 10]);

    $inputs->attach('belt', BeltStructure::class)
      ->setLabel(t('Hip belt'));

    $inputs->attachList('pockets', PocketStructure::class, max: 3)
      ->setLabel(t('Pockets'));
  }

}

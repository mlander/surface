<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Kit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Resource\Kit\KitResource;

/**
 * A tent: the kit's variant for "tent", at its gear.
 *
 * It declares the weight as the stove does, which the kit stores as one
 * key beside its kind. Its poles are required wherever it is chosen; with
 * the rain fly up, a rule of its own asks for two at least.
 */
#[Structure('resource_test.kit.tent', label: new TranslatableMarkup('Tent kit'))]
#[Variant(of: KitResource::class, at: 'gear', for: 'tent', label: new TranslatableMarkup('Tent'))]
final class TentKitStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', t('Weight'))
      ->addConstraint('Range', ['min' => 0]);
    $inputs->add('poles', 'integer', t('Poles'), default: 2)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1]);
    $inputs->add('fly', 'boolean', t('Rain fly'), default: FALSE);
    $inputs->narrow('poles')
      ->when(Condition::equals('fly', TRUE))
      ->constrain('Range', ['min' => 2])
      ->because(t('A rain fly takes two poles at least.'));
  }

}

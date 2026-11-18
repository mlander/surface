<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_values_test\Resource\ValuesResource;
use PortableSchema\Builder\Schema;

/**
 * The plan's variant that holds a secret, which the other does not.
 */
#[Structure('values_test.plan.premium', label: new TranslatableMarkup('Premium plan'))]
#[Variant(of: ValuesResource::class, at: 'plan', for: 'premium', label: new TranslatableMarkup('Premium'))]
final class PremiumPlanStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('seats', Schema::integer()
      ->title(t('Seats'))
      ->default(10));
    $inputs->add('license', Schema::string()
      ->title(t('License key'))
      ->writeOnly(TRUE));
  }

}

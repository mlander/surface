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
 * The plan's variant that holds no secret.
 */
#[Structure('values_test.plan.basic', label: new TranslatableMarkup('Basic plan'))]
#[Variant(of: ValuesResource::class, at: 'plan', for: 'basic', label: new TranslatableMarkup('Basic'))]
final class BasicPlanStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('seats', Schema::integer()
      ->title(t('Seats'))
      ->default(1));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface_resource_test\Shape\Sku;

/**
 * Tightens the stock code at every key that uses it: at most twelve.
 */
#[Alters(Sku::class)]
final class SkuAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->narrow('resource_test.sku')->constrain('Length', ['max' => 12]);
    $inputs->describe('resource_test.sku', description: $this->t('Two capitals and digits, as printed on the shelf.'));
  }

}

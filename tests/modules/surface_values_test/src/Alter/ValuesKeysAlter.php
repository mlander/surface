<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Alter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\ResourceBuild\StorageAdapter;
use Drupal\surface_values_test\Resource\ValuesResource;

/**
 * Mounts a key inside the stored map of mounted keys, and rules it out.
 *
 * The key `extra` is ruled out while `gate` holds a value, as it does by
 * default, so an accepted map of this module's keys leaves `extra` out
 * and kept() puts back what storage holds for it.
 */
#[Alters(ValuesResource::class)]
final class ValuesKeysAlter implements AltersInputsInterface {

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
    $inputs->add('gate', 'string', $this->t('Gate'), default: 'on');
    $inputs->add('extra', 'string', $this->t('Extra'), default: 'kept-extra');
    $inputs->describe(StorageAdapter::THIRD_PARTY . '.surface_values_test', label: $this->t('Values test'));
    $inputs->narrow('extra')
      ->when(Condition::present('gate'))
      ->doesNotApply();
  }

}

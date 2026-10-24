<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;

/**
 * Example 15: what the venue forms say once they wrote, and where they go.
 *
 * Each says what was done to which venue and goes back to the example's
 * page, which lists the venues: a removed venue has no form to stay on.
 */
final class VenueFormCosmetics implements DataSurfaceFormCosmeticsInterface {

  use StringTranslationTrait;

  /**
   * Constructs VenueFormCosmetics.
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
  public function alterSurfaceForm(array $form, DataSurfaceInterface $surface, FormStateInterface $form_state, string $operation, ?string $subject): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormMessage(DataSurfaceResult $result, string $operation, ?string $subject): \Stringable {
    $label = (string) ($result->values['label'] ?? $subject ?? '');
    return match ($operation) {
      'add' => $this->t('The venue %label has been added.', ['%label' => $label]),
      'remove' => $this->t('The venue %label has been removed.', ['%label' => $label]),
      default => $this->t('The venue %label has been saved.', ['%label' => $label]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormRedirect(DataSurfaceResult $result, string $operation, ?string $subject): Url {
    return Url::fromRoute('data_surface_examples.step15');
  }

}

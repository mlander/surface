<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceFormCosmeticsInterface;
use Drupal\surface\Pipeline\SurfaceResult;

/**
 * Example 15: what the venue forms say once they wrote, and where they go.
 *
 * Each says what was done to which venue and goes back to the example's
 * page, which lists the venues: a removed venue has no form to stay on.
 */
final class VenueFormCosmetics implements SurfaceFormCosmeticsInterface {

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
  public function alterSurfaceForm(array $form, SurfaceInterface $surface, FormStateInterface $form_state, string $operation, ?string $subject): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormMessage(SurfaceResult $result, string $operation, ?string $subject): \Stringable {
    $label = (string) ($result->values['label'] ?? $subject ?? '');
    return match ($operation) {
      'create' => $this->t('The venue %label has been added.', ['%label' => $label]),
      'delete' => $this->t('The venue %label has been removed.', ['%label' => $label]),
      default => $this->t('The venue %label has been saved.', ['%label' => $label]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormRedirect(SurfaceResult $result, string $operation, ?string $subject): Url {
    return Url::fromRoute('surface_examples.step15');
  }

}

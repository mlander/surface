<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\surface\Form\SurfaceFormCosmeticsInterface;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\SurfaceInterface;

/**
 * What the basic page's delete form says once it wrote, and where it goes.
 *
 * It says what was done to which page and goes back to the example's
 * landing page, which lists the newest pages: the id the delete form was
 * served at names nothing once the page is deleted, so the form has
 * nothing to stay on. The label is the page's title as the result holds
 * it, else the id. The create and edit routes name no cosmetics and keep
 * the generic message and their page.
 */
final class EntityFormCosmetics implements SurfaceFormCosmeticsInterface {

  use StringTranslationTrait;

  /**
   * Constructs EntityFormCosmetics.
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
    $title = $result->values['title']['value'] ?? NULL;
    $label = is_scalar($title) && (string) $title !== '' ? (string) $title : (string) ($subject ?? '');
    return match ($operation) {
      'create' => $this->t('The page %label has been added.', ['%label' => $label]),
      'delete' => $this->t('The page %label has been deleted.', ['%label' => $label]),
      default => $this->t('The page %label has been saved.', ['%label' => $label]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormRedirect(SurfaceResult $result, string $operation, ?string $subject): Url {
    return Url::fromRoute('surface_examples_entity.index');
  }

}

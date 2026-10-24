<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormCosmeticsInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;

/**
 * What the placement forms say when they are done, and where they go.
 *
 * The message names the block, and each form returns to the block layout
 * of the block's theme, as core's own block forms do. Nothing here
 * changes what a value means.
 */
final class BlockPlacementFormCosmetics implements DataSurfaceFormCosmeticsInterface {

  use StringTranslationTrait;

  /**
   * Constructs a BlockPlacementFormCosmetics.
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
      'create' => $this->t('The block %label has been placed.', ['%label' => $label]),
      'delete' => $this->t('The block %label has been removed.', ['%label' => $label]),
      default => $this->t('The block %label has been saved.', ['%label' => $label]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function surfaceFormRedirect(DataSurfaceResult $result, string $operation, ?string $subject): Url {
    $theme = $result->values['theme'] ?? NULL;
    return is_string($theme) && $theme !== ''
      ? Url::fromRoute('block.admin_display_theme', ['theme' => $theme])
      : Url::fromRoute('block.admin_display');
  }

}

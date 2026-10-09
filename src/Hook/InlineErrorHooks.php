<?php

declare(strict_types=1);

namespace Drupal\data_surface\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;

/**
 * Prints a refinement trigger's own error under it.
 *
 * Core's form element templates have a place for an element's errors,
 * and core leaves it empty, printing every error in the messages at the
 * top of the page; Inline Form Errors fills it. A refinement request
 * answers one question about one element, and the answer belongs beside
 * it, so an element the AJAX callback marked with INLINE_ERROR_KEY has
 * its errors printed there whether or not Inline Form Errors is on. The
 * same value either way, so the two never print it twice.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFormBuilder::refreshSurface()
 */
final class InlineErrorHooks {

  /**
   * Implements hook_preprocess_HOOK() for form_element.
   *
   * @param array $variables
   *   The template variables.
   */
  #[Hook('preprocess_form_element')]
  public function preprocessFormElement(array &$variables): void {
    static::inline($variables);
  }

  /**
   * Implements hook_preprocess_HOOK() for fieldset.
   *
   * Radios and checkboxes are wrapped in a fieldset, not a form element.
   *
   * @param array $variables
   *   The template variables.
   */
  #[Hook('preprocess_fieldset')]
  public function preprocessFieldset(array &$variables): void {
    static::inline($variables);
  }

  /**
   * Hands a marked element's errors to its template.
   *
   * @param array $variables
   *   The template variables.
   */
  protected static function inline(array &$variables): void {
    $element = $variables['element'] ?? [];
    if (!empty($element[DataSurfaceFormBuilderInterface::INLINE_ERROR_KEY]) && !empty($element['#errors'])) {
      $variables['errors'] = $element['#errors'];
    }
  }

}

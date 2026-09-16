<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;

/**
 * The bespoke part of a generated provider form.
 *
 * DataSurfaceProviderForm builds every element from the surface, which
 * leaves exactly three things a form still has an opinion about and a
 * surface never will: how the elements are arranged on the page, what a
 * person is told when the write succeeds, and where they are sent
 * afterwards. This interface is those three, and nothing else — a
 * cosmetic layer that cannot change what a value means, because it runs
 * after the container is built and its return is rendered, not read.
 *
 * Two ways to supply one, both named on the route:
 * - A service id or class in the route's own cosmetics default, which
 *   the class resolver instantiates. This is the spelling for a layer
 *   that is genuinely about one route.
 * - The provider itself implementing this interface, which needs no
 *   route default at all. This is the spelling for a provider whose
 *   presentation is the same wherever it is served from.
 *
 * Every method may decline, by returning the form it was given or by
 * returning NULL, and the generic form then does what it would have
 * done on its own. A cosmetic layer is never obliged to have an opinion
 * about all three.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceProviderForm
 * @see docs/forms.md
 */
interface DataSurfaceFormCosmeticsInterface {

  /**
   * Arranges the built form, after every element exists.
   *
   * Presentation only. Every element keeps its name and its #parents, so
   * grouping, weights, #states, a machine name mirror, an extra
   * description or a different widget type change nothing about
   * extraction, validation, or the machine-visible surface: deleting
   * this method's whole body yields the same flat working form.
   *
   * An implementation that needs to change what a value MEANS — its
   * allowed values, its default, whether it is required — is in the
   * wrong place, and the surface build event is the right one.
   *
   * Borrowing an element type for what it draws borrows what it checks
   * as well, and that half has to go: an element's own #element_validate
   * runs before any form level handler, and a form state keeps only the
   * first error per element, so a check the element brings with it
   * silently replaces the surface's own violation message. Clear
   * #element_validate on an element whose type was swapped for
   * presentation, and let the surface's constraints answer.
   *
   * @param array $form
   *   The complete form, with the surface container under its own key
   *   and the actions element already in place.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface the elements were built from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $operation
   *   The operation the form is serving.
   * @param string|null $subject
   *   The subject the form is serving, or NULL when the provider is its
   *   own subject.
   *
   * @return array
   *   The form, arranged.
   */
  public function alterSurfaceForm(array $form, DataSurfaceInterface $surface, FormStateInterface $form_state, string $operation, ?string $subject): array;

  /**
   * Gets what the person is told when the values are stored.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The pipeline's result, valid and committed. Its values are the
   *   accepted ones, so a message naming what was saved reads them from
   *   here rather than from the form.
   * @param string $operation
   *   The operation the form was serving.
   * @param string|null $subject
   *   The subject the form was serving, or NULL.
   *
   * @return string|\Stringable|null
   *   The status message, or NULL for the generic one.
   */
  public function surfaceFormMessage(DataSurfaceResult $result, string $operation, ?string $subject): string|\Stringable|null;

  /**
   * Gets where the person is sent when the values are stored.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The pipeline's result, valid and committed.
   * @param string $operation
   *   The operation the form was serving.
   * @param string|null $subject
   *   The subject the form was serving, or NULL.
   *
   * @return \Drupal\Core\Url|null
   *   Where to go, or NULL to stay on the form, which is what a form
   *   with nowhere in particular to send anybody does.
   */
  public function surfaceFormRedirect(DataSurfaceResult $result, string $operation, ?string $subject): ?Url;

}

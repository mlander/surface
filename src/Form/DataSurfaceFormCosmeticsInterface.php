<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;

/**
 * The bespoke part of a generated operation form.
 *
 * DataSurfaceOperationForm builds every element from the data surface,
 * and draws them as their keys' arrangements say (groups, weights,
 * placeholders, help), which every door reads. That leaves exactly three
 * things one form still has an opinion about and a data surface never
 * will: what it borrows or adds around the elements (an element of
 * core's, such as vertical tabs around the declared groups, or markup),
 * what a person is told when the write succeeds, and where they are sent
 * afterwards. This interface is those three, and nothing else:
 * a cosmetic layer that cannot change what a value means, because it runs
 * after the container is built and its return is rendered, not read.
 *
 * The route names it, as a service id or class in its cosmetics
 * default, which the class resolver instantiates.
 *
 * Every method may decline, by returning the form it was given or by
 * returning NULL, and the generic form then does what it would have
 * done on its own. A cosmetic layer is never obliged to have an opinion
 * about all three.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceOperationForm
 * @see docs/forms.md
 */
interface DataSurfaceFormCosmeticsInterface {

  /**
   * Borrows or adds what this one form draws, after every element exists.
   *
   * Presentation only, and only what is this form's alone: grouping,
   * weights, placeholders and help are the keys' arrangement
   * (docs/decisions.md#arrangement-is-declared-once), already drawn, each
   * group under DataSurfaceFormBuilderInterface::GROUP_PREFIX and its id.
   * Every element keeps its name and its #parents, so a borrowed element
   * (vertical tabs gathering the groups, a machine name mirror), markup
   * or a different widget type change nothing about extraction,
   * validation, or the machine-visible data surface: deleting this
   * method's whole body yields the same working form.
   *
   * An implementation that needs to change what a value MEANS (its
   * allowed values, its default, whether it is required) is in the wrong
   * place: that belongs to the resource or structure, or to an alter.
   *
   * Borrowing an element type for what it draws borrows what it checks
   * as well, and that half has to go: an element's own #element_validate
   * runs before any form level handler, and a form state keeps only the
   * first error per element, so a check the element brings with it
   * silently replaces the data surface's own violation message. Clear
   * #element_validate on an element whose type was swapped for
   * presentation, and let the data surface's constraints answer.
   *
   * @param array $form
   *   The complete form, with the data surface container under its own
   *   key and the actions element already in place.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the elements were built from.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $operation
   *   The operation the form is serving.
   * @param string|null $subject
   *   The raw value of the operation's first route parameter, or NULL
   *   when the operation takes none.
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

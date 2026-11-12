<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\surface\Contract\ServedContract;

/**
 * What the host of a form answers that the served document does not say.
 *
 * Two facts: the label of the entity a reference holds, which a lookup
 * gives a client and a page loads once; and the selection handler a
 * reference key's candidates are, with its settings, which the key's
 * definition holds and the document does not carry. Beside those, the
 * document a deferred key's `choose` link would serve for one place and
 * one value, which the envelope leaves out where no conditional ties the
 * chosen variant to its place (a variant inside a collection's item). The
 * host answers each from what it holds (the references service, the data
 * surface and the values); a widget asks the first two through its
 * WidgetContext, the drawer the third.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
interface WidgetHostInterface {

  /**
   * Gives the label of the entity a reference names.
   *
   * @param string $entity_type_id
   *   The entity type, as the key's `drupal:SurfaceValidReference` check
   *   names it.
   * @param int|string $id
   *   The id the key holds.
   *
   * @return string|null
   *   The label the account may see, or NULL when the entity is gone or
   *   the account may not see it.
   */
  public function labelOf(string $entity_type_id, int|string $id): ?string;

  /**
   * Gives the selection handler a reference key's candidates are.
   *
   * @param string $pointer
   *   The key's JSON Pointer.
   *
   * @return array{handler: string, handlerSettings: array}|null
   *   The handler's plugin id and its settings, as a reference field holds
   *   them; NULL where the key names none and the default handler for its
   *   bundles answers.
   */
  public function selection(string $pointer): ?array;

  /**
   * Gives the document a deferred key's `choose` link serves for a value.
   *
   * @param string $pointer
   *   The key's JSON Pointer.
   * @param string|int|float|bool $value
   *   What its deciding key holds.
   *
   * @return \Drupal\surface\Contract\ServedContract|null
   *   The full schema with that choice at that place, and a choice at each
   *   key with variants on the way, as the link's route emits it; NULL
   *   where no key there takes that choice.
   */
  public function chosen(string $pointer, string|int|float|bool $value): ?ServedContract;

}

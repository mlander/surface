<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * A widget the emitter may choose for a definition by what it holds.
 *
 * The producer's half of a widget: the emitter reads each key's
 * definition and writes the word of the widget that draws it into the
 * document (`x-portable-ui.widget`), and the form draws the key with the
 * widget that word names. Every stock widget implements this; a module's
 * widget that is chosen by its weight, rather than by a claim or by the
 * word a key asks for, implements it to be chosen at all.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 *
 * @see \Drupal\surface\Contract\WidgetChoice
 */
interface DefinitionWidgetInterface {

  /**
   * Answers whether this widget draws a definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The (possibly refined) definition.
   * @param \Drupal\surface\Contract\WidgetChoice $choice
   *   The choice, for a widget that asks which widget draws what it holds.
   *
   * @return bool
   *   TRUE when this widget draws it.
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool;

}

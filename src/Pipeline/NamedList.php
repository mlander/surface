<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;

/**
 * Values a refusal names in a row, each said for whoever reads it.
 *
 * The allowed values a refusal lists ("Choose Main hall, East room or
 * ..."), set in a row with commas outside translation as the sentence's
 * placeholder, each one a Named rendered at the moment the row is.
 *
 * @see \Drupal\surface\Pipeline\Named
 */
final class NamedList implements MarkupInterface {

  /**
   * The values, in order.
   *
   * @var array<int, \Drupal\surface\Pipeline\Named>
   */
  protected readonly array $named;

  /**
   * Constructs a NamedList.
   *
   * @param \Drupal\surface\Pipeline\Named ...$named
   *   The values, in order.
   */
  public function __construct(Named ...$named) {
    $this->named = array_values($named);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return implode(', ', array_map('strval', $this->named));
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}

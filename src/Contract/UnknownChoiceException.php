<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * A choice that names no key, no list, or no value of the key's list.
 *
 * Thrown by Choices::resolve() before anything is emitted; a door answers
 * it as its own refusal, the HTTP door a 404 naming the pointer.
 *
 * @see \Drupal\data_surface\Contract\Choices
 */
final class UnknownChoiceException extends \InvalidArgumentException {

  /**
   * The pointer names no key of the resource.
   */
  public const NO_KEY = 'key';

  /**
   * The key names no list the site decides: nothing to choose from.
   */
  public const NO_LIST = 'list';

  /**
   * The value is not one of the key's list.
   */
  public const NO_VALUE = 'value';

  /**
   * The pointer passes a key with variants that no choice names.
   */
  public const NOT_CHOSEN = 'unchosen';

  /**
   * Constructs the exception.
   *
   * @param string $pointer
   *   The JSON Pointer of the key it is about.
   * @param string $reason
   *   NO_KEY, NO_LIST, NO_VALUE or NOT_CHOSEN.
   * @param string|null $value
   *   The value sent, for NO_VALUE.
   * @param list<string> $values
   *   The values the key's list holds, for NO_VALUE and NOT_CHOSEN.
   * @param string|null $by
   *   The key that chooses between the variants, where there is one.
   */
  public function __construct(
    public readonly string $pointer,
    public readonly string $reason,
    public readonly ?string $value = NULL,
    public readonly array $values = [],
    public readonly ?string $by = NULL,
  ) {
    parent::__construct(match ($reason) {
      self::NO_KEY => sprintf('No key at %s.', $pointer),
      self::NO_LIST => sprintf('The key at %s has no list to choose from.', $pointer),
      self::NOT_CHOSEN => sprintf('The key at %s holds variants: choose one there first.', $pointer),
      default => sprintf('The key at %s has no %s to choose.', $pointer, (string) $value),
    });
  }

}

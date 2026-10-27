<?php

declare(strict_types=1);

namespace Drupal\data_surface\Compute;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\ComputesValueInterface;

/**
 * Computes a machine name from a title, as core's machine name element does.
 *
 * The stock class for a title and its machine name, `using:
 * MachineName::class` with `when: Compute::UNLESS_SENT`. The first key
 * `from:` names is transliterated, lowercased, each run of characters
 * outside `[a-z0-9_]` replaced by one underscore, cut to the key's Length
 * (166, a `machine_name`'s, when it states none) and trimmed of
 * underscores at both ends: the steps of core's `machine-name.js` with
 * its default `replace_pattern`, in its order, so the name the browser
 * suggests and the name the server computes for a call that leaves it
 * out are one name. "Café Déjà Vu" is `cafe_deja_vu`.
 *
 * Transliteration is core's `transliteration` service, the one service a
 * computed key's class may ask for (PipelineGuard::COMPUTE_ALLOWED): it is
 * a function of a string and a language code, and reads no site state.
 * The language is English, the language a machine name is written in;
 * the browser transliterates for the page's language, which differs only
 * where that language's overrides do, and what the browser suggests is
 * sent, and kept as sent.
 *
 * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
 */
final class MachineName implements ComputesValueInterface {

  /**
   * The longest machine name, when the key states no Length.
   *
   * A `machine_name`'s, which is a config entity id's.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityStorage::MAX_ID_LENGTH
   */
  public const MAX_LENGTH = 166;

  /**
   * The characters a machine name may not hold, as machine-name.js has it.
   */
  public const REPLACE_PATTERN = '[^a-z0-9_]+';

  /**
   * What each run of them becomes.
   */
  public const REPLACE = '_';

  /**
   * The language the source is transliterated for.
   */
  public const LANGCODE = 'en';

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    $source = reset($from);
    if (!is_scalar($source) || trim((string) $source) === '') {
      return NULL;
    }
    $transliterated = \Drupal::transliteration()->transliterate((string) $source, self::LANGCODE, self::REPLACE);
    $replaced = (string) preg_replace('/' . self::REPLACE_PATTERN . '/', self::REPLACE, mb_strtolower($transliterated));
    $name = trim(mb_substr($replaced, 0, static::maxLength($key)), self::REPLACE);
    return $name === '' ? NULL : $name;
  }

  /**
   * Reads the longest machine name the key allows.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $key
   *   The computed key.
   *
   * @return int
   *   Its Length's maximum, or MAX_LENGTH.
   */
  protected static function maxLength(?DataDefinitionInterface $key): int {
    // As declared, not through getConstraints(), which asks typed data for
    // the type's defaults: services this class may not reach.
    $constraints = $key instanceof \ArrayAccess ? $key['constraints'] ?? [] : [];
    $length = is_array($constraints) ? ($constraints['Length'] ?? NULL) : NULL;
    $max = is_array($length) ? ($length['max'] ?? NULL) : NULL;
    return is_numeric($max) && (int) $max > 0 ? (int) $max : self::MAX_LENGTH;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;

/**
 * Hands Symfony a constraint message's source string.
 *
 * A message option written with t() is a source potx extracts, as every
 * other string a person reads is. The validator wants the source itself:
 * a Symfony constraint holds its message as a string, and core's
 * validator translator (DrupalTranslator) translates that string when a
 * violation is made, in the language of the request, filling in the
 * constraint's own parameters. So the build replaces each such message
 * with its untranslated string, and the validator, the served contract
 * and the tool listing all meet the one source the author wrote.
 *
 * Only a plain source is unwrapped: a message with arguments, a context
 * or plural forms says more than a source string can, and is left as the
 * object it is.
 *
 * @see \Drupal\Core\Validation\DrupalTranslator::trans()
 * @see docs/semantics.md#violation-messages-are-objects
 */
final class ConstraintMessages {

  /**
   * Replaces each translatable message option with its source, in place.
   *
   * Every constraint on the definition, and on each property of a map
   * or the item of a list inside it, at any depth.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition; changed in place.
   */
  public static function sources(DataDefinitionInterface $definition): void {
    if ($definition instanceof DataDefinition) {
      // The declared constraints alone: getConstraints() adds the data
      // type's defaults, which are not the declaration's to rewrite.
      $declared = $definition->toArray()['constraints'] ?? [];
      $unwrapped = self::unwrapped($declared);
      if ($unwrapped !== $declared) {
        $definition->setConstraints($unwrapped);
      }
    }
    if ($definition instanceof MapDataDefinition) {
      foreach ($definition->getPropertyDefinitions() as $property) {
        self::sources($property);
      }
    }
    if ($definition instanceof ListDataDefinition) {
      self::sources($definition->getItemDefinition());
    }
  }

  /**
   * Gets a message option as the validator reads it.
   *
   * @param mixed $message
   *   A message option, as declared.
   *
   * @return mixed
   *   Its source string, for a translatable one with nothing but a source;
   *   otherwise the option as it is.
   */
  public static function source(mixed $message): mixed {
    if (!$message instanceof TranslatableMarkup || $message instanceof PluralTranslatableMarkup) {
      return $message;
    }
    if ($message->getArguments() !== [] || $message->getOption('context') !== '') {
      return $message;
    }
    return $message->getUntranslatedString();
  }

  /**
   * Unwraps the message options of a definition's declared constraints.
   *
   * @param array<string, mixed> $constraints
   *   The constraints, keyed by name.
   *
   * @return array<string, mixed>
   *   The same, each translatable message a source string.
   */
  protected static function unwrapped(array $constraints): array {
    foreach ($constraints as $name => $options) {
      if (!is_array($options)) {
        continue;
      }
      foreach ($options as $option => $value) {
        // Symfony names every message option so: message,
        // notInRangeMessage, maxMessage.
        if (is_string($option) && preg_match('/message$/i', $option)) {
          $constraints[$name][$option] = self::source($value);
        }
      }
    }
    return $constraints;
  }

}

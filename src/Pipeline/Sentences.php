<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * A refusal said in whole sentences, set side by side.
 *
 * A constraint's message is a whole sentence. A refusal that says more
 * about the values sent (what the key depends on and what it holds, what
 * may be chosen) says it in sentences of its own, each one whole source
 * string a translator sees entire, and this sets them side by side
 * outside any translation: the message is kept whole, still the object
 * the constraint built, and no translated fragment is joined to another.
 *
 * It is also where a refusal's renderer is chosen. Cast to a string, a
 * message is rendered for a form: a key a sentence names by its label,
 * a value by its option label. Rendered through machine(), the same
 * sentence names a key by its dotted path and a value by what is sent,
 * its label beside it, for the tool's text and its result's `message`;
 * React, a person's door, renders as the form does. The sentences are
 * the same source strings either way; only the Named placeholders in
 * them are spelled otherwise.
 *
 * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
 *
 * @see \Drupal\surface\Pipeline\Validating::sayingWhatIsAllowed()
 * @see \Drupal\surface\Pipeline\Named
 */
final class Sentences implements MarkupInterface {

  /**
   * Whether a message is being rendered for a machine right now.
   */
  protected static bool $machine = FALSE;

  /**
   * The sentences, in order.
   *
   * @var array<int, string|\Stringable>
   */
  protected readonly array $sentences;

  /**
   * Constructs a Sentences.
   *
   * @param string|\Stringable ...$sentences
   *   The sentences, the refusal's own message first, each still the
   *   object it was built as.
   */
  public function __construct(string|\Stringable ...$sentences) {
    $this->sentences = array_values($sentences);
  }

  /**
   * Says a refusal of the dialect's validator, by its code and params.
   *
   * The validator says no text. Each code family has one source string,
   * its params as placeholders: `minimum` "This value should be @limit or
   * more.", `maxLength` "This value is too long. It should have @limit
   * characters or less.", `format.<name>` a sentence for the format. A
   * `type` that asks for null alone, which a key with variants answers
   * when the chosen variant refuses its value, is "This value does not
   * fit the chosen variant.". A key bounded on both sides is said as
   * Range says it, "This value should be between @min and @max.", from
   * the two bounds its schema holds ($context `min` and `max`).
   * `required` names the property by its label ($context `label`),
   * `unknownProperty` by its dotted path ($context `key`). A code no
   * family is for is said as "This value is not valid."
   *
   * Decision: see
   * docs/decisions.md#validation-runs-through-the-dialects-validator.
   *
   * @param string $code
   *   The error code.
   * @param array<string, mixed> $params
   *   Its params, as the validator gives them.
   * @param array<string, mixed> $context
   *   What the code's sentence names beyond its params: `label`, `key`,
   *   `min`, `max`.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The sentence.
   */
  public static function ofError(string $code, array $params, array $context, TranslationInterface $translation): TranslatableMarkup {
    $limit = is_scalar($params['limit'] ?? NULL) ? $params['limit'] : 0;
    $both = isset($context['min'], $context['max']) && is_scalar($context['min']) && is_scalar($context['max']);
    if (str_starts_with($code, 'format.')) {
      return match (substr($code, strlen('format.'))) {
        'email' => new TranslatableMarkup('This value is not a valid email address.', [], [], $translation),
        'uri' => new TranslatableMarkup('This value is not a valid URI: it needs a scheme, such as https: or internal:.', [], [], $translation),
        'date-time' => new TranslatableMarkup('This value is not a valid date and time: it needs a time zone offset, such as Z.', [], [], $translation),
        'date' => new TranslatableMarkup('This value is not a valid date.', [], [], $translation),
        'uuid' => new TranslatableMarkup('This value is not a valid UUID.', [], [], $translation),
        default => new TranslatableMarkup('This value is not a valid @format.', ['@format' => substr($code, strlen('format.'))], [], $translation),
      };
    }
    $expected = array_diff(array_map('strval', (array) ($params['expected'] ?? [])), ['null']);
    return match ($code) {
      // A `type` that asks for null alone: the variant the value was held
      // to refused it.
      'type' => $expected === []
        ? new TranslatableMarkup('This value does not fit the chosen variant.', [], [], $translation)
        : new TranslatableMarkup('This value must be of type @expected, @actual given.', [
          '@expected' => implode(' or ', $expected),
          '@actual' => (string) ($params['actual'] ?? ''),
        ], [], $translation),
      'required' => new TranslatableMarkup('@label is required.', ['@label' => $context['label'] ?? (string) ($params['property'] ?? '')], [], $translation),
      'unknownProperty' => new TranslatableMarkup('Unknown key @key.', ['@key' => $context['key'] ?? (string) ($params['property'] ?? '')], [], $translation),
      'const' => new TranslatableMarkup('This value should be @expected.', ['@expected' => static::spelled($params['expected'] ?? NULL)], [], $translation),
      'enum' => new TranslatableMarkup('The value you selected is not a valid choice.', [], [], $translation),
      'minLength' => new PluralTranslatableMarkup((int) $limit, 'This value is too short. It should have 1 character or more.', 'This value is too short. It should have @count characters or more.', [], [], $translation),
      'maxLength' => new PluralTranslatableMarkup((int) $limit, 'This value is too long. It should have 1 character or less.', 'This value is too long. It should have @count characters or less.', [], [], $translation),
      'pattern' => new TranslatableMarkup('This value is not valid.', [], [], $translation),
      'minimum', 'maximum' => match (TRUE) {
        $both => new TranslatableMarkup('This value should be between @min and @max.', [
          '@min' => $context['min'],
          '@max' => $context['max'],
        ], [], $translation),
        $code === 'minimum' => new TranslatableMarkup('This value should be @limit or more.', ['@limit' => $limit], [], $translation),
        default => new TranslatableMarkup('This value should be @limit or less.', ['@limit' => $limit], [], $translation),
      },
      'exclusiveMinimum' => new TranslatableMarkup('This value should be greater than @limit.', ['@limit' => $limit], [], $translation),
      'exclusiveMaximum' => new TranslatableMarkup('This value should be less than @limit.', ['@limit' => $limit], [], $translation),
      'multipleOf' => new TranslatableMarkup('This value should be a multiple of @divisor.', ['@divisor' => static::spelled($params['divisor'] ?? NULL)], [], $translation),
      'minItems' => new PluralTranslatableMarkup((int) $limit, 'This collection should contain 1 element or more.', 'This collection should contain @count elements or more.', [], [], $translation),
      'maxItems' => new PluralTranslatableMarkup((int) $limit, 'This collection should contain 1 element or less.', 'This collection should contain @count elements or less.', [], [], $translation),
      'uniqueItems' => new TranslatableMarkup('Each item may be listed once: items @indexes are the same.', ['@indexes' => implode(', ', array_map('strval', (array) ($params['indexes'] ?? [])))], [], $translation),
      'minProperties' => new PluralTranslatableMarkup((int) $limit, 'This should hold 1 key or more.', 'This should hold @count keys or more.', [], [], $translation),
      'maxProperties' => new PluralTranslatableMarkup((int) $limit, 'This should hold 1 key or less.', 'This should hold @count keys or less.', [], [], $translation),
      'propertyNames' => new TranslatableMarkup('The key @key is not allowed here.', ['@key' => $context['key'] ?? (string) ($params['property'] ?? '')], [], $translation),
      'dependentRequired' => new TranslatableMarkup('@property needs @dependency too.', [
        '@property' => (string) ($params['property'] ?? ''),
        '@dependency' => (string) ($params['dependency'] ?? ''),
      ], [], $translation),
      'not' => new TranslatableMarkup('This value is not allowed.', [], [], $translation),
      'oneOf', 'anyOf' => (int) ($params['matched'] ?? 0) > 1
        ? new TranslatableMarkup('This value matches more than one of the shapes it may take.', [], [], $translation)
        : new TranslatableMarkup('This value matches none of the shapes it may take.', [], [], $translation),
      'typeBy.unresolved' => new TranslatableMarkup('Nothing describes a value of @value here.', ['@value' => static::spelled($params['value'] ?? NULL)], [], $translation),
      default => new TranslatableMarkup('This value is not valid.', [], [], $translation),
    };
  }

  /**
   * Spells a JSON value in a sentence.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   A string as itself, anything else as JSON.
   */
  protected static function spelled(mixed $value): string {
    return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

  /**
   * Renders a refusal for a machine: values as sent.
   *
   * A value is named by what is sent, its label beside it, and a key a
   * computed key reads or variants are chosen by, by its path; a key a
   * condition reads is named by its label in either rendering.
   *
   * The one place the machine renderer is chosen: for as long as the
   * message renders, every Named in it, at any depth, is spelled the
   * way a caller sends it. A translated sentence's placeholders are
   * formatted each time it renders, so nothing a form rendered before
   * is reused.
   *
   * @param string|\Stringable $message
   *   The message, as a violation carries it.
   *
   * @return string
   *   The message rendered, as markup: escaped once, like any message
   *   cast to a string.
   */
  public static function machine(string|\Stringable $message): string {
    $was = static::$machine;
    static::$machine = TRUE;
    try {
      return $message instanceof MarkupInterface ? (string) $message : Html::escape((string) $message);
    }
    finally {
      static::$machine = $was;
    }
  }

  /**
   * Answers whether a message is being rendered for a machine.
   *
   * @return bool
   *   TRUE inside machine().
   */
  public static function isMachine(): bool {
    return static::$machine;
  }

  /**
   * Gets the sentences as they were handed over.
   *
   * @return array<int, string|\Stringable>
   *   The sentences, the refusal's own message first.
   */
  public function sentences(): array {
    return $this->sentences;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $rendered = [];
    foreach ($this->sentences as $sentence) {
      // A plain string was never markup, so it is escaped, as Drupal
      // escapes any string it is handed to print.
      $rendered[] = $sentence instanceof MarkupInterface ? (string) $sentence : Html::escape((string) $sentence);
    }
    return implode(' ', $rendered);
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}

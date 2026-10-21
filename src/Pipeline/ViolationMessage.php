<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * A constraint's message with its Symfony placeholders filled in.
 *
 * Core's validator translator turns a violation's `{{ max }}` parameter
 * into `%max` and leaves the message alone, so core's own constraints
 * write their messages with `%max`. A message written the way Symfony
 * documents it, `At most {{ max }} recipients.`, as a custom
 * `notInRangeMessage` usually is, therefore reached the person with the
 * braces in it. This wraps such a message: the original stays the object
 * the constraint built, translated at render time as before, and each
 * `{{ name }}` the violation has a parameter for is replaced afterwards,
 * escaped and emphasized as `%name` would be. The form, the tool and the
 * served contract all read violations through the pipeline, so all three
 * show the number.
 *
 * Still an object end to end: rendering happens at each door's boundary,
 * when the message is cast to a string.
 *
 * @see \Drupal\Core\Validation\DrupalTranslator::processParameters()
 */
final class ViolationMessage implements MarkupInterface {

  /**
   * Constructs a ViolationMessage.
   *
   * @param string|\Stringable $message
   *   The message as the constraint built it.
   * @param array<string, string> $replacements
   *   Each `{{ name }}` placeholder the message holds => its value, as
   *   plain text.
   */
  protected function __construct(
    protected readonly string|\Stringable $message,
    protected readonly array $replacements,
  ) {}

  /**
   * Gets a violation's message, its Symfony placeholders filled in.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   The violation.
   *
   * @return string|\Stringable
   *   The violation's own message when it holds no Symfony placeholder it
   *   has a value for, which is nearly always; otherwise the message
   *   wrapped.
   */
  public static function of(ConstraintViolationInterface $violation): string|\Stringable {
    $message = $violation->getMessage();
    if ($message instanceof PluralTranslatableMarkup || !str_contains((string) $violation->getMessageTemplate(), '{{ ')) {
      return $message;
    }
    $replacements = [];
    foreach ($violation->getParameters() as $placeholder => $value) {
      if (is_string($placeholder) && str_starts_with($placeholder, '{{ ') && (is_scalar($value) || $value instanceof \Stringable)) {
        $replacements[$placeholder] = (string) $value;
      }
    }
    return $replacements === [] ? $message : new self($message, $replacements);
  }

  /**
   * Respells a constraint message's Symfony placeholders as Drupal's.
   *
   * For a message handed to a validator this module does not read the
   * violations of, such as the Tool API's own input validation: core's
   * translator fills `%max` from the violation's `{{ max }}` parameter.
   *
   * @param string $message
   *   A constraint's message option.
   *
   * @return string
   *   The message, each `{{ name }}` written `%name`.
   */
  public static function drupalPlaceholders(string $message): string {
    return (string) preg_replace('/\{\{ (\w+) \}\}/', '%$1', $message);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $rendered = (string) $this->message;
    $safe = $this->message instanceof MarkupInterface;
    $replacements = [];
    foreach ($this->replacements as $placeholder => $value) {
      $replacements[$placeholder] = '<em class="placeholder">' . Html::escape($value) . '</em>';
    }
    // A plain string was never markup, so it is escaped first, as Drupal
    // escapes any string it is handed to print.
    return strtr($safe ? $rendered : Html::escape($rendered), $replacements);
  }

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): string {
    return $this->__toString();
  }

}

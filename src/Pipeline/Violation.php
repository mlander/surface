<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Symfony\Component\Validator\ConstraintViolationInterface;
use Drupal\data_surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Type;

/**
 * One thing a data surface, a storage or the pipeline itself refused.
 *
 * Five facts: which top level key the refusal belongs to, where inside
 * that key's value it sits, what to say about it, a stable code a
 * program branches on, and whether it blocks. A stale entry carries a
 * sixth, the value it keeps.
 *
 * Almost every entry blocks. The one that does not is a stale
 * reference — a stored value that has fallen outside the list its key
 * now offers — which is reported so it can be said out loud and
 * deliberately does not stop the save, because the alternative is
 * resetting a value nobody asked to change. See ViolationSet for how
 * the two are kept apart, and docs/semantics.md for the rule.
 *
 * The message stays whatever object built it: a constraint's
 * TranslatableMarkup, the data surface's own required message, a config
 * schema's complaint. Rendering it here would print its placeholders
 * once as plain text, and whoever displayed the result afterwards would
 * escape that text a second time. A form error, a tool result and a log
 * line each render it themselves, at their own boundary.
 *
 * The code is what a script or an agent reads instead of the message,
 * which moves with the site's language and its alters: a constraint's own
 * Symfony code, by the name its constraint gives it (codeOf()), or one of
 * the module's codes below for a refusal the module makes itself. The
 * table is in docs/semantics.md (Codes).
 *
 * Decision: see docs/decisions.md#one-result-at-every-door.
 *
 * @see \Drupal\data_surface\Pipeline\ViolationSet
 * @see docs/pipeline.md
 */
final class Violation {

  /**
   * A key, or a property of a map, that must hold a value holds none.
   */
  public const REQUIRED = 'required';

  /**
   * A value was sent for a key a rule ruled out for the values sent.
   */
  public const DOES_NOT_APPLY = 'does_not_apply';

  /**
   * A key the data surface does not declare, at any depth.
   */
  public const UNKNOWN_KEY = 'unknown_key';

  /**
   * Locked: a call refused whole, or a value sent for a locked key.
   *
   * The whole call, for a resource that may be seen and not changed; as
   * a note, a value sent for a key locked one at a time, not taken.
   */
  public const LOCKED = 'locked';

  /**
   * Null sent for a key its storage always holds.
   */
  public const CANNOT_CLEAR = 'cannot_clear';

  /**
   * A value in a shape its definition cannot hold.
   */
  public const TYPE_MISMATCH = 'type_mismatch';

  /**
   * A key sent for a variant the deciding key did not choose.
   */
  public const WRONG_VARIANT = 'wrong_variant';

  /**
   * A key with variants holding a value while its deciding key holds none.
   */
  public const NO_VARIANT = 'no_variant';

  /**
   * A secret stored for a collection's item whose position has moved.
   */
  public const SECRET_IN_ITEM = 'secret_in_item';

  /**
   * The call's parameters name nothing.
   */
  public const NOT_FOUND = 'not_found';

  /**
   * The resource's access answer refused the whole call.
   */
  public const ACCESS = 'access';

  /**
   * The operation's permission refused the whole call.
   */
  public const PERMISSION = 'permission';

  /**
   * What storage holds changed since the caller read it.
   */
  public const CONFLICT = 'conflict';

  /**
   * A create names a thing that exists already: its machine name is taken.
   */
  public const TAKEN = 'taken';

  /**
   * A storage holds settings no schema describes.
   */
  public const NO_SCHEMA = 'no_schema';

  /**
   * A stored value kept, which is no longer one the key offers.
   */
  public const STALE = 'stale';

  /**
   * Values a schema read into a structure refuses, beyond any key's rules.
   *
   * The code of a JsonSchema constraint's refusal too, read off its own
   * code by codeOf().
   */
  public const NOT_VALID = 'not_valid';

  /**
   * Values a schema read into a structure cannot decide here.
   *
   * It refers to a document outside it, which is never fetched.
   */
  public const UNRESOLVED = 'unresolved';

  /**
   * Symfony's constraints whose codes name a violation that lost its own.
   */
  protected const SYMFONY = [
    NotNull::class,
    NotBlank::class,
    Range::class,
    Length::class,
    Choice::class,
    Count::class,
    Regex::class,
    Email::class,
    Type::class,
    JsonSchemaConstraint::class,
  ];

  /**
   * Constructs a Violation.
   *
   * @param string $key
   *   The key the violation is filed under. Always a top level key, even
   *   for something found deep inside a nested value; the empty string
   *   for a refusal of the whole call.
   * @param string $path
   *   The property path within that key's value, dotted; the empty
   *   string for the value itself.
   * @param string|\Stringable $message
   *   What to say about it, unrendered.
   * @param string $code
   *   The stable code: one of this class's constants, or a constraint's,
   *   as codeOf() names it.
   * @param bool $stale
   *   TRUE when the entry is a stale reference rather than a refusal:
   *   the key holds what it always held, that value is no longer among
   *   the ones the key offers, and nothing about this run tried to
   *   change it. A stale entry warns and never blocks.
   * @param mixed $stored
   *   For a stale entry, the value it keeps; NULL otherwise.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $path,
    public readonly string|\Stringable $message,
    public readonly string $code,
    public readonly bool $stale = FALSE,
    public readonly mixed $stored = NULL,
  ) {
  }

  /**
   * Files a constraint's violation, with its message and its code.
   *
   * @param string $key
   *   The top level key.
   * @param string $path
   *   The path within it.
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   The constraint's violation.
   *
   * @return static
   *   The violation, its message the constraint's, Symfony placeholders
   *   filled in.
   */
  public static function fromConstraint(string $key, string $path, ConstraintViolationInterface $violation): static {
    return new static($key, $path, ViolationMessage::of($violation), static::codeOf($violation));
  }

  /**
   * Names a constraint's violation by a stable code.
   *
   * Symfony's own code where the constraint defines one, by the name its
   * constraint gives it, lower case and without `_ERROR`: a Range's
   * `NOT_IN_RANGE_ERROR` is `not_in_range`, a Choice's or an
   * AllowedValues' `NO_SUCH_CHOICE_ERROR` `no_such_choice`, a Length's
   * `TOO_LONG_ERROR` `too_long`; the name stands one to one for Symfony's
   * code, which is a UUID. A constraint that sets no code is named for
   * itself, in snake case: `UniqueNodeTypeConstraint` is
   * `unique_node_type`.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   The violation.
   *
   * @return string
   *   The code.
   */
  public static function codeOf(ConstraintViolationInterface $violation): string {
    $constraint = $violation->getConstraint();
    $code = $violation->getCode();
    if (is_string($code) && $code !== '') {
      // A violation copied without its constraint, as the Tool API copies
      // the ones it refuses an input with, still carries Symfony's code,
      // which is unique: it is named by the constraint that defines it.
      foreach ($constraint === NULL ? self::SYMFONY : [$constraint::class] as $class) {
        try {
          return strtolower((string) preg_replace('/_ERROR$/', '', $class::getErrorName($code)));
        }
        catch (\InvalidArgumentException) {
          // Not this constraint's code.
        }
      }
    }
    if ($constraint === NULL) {
      return 'invalid';
    }
    $name = (string) preg_replace('/Constraint$/', '', (new \ReflectionClass($constraint))->getShortName());
    return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name));
  }

  /**
   * Gets the same violation filed at another place.
   *
   * @param string $key
   *   The top level key.
   * @param string $path
   *   The path within it.
   *
   * @return static
   *   The violation, its message, code and staleness kept.
   */
  public function at(string $key, string $path): static {
    return new static($key, $path, $this->message, $this->code, $this->stale, $this->stored);
  }

  /**
   * Gets the same violation saying something else.
   *
   * @param string|\Stringable $message
   *   The message.
   *
   * @return static
   *   The violation, its place, code and staleness kept.
   */
  public function saying(string|\Stringable $message): static {
    return new static($this->key, $this->path, $message, $this->code, $this->stale, $this->stored);
  }

  /**
   * Gets the full dotted path, from the top level key down.
   *
   * @return string
   *   The top level key, followed by the path within it when there is
   *   one; the empty string for a refusal of the whole call.
   */
  public function fullPath(): string {
    if ($this->key === '') {
      return $this->path;
    }
    return $this->path === '' ? $this->key : $this->key . '.' . $this->path;
  }

}

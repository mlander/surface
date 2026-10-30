<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Refuses an identity a thing already holds, naming both.
 *
 * "A venue named riverside already exists." The stock uniqueness rule.
 * Nobody writes it on a key: the build adds it to the identity key of
 * every operation that creates, when the resource's storage can say
 * whether a thing is stored under an identity
 * (DeletableStorageInterface::exists()), with the storage, the key and
 * the storage's name for one thing as its options. The form's machine
 * name element asks the same storage the same question, so the form, the
 * tool and HTTP refuse one id in one sentence. No JSON Schema keyword
 * says it: both documents name it under `x-surface.checkedOnServer`, its
 * `reason` the sentence beside it.
 *
 * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
 */
#[Constraint(
  id: 'SurfaceUnique',
  label: new TranslatableMarkup('Held once: an identity a thing already holds is refused', [], ['context' => 'Validation']),
)]
final class UniqueConstraint extends SymfonyConstraint {

  /**
   * The code of a refusal, which Violation::codeOf() names `taken`.
   */
  public const TAKEN_ERROR = '6b1f0c2e-7f53-4a3e-9d0b-6a8f6f0d2a41';

  /**
   * {@inheritdoc}
   */
  protected const ERROR_NAMES = [
    self::TAKEN_ERROR => 'TAKEN_ERROR',
  ];

  /**
   * Says what the constraint checks, for `checkedOnServer`.
   *
   * @param string|\Stringable $thing
   *   What one thing is called, singular and lowercase: "venue".
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The reason.
   */
  public static function reason(string|\Stringable $thing): TranslatableMarkup {
    return t('Refused when a @thing named this already exists.', ['@thing' => $thing]);
  }

  /**
   * Constructs a UniqueConstraint.
   *
   * @param string $storage
   *   The class of the resource's storage, which answers exists().
   * @param string $key
   *   The identity key the constraint is on.
   * @param string|\Stringable $thing
   *   What one thing is called, singular and lowercase: "venue".
   * @param string|\Stringable|null $reason
   *   What the constraint checks, for `checkedOnServer`.
   * @param string $message
   *   The refusal.
   * @param array|null $groups
   *   The validation groups.
   * @param mixed $payload
   *   The payload.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $storage = '',
    public string $key = '',
    public string|\Stringable $thing = '',
    public string|\Stringable|null $reason = NULL,
    public string $message = 'A @thing named @id already exists.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(NULL, $groups, $payload);
  }

}

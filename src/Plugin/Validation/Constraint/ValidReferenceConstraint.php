<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\ValidReferenceConstraint as CoreValidReferenceConstraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;

/**
 * Checks that an id names an entity the account may reference.
 *
 * Core's ValidReference, for a key that holds one id rather than a
 * reference field's items: core's validator reads the field's selection
 * handler off the item list, and a key has no field, so the entity type
 * and the bundles are this constraint's options and the handler is the
 * default one for them, configured as References::handler() configures
 * it. A key read from a reference field names the field's handler and
 * its settings as well, so the key accepts what the field's handler
 * accepts. Its messages are core's.
 *
 * The options resolver beside it reads it as the candidates while there
 * are few enough to list; past that, the key takes any id and this
 * validator decides, which both documents name under `portable-check`.
 *
 * @see \Drupal\surface\Reference\References
 * @see \Drupal\surface\Plugin\SurfaceOptionsResolver\ReferenceOptions
 */
#[Constraint(
  id: 'SurfaceValidReference',
  label: new TranslatableMarkup('Refers to an entity the account may reference', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class ValidReferenceConstraint extends CoreValidReferenceConstraint {

  /**
   * Constructs a ValidReferenceConstraint.
   *
   * @param string $entityTypeId
   *   The entity type the id is of.
   * @param string[] $bundles
   *   The bundles it may be of, or none for any.
   * @param string $handler
   *   The selection handler that answers, such as `default:node` or a
   *   view's, as a reference field names it; empty for the default one.
   * @param array $handlerSettings
   *   The handler's settings, as the field holds them; read only with a
   *   handler.
   * @param string|null $message
   *   The error message for an id the account may not reference, or NULL
   *   for core's.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $entityTypeId = '',
    public array $bundles = [],
    public string $handler = '',
    public array $handlerSettings = [],
    ?string $message = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
    if ($message !== NULL) {
      $this->message = $message;
    }
  }

}

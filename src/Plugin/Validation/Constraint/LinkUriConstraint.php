<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a value is a URI a link field holds.
 *
 * A link field's `uri` is a URI with a scheme, as core's link item types
 * it (a `uri` value, held by PrimitiveType to one `parse_url()` reads a
 * scheme from), and Drupal's own schemes are among them: `route:<nolink>`,
 * `route:<front>`, `route:<none>`, `route:<button>`, `internal:/node/1`,
 * `entity:node/1`, `base:robots.txt`. The `uri` format is RFC 3986's,
 * which refuses `<` and `>`, so a key that holds a link says this check in
 * place of the format. It takes every value the format takes: each starts
 * with a scheme and a colon.
 *
 * Decision: see docs/decisions.md#a-link-is-held-to-what-a-link-field-takes.
 */
#[Constraint(
  id: 'SurfaceLinkUri',
  label: new TranslatableMarkup('Link URI', [], ['context' => 'Validation']),
  type: FALSE,
)]
final class LinkUriConstraint extends SymfonyConstraint {

  /**
   * Constructs a LinkUriConstraint.
   *
   * @param string $message
   *   The error message if the value is no URI a link field holds.
   * @param array|null $groups
   *   The groups the constraint belongs to.
   * @param mixed $payload
   *   Domain-specific data attached to the constraint.
   */
  #[HasNamedArguments]
  public function __construct(
    public string $message = 'This value is not a valid link: it needs a scheme, such as https:, internal: or route:.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}

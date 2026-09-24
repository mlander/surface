<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraints\Unique;
use Symfony\Component\Validator\Constraints\UniqueValidator;

/**
 * Checks that no item of a list appears in it twice.
 *
 * Symfony has the constraint and core does not register it, so it is
 * registered here under this module's name rather than assumed. On the
 * content type surface it is how "each tag once" is said as contract:
 * the classic form removes a repeated tag without a word, and the
 * surface refuses the list instead, naming it, because a surface never
 * turns a value into a different one on a caller's behalf.
 *
 * The Tool API's schema normalizer has no mapping for it, so the
 * advertised schema carries no uniqueItems keyword; the key's
 * description says it in words instead.
 */
#[Constraint(
  id: 'DataSurfaceDemoExtrasUniqueItems',
  label: new TranslatableMarkup('Unique list items', [], ['context' => 'Validation']),
)]
final class UniqueItemsConstraint extends Unique {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'Each item may be listed only once.';

  /**
   * {@inheritdoc}
   *
   * Symfony's validator, named explicitly: the inherited answer is this
   * class's own name with "Validator" appended, which does not exist.
   */
  public function validatedBy(): string {
    return UniqueValidator::class;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_entity_test\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * An entity-level constraint, refusing one title and one whole entity.
 *
 * A title of "Forbidden" is refused at the title's value; a title of
 * "Refused whole" is refused about the entity as a whole.
 */
#[Constraint(
  id: 'SurfaceEntityTestTitle',
  label: new TranslatableMarkup('Surface entity test title', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class TitleConstraint extends SymfonyConstraint {

  /**
   * The message for the title.
   */
  public string $message = 'A node may not be called @title.';

  /**
   * The message for the whole entity.
   */
  public string $wholeMessage = 'This node is refused as a whole.';

}

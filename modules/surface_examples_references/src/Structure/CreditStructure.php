<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Structure;

use Drupal\surface\Resource\Attribute\Structure;

/**
 * What the sponsor is credited on: an article or a user.
 */
#[Structure('event.sponsorship.credit')]
final class CreditStructure extends AnyEntityStructure {

  /**
   * {@inheritdoc}
   */
  protected const ENTITY_TYPES = [
    'targets' => ['node' => ['article'], 'user' => []],
  ];

}

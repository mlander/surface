<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Structure;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\surface\Resource\Attribute\Structure;

/**
 * What the sponsor is featured beside: content of any type.
 */
#[Structure('event.sponsorship.thing')]
final class ThingStructure extends AnyEntityStructure {

  /**
   * {@inheritdoc}
   */
  protected const ENTITY_TYPES = ['interface' => ContentEntityInterface::class];

}

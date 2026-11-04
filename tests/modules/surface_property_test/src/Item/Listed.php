<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Item;

use Drupal\surface\Resource\Attribute\Input;

/**
 * One item of the outputs' list: a plain class of inputs, no structure.
 */
final class Listed {

  #[Input('Id')]
  public string $id;

  #[Input('Label')]
  public ?string $label = NULL;

}

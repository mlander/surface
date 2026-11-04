<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a property: an output key, declared on the output blueprint.
 *
 * Says what #[Input] says, of a value the resource or structure answers
 * with and nobody sends: the spelling of add() and addList() in
 * defineOutputs(). The class implements DefinesOutputsInterface, and the
 * trait answers defineOutputs() from these properties alone, as it
 * answers defineInputs() from the others.
 *
 * @code
 * #[Output('Total', description: 'How many venues the filter matched.')]
 * public int $total;
 * @endcode
 *
 * @see \Drupal\surface\Resource\DefinesOutputsInterface
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Output extends Input {

}

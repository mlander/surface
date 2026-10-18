<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A structure: a group of keys with no identity or storage of its own.
 *
 * Carries #[Structure], or nothing at all when a plugin class is its own
 * structure (#[UsesStructure] with no argument). A structure is only ever
 * held by something else: attached to a parent at a key, filling a slot
 * as a variant, or configuring the plugin that names it, whose host
 * supplies the operation and the storage. It may still declare
 * operations, for its container to choose between, and refiners,
 * outputs and an access class, exactly as a resource does.
 *
 * @see \Drupal\data_surface\Resource\Attribute\Structure
 * @see \Drupal\data_surface\Resource\ResourceInterface
 */
interface StructureInterface extends DefinesInputsInterface {

}

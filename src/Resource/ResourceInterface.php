<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * A resource: a thing with identity and storage that operations act on.
 *
 * Carries #[Resource], which names its identity keys, its storage and
 * its access class. Its operations (#[Operation], on the class or on a
 * static method) are the ways it is asked for, each knowing as much of
 * its identity as it says; the form, the tool, the route and the served
 * schema are derived from the resource and the operation, never written
 * beside it. A resource that names no identity keys is a singleton:
 * there is one of it, and its storage knows where.
 *
 * A resource has storage of its own, or a host that supplies it: a
 * plugin that names it with #[UsesStructure], or the container a parent
 * stores it in.
 *
 * @see \Drupal\surface\Resource\Attribute\Resource
 * @see \Drupal\surface\Resource\StructureInterface
 */
interface ResourceInterface extends DefinesInputsInterface {

}

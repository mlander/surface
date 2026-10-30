<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;

/**
 * Makes typed data that is held to the definition it was made from.
 *
 * Core builds the properties of a map, and the items of a list, from a
 * prototype it keeps for the rest of the process, keyed by the root's
 * data type and settings and the property's path, and never by the
 * definition it is asked about. Two definitions with the same root type
 * and the same paths therefore share every property below the root: the
 * first one validated in a process judges every later one. A refined
 * definition is exactly that case. Two builds of one resource differ
 * only in what their refiners narrowed, so the stewards the compliance
 * alter mounts were held to the first call's minimum by every later call
 * in the process, and two resources with a map key of the same name
 * shared its properties' types. A key that is not a map or a list has no
 * properties to build, and the root is always made from the definition
 * handed in, which is why only mounted keys and other maps showed it.
 *
 * Every place the module turns a definition into typed data comes here,
 * and the root is handed to core as a copy carrying a digest of the
 * definition among its settings. The prototype key then names the
 * definition: equal definitions still share prototypes, and different
 * ones never do. Nothing else reads the setting, and the definition
 * handed in is not touched. A list keys its prototypes by its item
 * definition's settings, so a list's digest goes there.
 *
 * Decision: see docs/decisions.md#a-definition-is-validated-as-itself.
 *
 * @see https://www.drupal.org/project/drupal/issues/3616328
 * @see \Drupal\Core\TypedData\TypedDataManager::getPropertyInstance()
 */
final class DefinitionTypedData {

  /**
   * The setting holding the digest of the definition the root was made from.
   */
  public const DIGEST_SETTING = 'surface_definition_digest';

  /**
   * Creates typed data whose every property is the definition's own.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, as refined for the values being judged.
   * @param mixed $value
   *   The value.
   * @param string|null $name
   *   The root's name, if it has one.
   *
   * @return \Drupal\Core\TypedData\TypedDataInterface
   *   The typed data.
   */
  public static function create(TypedDataManagerInterface $typedDataManager, DataDefinitionInterface $definition, mixed $value = NULL, ?string $name = NULL): TypedDataInterface {
    return $typedDataManager->create(static::keyed($definition), $value, $name);
  }

  /**
   * Copies a root definition with its digest where core keys prototypes.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The root definition.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A copy carrying the digest, or the definition itself when it has no
   *   properties or items to build, or nowhere to carry a setting.
   */
  protected static function keyed(DataDefinitionInterface $definition): DataDefinitionInterface {
    if ($definition instanceof ListDataDefinition) {
      // Core's list copies its item definition with itself. Another list
      // definition, a field config's, may share its item with the field,
      // so it is handed over as it is.
      $keyed = clone $definition;
      $holder = $keyed->getItemDefinition();
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface && !$definition instanceof ListDataDefinitionInterface) {
      // Only the root's settings change: its properties are shared with
      // the definition handed in and are only read.
      $keyed = $holder = clone $definition;
    }
    else {
      return $definition;
    }
    if (!$holder instanceof DataDefinition) {
      return $definition;
    }
    $holder->setSetting(self::DIGEST_SETTING, static::digest($definition));
    return $keyed;
  }

  /**
   * Digests what a definition says that validation reads, at every depth.
   *
   * The data type and class, whether the value is required, the settings
   * and the constraints; a map's properties and a list's item with them,
   * since core's complex definitions leave their property definitions
   * out when serialized. A label or a description is left out on
   * purpose: no violation reads them, and a refiner writes the values it
   * was handed into a description ("At least 2 stewards for 80
   * attendees"), which would give core one prototype per distinct value
   * in a long-lived process. Two definitions that differ only in their
   * words share a prototype, and the words on it are the first one's,
   * which nothing that validates can tell.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string
   *   The digest; for a definition that cannot be serialized, which a data
   *   surface never carries, one that matches nothing else.
   */
  protected static function digest(DataDefinitionInterface $definition): string {
    try {
      $parts = [
        serialize([
          $definition->getDataType(),
          $definition->getClass(),
          $definition->isRequired(),
          $definition->isReadOnly(),
          $definition->getSettings(),
          $definition->getConstraints(),
        ]),
      ];
    }
    catch (\Throwable) {
      return bin2hex(random_bytes(16));
    }
    if ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $parts[] = $name . '=' . static::digest($property);
      }
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $parts[] = '[]=' . static::digest($definition->getItemDefinition());
    }
    return hash('xxh128', implode("\0", $parts));
  }

}

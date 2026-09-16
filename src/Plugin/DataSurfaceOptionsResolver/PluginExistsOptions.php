<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Plugin\Validation\Constraint\PluginExistsConstraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a PluginExists constraint as the definitions of its manager.
 *
 * The constraint already says which plugin manager a value names and
 * which interface the plugin has to implement, which is a complete
 * description of a list of options. Nothing beside it has to repeat the
 * manager service name, which is what the adapter-era select adapters
 * did.
 */
#[DataSurfaceOptionsResolver(
  id: 'plugin_exists',
  label: new TranslatableMarkup('Plugin exists'),
  constraint: 'PluginExists',
)]
final class PluginExistsOptions extends DataSurfaceOptionsResolverBase {

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof PluginExistsConstraint);
    $manager = $constraint->pluginManager;
    $options = [];
    foreach ($manager->getDefinitions() as $id => $plugin_definition) {
      if (!$this->hasInterface((string) $id, $plugin_definition, $constraint->interface)) {
        continue;
      }
      $options[$id] = $this->label((string) $id, $plugin_definition);
    }
    $cacheability = new CacheableMetadata();
    if ($manager instanceof CacheableDependencyInterface) {
      $cacheability->addCacheableDependency($manager);
    }
    return new OptionSet($options, [], $cacheability);
  }

  /**
   * Returns whether a plugin's class implements the wanted interface.
   *
   * @param string $id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition, an array or a definition object.
   * @param string|null $interface
   *   The interface the constraint demands, or NULL when it demands
   *   none.
   *
   * @return bool
   *   TRUE when the plugin belongs in the list.
   */
  protected function hasInterface(string $id, mixed $plugin_definition, ?string $interface): bool {
    if ($interface === NULL || $interface === '') {
      return TRUE;
    }
    try {
      $class = DefaultFactory::getPluginClass($id, $plugin_definition);
    }
    catch (PluginException) {
      // A definition that names no class cannot be checked, and cannot
      // be offered either.
      return FALSE;
    }
    return is_a($class, $interface, TRUE);
  }

  /**
   * Reads what a plugin definition calls itself.
   *
   * Three spellings, because plugin definitions have three: an array
   * key, a public property on a definition object, and an accessor over
   * a property the object keeps to itself — which is core's entity type
   * definition, whose label is unreachable any other way. Without the
   * last one an entity type select reads as machine names.
   *
   * @param string $id
   *   The plugin ID, the last resort when the definition says nothing.
   * @param mixed $plugin_definition
   *   The plugin definition, an array or a definition object.
   *
   * @return mixed
   *   The label.
   */
  protected function label(string $id, mixed $plugin_definition): mixed {
    foreach (['label', 'admin_label'] as $key) {
      if (is_array($plugin_definition) && isset($plugin_definition[$key])) {
        return $plugin_definition[$key];
      }
      if ($plugin_definition instanceof PluginDefinitionInterface && isset($plugin_definition->{$key})) {
        return $plugin_definition->{$key};
      }
    }
    foreach (['getLabel', 'getAdminLabel'] as $method) {
      if (is_object($plugin_definition) && method_exists($plugin_definition, $method)) {
        $label = $plugin_definition->{$method}();
        if ($label !== NULL && (string) $label !== '') {
          return $label;
        }
      }
    }
    return $id;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\Plugin\Validation\Constraint\ExtensionExistsConstraint;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Attribute\SurfaceOptionsResolver;
use Drupal\surface\Options\SurfaceOptionsResolverBase;
use Drupal\surface\Options\OptionSet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads an ExtensionExists constraint as the extensions installed.
 *
 * Core's constraint says which kind of extension a value names, a module
 * or a theme, and holds it to one installed, which is a complete
 * description of a list: the installed themes by name, or the installed
 * modules. Which are installed is core.extension's, so the answer is
 * cached by that configuration's tag.
 */
#[SurfaceOptionsResolver(
  id: 'extension_exists',
  label: new TranslatableMarkup('Extension exists'),
  constraint: 'ExtensionExists',
)]
final class ExtensionExistsOptions extends SurfaceOptionsResolverBase {

  /**
   * Constructs an ExtensionExistsOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themes
   *   The theme handler, which lists the installed themes.
   * @param \Drupal\Core\Extension\ModuleExtensionList $modules
   *   The module list, which names the installed modules.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly ThemeHandlerInterface $themes,
    protected readonly ModuleExtensionList $modules,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $constraint_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('validation.constraint'),
      $container->get('theme_handler'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof ExtensionExistsConstraint);
    $options = [];
    if ($constraint->type === 'theme') {
      foreach ($this->themes->listInfo() as $name => $theme) {
        $options[$name] = (string) ($theme->info['name'] ?? $name);
      }
    }
    else {
      foreach (array_keys($this->modules->getAllInstalledInfo()) as $name) {
        $options[$name] = $this->modules->getName($name);
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    $cacheability = (new CacheableMetadata())->addCacheTags(['config:core.extension']);
    return new OptionSet($options, [], $cacheability);
  }

}

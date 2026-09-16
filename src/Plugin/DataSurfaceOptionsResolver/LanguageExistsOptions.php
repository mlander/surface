<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Plugin\Validation\Constraint\LanguageExistsConstraint;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a LanguageExists constraint as the languages the site has.
 *
 * The constraint already says which languages a value may name, locked
 * ones included or not, so the list a form offers is that same list
 * asked for by name. The languages live in site state, so the list
 * carries the tag core invalidates when a language is added or removed.
 */
#[DataSurfaceOptionsResolver(
  id: 'language_exists',
  label: new TranslatableMarkup('Language exists'),
  constraint: 'LanguageExists',
)]
final class LanguageExistsOptions extends DataSurfaceOptionsResolverBase {

  /**
   * The tag core invalidates when the site's languages change.
   *
   * Saving or deleting a language is saving or deleting a
   * configurable_language config entity, so the entity type's list cache
   * tag is what changes; it is the tag core's own language block hangs
   * its cacheability on.
   */
  protected const LANGUAGE_LIST_TAG = 'config:configurable_language_list';

  /**
   * Constructs a LanguageExistsOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager, which names the site's languages.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly LanguageManagerInterface $languageManager,
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
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof LanguageExistsConstraint);
    $options = [];
    foreach ($this->languageManager->getLanguages($constraint->languageStates()) as $langcode => $language) {
      $options[$langcode] = $language->getName();
    }
    $cacheability = (new CacheableMetadata())->addCacheTags([self::LANGUAGE_LIST_TAG]);
    return new OptionSet($options, [], $cacheability);
  }

}

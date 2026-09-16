<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use CommerceGuys\Addressing\Country\CountryRepositoryInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Reads the address module's Country constraint as a country list.
 *
 * The constraint already says a value is a country code, and says which
 * countries when it limits them, so a settings form that wants a country
 * select has nothing left to declare. This is the resolver that made the
 * address field's surface static: before it, the country list had to be
 * built into the definition at runtime, which is the one reason that
 * surface could not be declared on the class it describes.
 *
 * The resolver lives in data_surface rather than in an address specific
 * module because a resolver is inert until something declares the
 * constraint it reads: applies() asks the constraint plugin manager for
 * the Country plugin without demanding that it exist, so on a site
 * without the address module this class answers no and nothing here ever
 * loads an address class.
 */
#[DataSurfaceOptionsResolver(
  id: 'country',
  label: new TranslatableMarkup('Country'),
  constraint: 'Country',
)]
final class CountryOptions extends DataSurfaceOptionsResolverBase {

  /**
   * The cache tag the country repository stores its lists under.
   */
  protected const COUNTRY_LIST_TAG = 'countries';

  /**
   * Constructs a CountryOptions resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \CommerceGuys\Addressing\Country\CountryRepositoryInterface|null $countryRepository
   *   The address module's country repository, or NULL on a site that
   *   does not have the address module — where the constraint this
   *   resolver reads does not exist either, so it is never asked.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly ?CountryRepositoryInterface $countryRepository = NULL,
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
      $container->get('address.country_repository', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The base class asks the constraint plugin manager for the class
   * behind the declared plugin ID and lets a missing plugin throw, which
   * is right for a constraint the same module ships. This one is another
   * module's, so a missing plugin is an ordinary answer: no address
   * module, no Country constraint, nothing to resolve.
   */
  public function applies(Constraint $constraint): bool {
    $definition = $this->constraintManager->getDefinition($this->pluginDefinition['constraint'], FALSE);
    return $definition !== NULL && $constraint instanceof $definition['class'];
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    // The constraint class is the address module's, and nothing here
    // names it: applies() has already established that this constraint
    // is one, and reading the option it declares needs no more than
    // that. Keeping the class name out of this file is what lets the
    // plugin be discovered on a site without the address module.
    assert(property_exists($constraint, 'availableCountries'));
    assert($this->countryRepository !== NULL);
    $options = $this->countryRepository->getList();
    $available = array_filter((array) $constraint->availableCountries);
    if ($available !== []) {
      $options = array_intersect_key($options, array_flip($available));
    }
    // The repository translates the names into the interface language
    // and caches each language's list under one tag, so the answer is
    // good for this language until the lists are rebuilt.
    $cacheability = (new CacheableMetadata())
      ->addCacheTags([self::COUNTRY_LIST_TAG])
      ->addCacheContexts(['languages:language_interface']);
    return new OptionSet($options, [], $cacheability);
  }

}

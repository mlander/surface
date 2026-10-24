<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Variants;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Resource\ClosedSiteListInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface_examples\Structure\ManagedCredentialStructure;
use Drupal\data_surface_examples\Structure\PastedCredentialStructure;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Example 14: the sources a credential may come from on this site.
 *
 * A key pasted, always; a key the site manages, while the module that
 * manages keys is on. Asked at every build and cacheable by the list of
 * modules and the keys' configuration, so the second source is there at
 * the next request after the module is installed, with no cache rebuild.
 * Both are structures of this module, named once in structures(), so the
 * config schema describes both whichever the site offers.
 */
final class CredentialSources implements ClosedSiteListInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The module that manages keys.
   */
  public const KEYS = 'data_surface_examples_keys';

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('module_handler'), $container->get('string_translation'));
  }

  /**
   * {@inheritdoc}
   */
  public static function structures(): array {
    return [
      'pasted' => PastedCredentialStructure::class,
      'managed' => ManagedCredentialStructure::class,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $sources = (new SiteList())
      ->addCacheTags(['config:core.extension', 'config:' . self::KEYS . '.keys'])
      ->add('pasted', $this->t('Pasted here'));
    if ($this->moduleHandler->moduleExists(self::KEYS)) {
      $sources->add('managed', $this->t('A managed key'));
    }
    return $sources;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Hook;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\surface_examples_references\Sponsorship;

/**
 * Example 16: writes its three nodes once the module is installed.
 *
 * After every module installed with it, so the article and page bundles
 * the module ships as optional config are there, which they are not yet
 * when its own install hook runs beside node's. Nothing is written during
 * a configuration import.
 */
final class SponsorshipHooks {

  /**
   * Constructs SponsorshipHooks.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   The class resolver, which builds the writer of the nodes.
   */
  public function __construct(
    protected readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if (!$is_syncing && in_array('surface_examples_references', $modules, TRUE)) {
      $this->classResolver->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\ContractBaselines;
use Drupal\surface\Resource\OperationContext;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Builds every shipped example the contract baselines pin, as they pin it.
 *
 * The modules, the site state and the loop are ContractBaselineTest's and
 * ContractBaselines::emit()'s: each declaration of the pinned modules, in
 * each operation that needs no subject, or alone where it declares none,
 * built for the account that asks.
 */
trait ExampleSurfacesTrait {

  /**
   * The modules whose declarations are built.
   *
   * @var string[]
   */
  protected static array $pinned = [
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_first',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * Sets up the site the baselines are pinned on.
   */
  protected function setUpExamples(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'surface_examples',
      'surface_examples_first',
      'node',
      'filter',
      'surface_examples_references',
      'surface_examples_retitle',
    ]);
    $this->container->get('theme_installer')->install(['stark']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $sponsor->save();
    $this->setCurrentUser($sponsor);
  }

  /**
   * Builds each declaration in each operation its baseline would hold.
   *
   * @param bool $declared
   *   TRUE to build each for no account (buildDeclared()), as the build
   *   cache keeps it; FALSE for the account that asks (buildStatic()).
   * @param string[]|null $modules
   *   The modules whose declarations are built; NULL for the pinned ones
   *   ($pinned). A test that walks more installs more.
   *
   * @return \Generator<int, array{0: string, 1: string|null, 2: \Drupal\surface\SurfaceInterface, 3: int}>
   *   The declaration's id, the operation's id (NULL for one built
   *   alone), the data surface, and the index of its document in the
   *   baseline.
   */
  protected function examples(bool $declared = FALSE, ?array $modules = NULL): \Generator {
    $modules ??= static::$pinned;
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    foreach ($registry->getDefinitions() as $definition) {
      if ($definition->isDerived() || !in_array($definition->module, $modules, TRUE)) {
        continue;
      }
      $name = $definition->name();
      $operations = $registry->getOperations($name);
      $index = 0;
      if ($operations === []) {
        $context = new OperationContext(ContractBaselines::ALONE);
        $surface = $declared ? $resources->buildDeclared($name, $context) : $resources->buildStatic($name, $context);
        yield [$definition->id, NULL, $surface, $index++];
      }
      foreach ($operations as $operation) {
        if (!$operation->needsNothing() || $operation->isDefaultRead()) {
          continue;
        }
        $context = $resources->operation($name, $operation->id);
        $surface = $declared ? $resources->buildDeclared($name, $context) : $resources->buildStatic($name, $context);
        yield [$definition->id, $operation->id, $surface, $index++];
      }
    }
  }

}

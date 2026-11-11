<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds every kernel test's module list to what a real site can have.
 *
 * A kernel test enables the modules it lists and none of their
 * dependencies, so a test that lists a module and leaves out a module of
 * this project it depends on runs a set of modules no site has: an
 * example without surface_http builds every tool description and panel
 * in the variant that names no served path, which no site with the
 * example sees. This scans every kernel test class of the module and its
 * submodules, reads the modules it lists (its own and its parents'), and
 * fails for each listed module whose info file names a dependency on
 * `surface` or a `surface_*` module that the list leaves out.
 *
 * A test that is about a module installed without its dependency says so
 * with the class constant MODULES_WITHOUT_DEPENDENCIES set to TRUE, and a
 * comment saying why.
 */
#[Group('surface')]
class KernelTestModulesTest extends UnitTestCase {

  /**
   * The module's root.
   */
  protected const ROOT = __DIR__ . '/../../..';

  /**
   * The class constant a test opts out with.
   */
  public const OPT_OUT = 'MODULES_WITHOUT_DEPENDENCIES';

  /**
   * Tests no kernel test lists a module without a dependency it has here.
   */
  public function testEveryListedModuleHasItsDependencies(): void {
    $dependencies = $this->dependencies();
    $this->assertArrayHasKey('surface_examples', $dependencies, 'The info files were read.');
    $classes = $this->kernelTestClasses();
    $this->assertGreaterThan(100, count($classes), 'The kernel tests were found.');

    $missing = [];
    foreach ($classes as $class) {
      $reflection = new \ReflectionClass($class);
      if ($reflection->isAbstract() || !$reflection->isSubclassOf(KernelTestBase::class)) {
        continue;
      }
      if ($reflection->hasConstant(self::OPT_OUT) && $reflection->getConstant(self::OPT_OUT) === TRUE) {
        continue;
      }
      $listed = static::listedModules($reflection);
      foreach ($listed as $module) {
        foreach ($dependencies[$module] ?? [] as $dependency) {
          if (!in_array($dependency, $listed, TRUE)) {
            $missing[] = sprintf('%s lists %s but not %s, which it depends on.', $class, $module, $dependency);
          }
        }
      }
    }
    $this->assertSame([], $missing);
  }

  /**
   * Reads the project's dependencies of every module in this tree.
   *
   * @return array<string, list<string>>
   *   Each module's dependencies named `surface` or `surface_*`, by
   *   module.
   */
  protected function dependencies(): array {
    $dependencies = [];
    foreach ($this->files('.info.yml') as $file) {
      $module = basename($file, '.info.yml');
      $info = Yaml::decode((string) file_get_contents($file));
      $dependencies[$module] = [];
      foreach ($info['dependencies'] ?? [] as $dependency) {
        // `project:module (constraint)`, the project and the constraint
        // optional.
        $name = explode(' ', explode(':', (string) $dependency)[1] ?? (string) $dependency)[0];
        if ($name === 'surface' || str_starts_with($name, 'surface_')) {
          $dependencies[$module][] = $name;
        }
      }
    }
    return $dependencies;
  }

  /**
   * Finds every kernel test class of the module and its submodules.
   *
   * @return list<class-string>
   *   The classes, read from each file's namespace and class name.
   */
  protected function kernelTestClasses(): array {
    $classes = [];
    foreach ($this->files('.php') as $file) {
      if (!str_contains($file, '/tests/src/Kernel/')) {
        continue;
      }
      $source = (string) file_get_contents($file);
      if (preg_match('/^namespace ([^;]+);/m', $source, $namespace) && preg_match('/^(?:abstract |final )*class (\w+)/m', $source, $class)) {
        $classes[] = $namespace[1] . '\\' . $class[1];
      }
    }
    sort($classes);
    return $classes;
  }

  /**
   * Gives the modules a kernel test enables, its parents' first.
   *
   * KernelTestBase merges `$modules` up the class hierarchy, so a class's
   * list is its own and every parent's that declares one.
   *
   * @param \ReflectionClass $reflection
   *   The test class.
   *
   * @return list<string>
   *   The modules.
   */
  protected static function listedModules(\ReflectionClass $reflection): array {
    $modules = [];
    for ($class = $reflection; $class !== FALSE; $class = $class->getParentClass()) {
      if ($class->hasProperty('modules') && $class->getProperty('modules')->getDeclaringClass()->getName() === $class->getName()) {
        $modules = [...$class->getProperty('modules')->getValue(), ...$modules];
      }
    }
    return array_values(array_unique($modules));
  }

  /**
   * Lists the files in this tree whose names end in a suffix.
   *
   * @param string $suffix
   *   The suffix.
   *
   * @return list<string>
   *   The paths, no file under node_modules among them.
   */
  protected function files(string $suffix): array {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
      new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS),
      static fn (\SplFileInfo $file): bool => $file->getFilename() !== 'node_modules' && $file->getFilename() !== '.git',
    ));
    $files = [];
    foreach ($iterator as $file) {
      if (str_ends_with($file->getFilename(), $suffix)) {
        $files[] = $file->getPathname();
      }
    }
    return $files;
  }

}

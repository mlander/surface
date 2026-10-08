<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds surfaces, alters and situations in every enabled module.
 *
 * The precedent is core's HookCollectorPass, which scans src/Hook: this
 * scans src/Surface and src/SurfaceAlter, recursively, in every module
 * the container is being built for, so nothing is registered by hand and
 * a module that is switched off stops contributing the moment the
 * container is rebuilt without it.
 *
 * What it keeps is deliberately small: which classes carry #[Surface],
 * which carry #[AltersSurface], and which have a #[Situation] method,
 * each with the module it came from. The reading of every attribute on
 * those classes — labels, identity, refiners, watched keys — is the
 * registry's, at run time, and cached the way plugin definitions are,
 * because a situation's label is translatable markup and a container
 * parameter holds scalars only.
 *
 * Classes that must be built with services are registered as autowired
 * services here, keyed by class name, the way a hook class is: every
 * alter, and every target and access class a discovered surface names.
 * A surface itself is not registered. It has no constructor and holds no
 * service, and the class resolver makes one when asked.
 *
 * Nothing is refused here. A defect in one module's surface is reported
 * when that surface is built, naming the offender, rather than by a
 * container that will not compile for any page of the site.
 *
 * @see \Drupal\Core\Hook\HookCollectorPass
 * @see \Drupal\data_surface\SurfaceBuild\SurfaceRegistry
 */
final class SurfaceCollectorPass implements CompilerPassInterface {

  /**
   * The container parameter the discovered class lists are written to.
   */
  public const PARAMETER = 'data_surface.surface_classes';

  /**
   * The directories scanned in each module, relative to its src/.
   */
  public const DIRECTORIES = ['Surface', 'SurfaceAlter'];

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    $root = $container->hasParameter('app.root') ? $container->getParameter('app.root') . '/' : '';
    $found = ['surfaces' => [], 'alters' => [], 'situations' => []];
    foreach ($container->getParameter('container.modules') as $module => $info) {
      $module_dir = $root . dirname($info['pathname']);
      foreach (self::DIRECTORIES as $directory) {
        foreach (self::classesIn($module_dir . '/src/' . $directory, 'Drupal\\' . $module . '\\' . $directory) as $class) {
          $this->collect($container, new \ReflectionClass($class), (string) $module, $found);
        }
      }
    }
    $container->setParameter(self::PARAMETER, $found);
  }

  /**
   * Records one class, and registers the services it brings.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   *   The container being built.
   * @param \ReflectionClass $class
   *   The class.
   * @param string $module
   *   The module it was found in.
   * @param array<string, array<class-string, string>> $found
   *   The class lists, by kind, then class name, valued with the module.
   */
  protected function collect(ContainerBuilder $container, \ReflectionClass $class, string $module, array &$found): void {
    if (!$class->isInstantiable()) {
      return;
    }
    $name = $class->getName();
    foreach ($class->getAttributes(Surface::class) as $attribute) {
      $found['surfaces'][$name] = $module;
      $surface = $attribute->newInstance();
      foreach ([$surface->target, $surface->access] as $named) {
        if ($named !== NULL) {
          self::registerAutowired($container, $named);
        }
      }
    }
    if ($class->getAttributes(AltersSurface::class) !== []) {
      $found['alters'][$name] = $module;
      self::registerAutowired($container, $name);
    }
    foreach ($class->getMethods() as $method) {
      if ($method->getAttributes(Situation::class) !== []) {
        $found['situations'][$name] = $module;
        break;
      }
    }
  }

  /**
   * Registers a class as an autowired service under its own name.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   *   The container being built.
   * @param string $class
   *   The class.
   */
  protected static function registerAutowired(ContainerBuilder $container, string $class): void {
    if (class_exists($class) && !$container->hasDefinition($class)) {
      $container->register($class, $class)->setAutowired(TRUE);
    }
  }

  /**
   * Lists the classes in one directory, by the PSR-4 rule.
   *
   * @param string $directory
   *   The directory, which need not exist.
   * @param string $namespace
   *   The namespace the directory maps to.
   *
   * @return class-string[]
   *   The classes whose files are there and that load.
   */
  protected static function classesIn(string $directory, string $namespace): array {
    if (!is_dir($directory)) {
      return [];
    }
    $classes = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
      $directory,
      \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
    ));
    foreach ($files as $file) {
      assert($file instanceof \SplFileInfo);
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
      $class = $namespace . '\\' . str_replace('/', '\\', $relative);
      if (class_exists($class)) {
        $classes[] = $class;
      }
    }
    // Directory order is the filesystem's; sorting makes the order alters
    // and situations are applied in the same on every machine.
    sort($classes);
    return $classes;
  }

}

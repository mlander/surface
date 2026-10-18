<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\DerivesResources;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds resources, structures, alters and operations in every module.
 *
 * The precedent is core's HookCollectorPass, which scans src/Hook: this
 * scans src/Resource, src/Structure and src/Alter, recursively, in every
 * module the container is being built for, so nothing is registered by
 * hand and a module that is switched off stops contributing the moment
 * the container is rebuilt without it. The attribute decides the kind,
 * not the directory: a class carrying #[Resource] or #[Structure] is
 * found in either of the first two, which are read as one list, sorted
 * by the class's name below its directory.
 *
 * What it keeps is deliberately small: which classes carry #[Resource]
 * or #[Structure], which carry #[Alters], which carry #[DerivesResources],
 * and which have an #[Operation] method, each with the module it came
 * from; and, for each deriver whose attribute names an override
 * attribute, the classes in any src/Resource or src/Structure carrying
 * that attribute. The reading of every attribute on those classes
 * (labels, kind, identity, refiners, watched keys) is the registry's, at
 * run time, and cached the way plugin definitions are, because an
 * operation's label is translatable markup and a container parameter
 * holds scalars only.
 *
 * Classes that must be built with services are registered as autowired
 * services here, keyed by class name, the way a hook class is: every
 * alter and every deriver, and every storage and access class a
 * discovered resource or structure names. A derived resource's storage
 * and access class are named at run time, so the class resolver builds
 * them. A resource or a structure itself is not registered and never
 * instantiated: its defineInputs() and its refiners are static, called
 * on the class.
 *
 * A plugin that is its own structure (#[UsesStructure] with no argument)
 * is not found here: it lives where its plugin type says, and the
 * registry reads it from the plugin definitions, which already list it.
 *
 * Nothing is refused here. A defect in one module's resource is reported
 * when discovery is read or when that resource is built, naming the
 * offender, rather than by a container that will not compile for any
 * page of the site.
 *
 * @see \Drupal\Core\Hook\HookCollectorPass
 * @see \Drupal\data_surface\ResourceBuild\ResourceRegistry
 */
final class ResourceCollectorPass implements CompilerPassInterface {

  /**
   * The container parameter the discovered class lists are written to.
   */
  public const PARAMETER = 'data_surface.resource_classes';

  /**
   * The directories scanned in each module, relative to its src/.
   *
   * The first two hold resources, structures and derivers alike, and are
   * read as one list; the last holds alters.
   */
  public const DIRECTORIES = ['Resource', 'Structure', 'Alter'];

  /**
   * The directories of resources and structures, read as one list.
   */
  protected const DECLARATIONS = ['Resource', 'Structure'];

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    $root = $container->hasParameter('app.root') ? $container->getParameter('app.root') . '/' : '';
    $found = ['resources' => [], 'alters' => [], 'operations' => [], 'derivers' => [], 'overrides' => []];
    $scanned = [];
    foreach ($container->getParameter('container.modules') as $module => $info) {
      $module_dir = $root . dirname($info['pathname']);
      $lists = [];
      foreach (self::DIRECTORIES as $directory) {
        $classes = self::classesIn($module_dir . '/src/' . $directory, 'Drupal\\' . $module . '\\' . $directory);
        $declares = in_array($directory, self::DECLARATIONS, TRUE);
        $lists[$declares ? 'declarations' : $directory] = array_merge($lists[$declares ? 'declarations' : $directory] ?? [], array_fill_keys($classes, $declares));
      }
      // Both declaring directories as one list, in the order a class's name
      // below its directory gives, whichever directory it is in.
      $prefix = strlen('Drupal\\' . $module . '\\');
      uksort($lists['declarations'], static fn (string $a, string $b): int => strcmp(
        (string) strstr(substr($a, $prefix), '\\'),
        (string) strstr(substr($b, $prefix), '\\'),
      ));
      foreach ($lists as $list) {
        foreach ($list as $class => $declares) {
          $reflection = new \ReflectionClass($class);
          $this->collect($container, $reflection, (string) $module, $found);
          if ($declares) {
            $scanned[$class] = [(string) $module, $reflection];
          }
        }
      }
    }
    // A deriver's overrides may live in a module scanned after its own.
    foreach (array_keys($found['derivers']) as $deriver) {
      $attribute = (new \ReflectionClass($deriver))->getAttributes(DerivesResources::class)[0]->newInstance();
      foreach ($attribute->overrides === NULL ? [] : $scanned as $class => [$module, $reflection]) {
        if ($reflection->getAttributes($attribute->overrides) !== []) {
          $found['overrides'][$deriver][$class] = $module;
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
    foreach ([...$class->getAttributes(Resource::class), ...$class->getAttributes(Structure::class)] as $attribute) {
      $found['resources'][$name] = $module;
      $declared = $attribute->newInstance();
      foreach ([$declared instanceof Resource ? $declared->storage : NULL, $declared->access] as $named) {
        if ($named !== NULL) {
          self::registerAutowired($container, $named);
        }
      }
    }
    if ($class->getAttributes(Alters::class) !== []) {
      $found['alters'][$name] = $module;
      self::registerAutowired($container, $name);
    }
    if ($class->getAttributes(DerivesResources::class) !== []) {
      $found['derivers'][$name] = $module;
      self::registerAutowired($container, $name);
    }
    foreach ($class->getMethods() as $method) {
      if ($method->getAttributes(Operation::class) !== []) {
        $found['operations'][$name] = $module;
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
    // and operations are applied in the same on every machine.
    sort($classes);
    return $classes;
  }

}

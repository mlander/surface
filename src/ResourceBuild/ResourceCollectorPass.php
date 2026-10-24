<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\DerivesResources;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\Attribute\Structure;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds resources, structures, shapes, alters and operations.
 *
 * The precedent is core's HookCollectorPass, which scans src/Hook: this
 * scans src/Resource, src/Structure, src/Shape and src/Alter,
 * recursively, in every module the container is being built for, so
 * nothing is registered by hand and a module that is switched off stops
 * contributing the moment the container is rebuilt without it. The
 * attribute decides the kind, not the directory: a class carrying
 * #[Resource] or #[Structure] is found in either of the first two, which
 * are read as one list, sorted by the class's name below its directory,
 * and one carrying #[Shape] wherever it is scanned.
 *
 * What it keeps is deliberately small: which classes carry #[Resource]
 * or #[Structure], which carry #[Shape], which carry #[Alters], which
 * carry #[DerivesResources], and which carry #[Operation] on the class
 * or on a method, each with the module it came from; and, for each
 * deriver whose attribute names an override attribute, the classes in
 * any src/Resource or src/Structure carrying that attribute. The reading
 * of every attribute on those classes (labels, kind, identity, refiners,
 * watched keys) is the registry's, at run time, and cached the way
 * plugin definitions are, because an operation's label is translatable
 * markup and a container parameter holds scalars only.
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
   * The interface removed on 2026-10-24 through which an alter added outputs.
   */
  public const ALTERS_OUTPUTS = 'Drupal\\data_surface\\Resource\\AltersOutputsInterface';

  /**
   * The container parameter the discovered class lists are written to.
   */
  public const PARAMETER = 'data_surface.resource_classes';

  /**
   * The directories scanned in each module, relative to its src/.
   *
   * The first two hold resources, structures and derivers alike, and are
   * read as one list; the third holds shapes, the last alters.
   */
  public const DIRECTORIES = ['Resource', 'Structure', 'Shape', 'Alter'];

  /**
   * The directories of resources and structures, read as one list.
   */
  protected const DECLARATIONS = ['Resource', 'Structure'];

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    $root = $container->hasParameter('app.root') ? $container->getParameter('app.root') . '/' : '';
    $container->setParameter(self::PARAMETER, self::discover($container->getParameter('container.modules'), $root, $container));
  }

  /**
   * Scans modules for the classes discovery keeps, as the container is built.
   *
   * Also asked, with no container, for modules that are not installed:
   * the config schema generator reads a module's resources before the
   * module is installed (ConfigSchemaGenerator::including()), so its
   * schema and install file are there when it is.
   *
   * @param array<string, array{pathname: string}> $modules
   *   The modules, in the order they are scanned, each with the path of
   *   its info file from the root.
   * @param string $root
   *   The root the paths are relative to, with a trailing slash; empty
   *   for paths that are already whole.
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder|null $container
   *   The container being built, which the autowired services are
   *   registered in; NULL to register none.
   *
   * @return array<string, array<class-string, mixed>>
   *   The class lists, by kind, then class name, valued with the module.
   */
  public static function discover(array $modules, string $root, ?ContainerBuilder $container = NULL): array {
    $found = array_fill_keys(['resources', 'shapes', 'alters', 'operations', 'derivers', 'overrides'], []);
    $scanned = [];
    foreach ($modules as $module => $info) {
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
          $reflection = self::reflect($class);
          self::collect($container, $reflection, (string) $module, $found);
          if ($declares) {
            $scanned[$class] = [(string) $module, $reflection];
          }
        }
      }
    }
    // A deriver's overrides may live in a module scanned after its own.
    foreach (array_keys($found['derivers']) as $deriver) {
      $attribute = (new \ReflectionClass($deriver))->getAttributes(DerivesResources::class)[0]->newInstance();
      foreach ($attribute->overriddenBy === NULL ? [] : $scanned as $class => [$module, $reflection]) {
        if ($reflection->getAttributes($attribute->overriddenBy) !== []) {
          $found['overrides'][$deriver][$class] = $module;
        }
      }
    }
    return $found;
  }

  /**
   * Reflects a class found in a scanned directory.
   *
   * An alter that still implements AltersOutputsInterface, removed on
   * 2026-10-24, cannot be loaded at all: it is refused by name, with what
   * replaced it, rather than with PHP's missing interface.
   *
   * Decision: see docs/decisions.md#the-surface-was-counted-and-cut.
   *
   * @param class-string $class
   *   The class.
   *
   * @return \ReflectionClass
   *   Its reflection.
   *
   * @throws \LogicException
   *   When it implements the removed interface.
   */
  public static function reflect(string $class): \ReflectionClass {
    try {
      // Loading the class is what fails, before it can be reflected.
      class_exists($class);
    }
    catch (\Error $e) {
      if (str_contains($e->getMessage(), self::ALTERS_OUTPUTS)) {
        throw self::outputsRefused($class);
      }
      throw $e;
    }
    return new \ReflectionClass($class);
  }

  /**
   * Says why an alter that adds outputs is refused.
   *
   * @param string $class
   *   The alter.
   *
   * @return \LogicException
   *   The refusal.
   */
  public static function outputsRefused(string $class): \LogicException {
    return new \LogicException(sprintf(
      '%s adds outputs (%s, alterOutputs()), which an alter no longer may since 2026-10-24: what a call answers is its operation\'s outputs, declared by the resource or structure that owns it, and a reaction to a save belongs to ECA.',
      $class,
      self::ALTERS_OUTPUTS,
    ));
  }

  /**
   * Records one class, and registers the services it brings.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder|null $container
   *   The container being built; NULL to register nothing.
   * @param \ReflectionClass $class
   *   The class.
   * @param string $module
   *   The module it was found in.
   * @param array<string, array<class-string, string>> $found
   *   The class lists, by kind, then class name, valued with the module.
   */
  protected static function collect(?ContainerBuilder $container, \ReflectionClass $class, string $module, array &$found): void {
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
    if ($class->getAttributes(Shape::class) !== []) {
      $found['shapes'][$name] = $module;
    }
    if ($class->getAttributes(Alters::class) !== []) {
      $found['alters'][$name] = $module;
      self::registerAutowired($container, $name);
    }
    if ($class->getAttributes(DerivesResources::class) !== []) {
      $found['derivers'][$name] = $module;
      self::registerAutowired($container, $name);
    }
    if ($class->getAttributes(Operation::class) !== []) {
      $found['operations'][$name] = $module;
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
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder|null $container
   *   The container being built; NULL to register nothing.
   * @param string $class
   *   The class.
   */
  protected static function registerAutowired(?ContainerBuilder $container, string $class): void {
    if ($container !== NULL && class_exists($class) && !$container->hasDefinition($class)) {
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

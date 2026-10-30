<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators\Resource;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\surface\Contract\ConfigSchemaDocument;
use Drupal\surface\Contract\SchemaReader;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\Variants\ComponentProps;

/**
 * Builds what the resource generator writes, from one of its three sources.
 *
 * Each source is built into a sealed data surface before anything is
 * written, by the build step every resource goes through: the answers of
 * the interview declared as the generated class declares them
 * (KeyAnswers), a config schema entry written as JSON Schema
 * (ConfigSchemaDocument::document()) and read (SchemaReader), or a JSON
 * Schema document read as it is. The generated class is written from the
 * built keys (ResourceCode), and the config schema entry and install file
 * from the same build by the config schema generator's own mapping.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 */
final class ResourceSources {

  /**
   * The keys a config object holds that no resource declares.
   */
  protected const OWNED = ['_core', 'langcode'];

  /**
   * Constructs a ResourceSources.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   The registry, which a source is registered with as a structure read
   *   from it.
   * @param \Drupal\surface\ResourceBuild\Resources $resources
   *   The build step.
   * @param \Drupal\surface\Contract\SchemaReader $reader
   *   The schema reader.
   * @param \Drupal\surface\Contract\ConfigSchemaDocument $documents
   *   Writes a config schema entry as JSON Schema.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for what a config object holds now.
   * @param \Drupal\Core\Extension\ModuleExtensionList $modules
   *   The module list, for a module's install files.
   * @param string $root
   *   The Drupal root.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly Resources $resources,
    protected readonly SchemaReader $reader,
    protected readonly ConfigSchemaDocument $documents,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly ModuleExtensionList $modules,
    protected readonly string $root,
  ) {}

  /**
   * Builds the keys an interview answered.
   *
   * @param string $id
   *   The resource's id.
   * @param string $module
   *   The module it is written in.
   * @param array<int, array<string, mixed>> $keys
   *   The keys, as KeyAnswers reads them.
   *
   * @return \Drupal\surface\Drush\Generators\Resource\BuiltSource
   *   What was built.
   */
  public function fromAnswers(string $id, string $module, array $keys): BuiltSource {
    $inputs = new ResourceCallable(KeyAnswers::class, 'fill', [$keys]);
    $name = $this->registry->defineRead($id, $module, $inputs, hash('xxh128', serialize([$id, $keys])));
    return $this->built($name, 'the interview');
  }

  /**
   * Builds a config object's keys from its config schema entry.
   *
   * Each key's default is what the object holds now on the site, or,
   * where the site has no such object, what its module's install file
   * gives it.
   *
   * @param string $id
   *   The resource's id.
   * @param string $module
   *   The module it is written in.
   * @param string $config
   *   The config object, whose schema entry is read.
   *
   * @return \Drupal\surface\Drush\Generators\Resource\BuiltSource
   *   What was built, with what the schema states that is not written.
   *
   * @throws \InvalidArgumentException
   *   When the site has no config schema of that name.
   */
  public function fromConfigSchema(string $id, string $module, string $config): BuiltSource {
    $defaults = $this->currentValues($config);
    $left = [];
    $document = $this->documents->document($config, $defaults, $left);
    $name = $this->reader->structure($document, $id, $module);
    return $this->built($name, sprintf('the config schema of %s', $config), $this->reader->notes($document), $left);
  }

  /**
   * Builds a JSON Schema document's keys.
   *
   * The file is JSON, or YAML; a single-directory component's
   * `*.component.yml` gives its props, written as a document
   * (ComponentProps::props()).
   *
   * @param string $id
   *   The resource's id.
   * @param string $module
   *   The module it is written in.
   * @param string $file
   *   The document's path.
   *
   * @return \Drupal\surface\Drush\Generators\Resource\BuiltSource
   *   What was built.
   *
   * @throws \InvalidArgumentException
   *   When the file cannot be read as a document, or the reader refuses it.
   */
  public function fromJsonSchema(string $id, string $module, string $file): BuiltSource {
    $document = static::document($file);
    $name = $this->reader->structure($document, $id, $module);
    return $this->built($name, sprintf('the JSON Schema %s', basename($file)), $this->reader->notes($document));
  }

  /**
   * Gives the label a config schema entry gives its object.
   *
   * @param string $config
   *   The entry.
   *
   * @return string|null
   *   Its label; NULL for none.
   */
  public function configLabel(string $config): ?string {
    return $this->documents->label($config);
  }

  /**
   * Reads a JSON Schema document from a file.
   *
   * @param string $file
   *   The path.
   *
   * @return array
   *   The document, objects as arrays.
   *
   * @throws \InvalidArgumentException
   *   When it is not there or holds no object.
   */
  public static function document(string $file): array {
    if (!is_file($file)) {
      throw new \InvalidArgumentException(sprintf('No file %s to read a JSON Schema from.', $file));
    }
    $text = (string) file_get_contents($file);
    $decoded = preg_match('/\.ya?ml$/', $file) ? Yaml::decode($text) : json_decode($text, TRUE);
    if (!is_array($decoded)) {
      throw new \InvalidArgumentException(sprintf('%s holds no JSON Schema object.', $file));
    }
    if (str_ends_with($file, '.component.yml')) {
      $decoded = ComponentProps::props(is_array($decoded['props'] ?? NULL) ? $decoded['props'] : []);
    }
    return $decoded;
  }

  /**
   * Gives what a config object holds now, or its module installs it with.
   *
   * @param string $config
   *   The object.
   *
   * @return array
   *   Its values, less what its storage owns; empty when neither the site
   *   nor the module has any.
   */
  public function currentValues(string $config): array {
    $values = $this->configFactory->get($config)->getRawData();
    if ($values === []) {
      $provider = explode('.', $config, 2)[0];
      foreach (['install', 'optional'] as $directory) {
        $file = $this->modules->exists($provider) ? $this->root . '/' . $this->modules->getPath($provider) . '/config/' . $directory . '/' . $config . '.yml' : '';
        if ($file !== '' && is_file($file)) {
          $values = (array) Yaml::decode((string) file_get_contents($file));
          break;
        }
      }
    }
    return array_diff_key($values, array_flip(self::OWNED));
  }

  /**
   * Builds a registered structure as declared.
   *
   * @param string $name
   *   Its name.
   * @param string $source
   *   What it was read from, for the generated docblock.
   * @param array<string, string> $notes
   *   What the reader noted.
   * @param array $left
   *   What a config schema states that the document does not.
   *
   * @return \Drupal\surface\Drush\Generators\Resource\BuiltSource
   *   What was built.
   */
  protected function built(string $name, string $source, array $notes = [], array $left = []): BuiltSource {
    return new BuiltSource(
      $this->resources->declaration($name, new OperationContext('configure')),
      $this->registry->getDefinition($name),
      $source,
      $notes,
      $left,
    );
  }

}

<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Generators;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\surface\Drush\Generators\Resource\BuiltSource;
use Drupal\surface\Drush\Generators\Resource\KeyAnswers;
use Drupal\surface\Drush\Generators\Resource\PhpLiteral;
use Drupal\surface\Drush\Generators\Resource\ResourceCode;
use Drupal\surface\Drush\Generators\Resource\ResourceSources;
use Drupal\surface\Drush\Generators\Resource\RewriteResolver;
use Drupal\surface\Drush\Generators\Resource\SchemaEntryResolver;
use Drupal\surface\Drush\Generators\Resource\YamlEntryResolver;
use Drupal\surface\Storage\ConfigSchemaGenerator;
use DrupalCodeGenerator\Asset\AssetCollection;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\Exception\RuntimeException;
use DrupalCodeGenerator\GeneratorType;
use DrupalCodeGenerator\InputOutput\Interviewer;
use DrupalCodeGenerator\Utils;
use DrupalCodeGenerator\Validator\RequiredMachineName;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * Writes the files of a settings page: `drush generate surface:resource`.
 *
 * What "Your first resource" lists, from one interview: the permission and
 * the route, each added to the module's file when there is one (never
 * replacing an entry of the same name), the resource class, its storage
 * naming the config object, and the object's config schema entry and
 * install file. The keys come from one of three sources: the interview,
 * asking each key in turn; an existing config schema entry on the site,
 * `--from-config-schema=<name>`; or a JSON Schema document,
 * `--from-json-schema=<file>`. Each source is built first, by the build
 * step every resource goes through (ResourceSources), the class written
 * from the built keys (ResourceCode), and the schema entry and install
 * file from the same build by the config schema generator's mapping.
 * Once the files are in the module's own directory, that generator reads
 * the written class back, installed or not, and the two are compared.
 *
 * Decision: see
 * docs/decisions.md#the-generator-writes-what-the-first-page-shows.
 *
 * @see docs/generating.md
 */
#[Generator(
  name: 'surface:resource',
  description: 'Generates a settings page: a resource, its storage, config schema, install file, permission and route.',
  templatePath: __DIR__ . '/templates',
  type: GeneratorType::MODULE_COMPONENT,
)]
final class ResourceGenerator extends BaseGenerator {

  /**
   * The option that reads the keys from a config schema entry.
   */
  public const FROM_CONFIG_SCHEMA = 'from-config-schema';

  /**
   * The option that reads the keys from a JSON Schema document.
   */
  public const FROM_JSON_SCHEMA = 'from-json-schema';

  /**
   * The types a key of the interview may be, by answer.
   */
  protected const TYPES = [
    'string' => 'string',
    'integer' => 'integer',
    'boolean' => 'boolean',
    'email' => 'email',
    'uri' => 'uri',
    KeyAnswers::CHOICES => 'a list of choices',
    'reference' => 'a reference, entity:<type>',
  ];

  /**
   * What the dump step checks once the files are written.
   *
   * @var array{module: string, schema: string|null, install: string|null}|null
   */
  protected ?array $written = NULL;

  /**
   * Constructs a ResourceGenerator.
   *
   * @param \Drupal\surface\Drush\Generators\Resource\ResourceSources $sources
   *   Builds each source.
   * @param \Drupal\surface\Storage\ConfigSchemaGenerator $schemas
   *   The config schema generator, which writes the schema entry and the
   *   install file and reads the written class back.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   Every module in the code base, installed or not.
   * @param string $root
   *   The Drupal root.
   * @param \Symfony\Component\Console\Input\InputInterface|null $drushInput
   *   Drush's own input, which carries the two source options when the
   *   generator runs through `drush generate` (whose own input names only
   *   the generator's answers); NULL elsewhere.
   */
  public function __construct(
    protected readonly ResourceSources $sources,
    protected readonly ConfigSchemaGenerator $schemas,
    protected readonly ModuleExtensionList $moduleList,
    protected readonly string $root,
    protected readonly ?InputInterface $drushInput = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Creates the generator from the container Drush hands it.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   Drush's container, which delegates to Drupal's.
   *
   * @return static
   *   The generator.
   */
  public static function create(ContainerInterface $container): static {
    $modules = $container->get('extension.list.module');
    return new static(
      new ResourceSources(
        $container->get('surface.resource_registry'),
        $container->get('surface.resources'),
        $container->get('surface.schema_reader'),
        $container->get('surface.config_schema_document'),
        $container->get('config.factory'),
        $modules,
        DRUPAL_ROOT,
      ),
      $container->get('surface.config_schema'),
      $modules,
      DRUPAL_ROOT,
      $container->has('input') ? $container->get('input') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    parent::configure();
    $this->addOption(self::FROM_CONFIG_SCHEMA, NULL, InputOption::VALUE_REQUIRED, 'Read the keys from a config schema entry on the site, such as node.settings.');
    $this->addOption(self::FROM_JSON_SCHEMA, NULL, InputOption::VALUE_REQUIRED, 'Read the keys from a JSON Schema document: a .json or .yml file, or a component\'s .component.yml.');
  }

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, AssetCollection $assets): void {
    $ir = $this->createInterviewer($vars);
    $config_source = $this->sourceOption(self::FROM_CONFIG_SCHEMA);
    $json_source = $this->sourceOption(self::FROM_JSON_SCHEMA);
    if ($config_source !== NULL && $json_source !== NULL) {
      throw new RuntimeException('Name one source for the keys: --from-config-schema or --from-json-schema.');
    }

    $module = $this->askModule();
    $vars['machine_name'] = $module;
    $destination = $this->io()->getInput()->getOption('destination') ?: $this->getDestination($vars);

    $id = $this->ask($ir, 'Resource id', $config_source ?? $module . '.settings', static function (mixed $value): string {
      if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $value)) {
        throw new \UnexpectedValueException('A resource id is dotted lowercase words, such as first.newsletter.');
      }
      return $value;
    });
    $segments = explode('.', $id);
    $last = (string) end($segments);
    $class_default = Utils::camelize($last === 'settings' && count($segments) > 1 ? $segments[count($segments) - 2] . '_' . $last : $last);

    $source = NULL;
    $label_default = Utils::machine2human($last);
    if ($config_source !== NULL) {
      $source = $this->build(fn (): BuiltSource => $this->sources->fromConfigSchema($id, $module, $config_source));
      $label_default = $this->sources->configLabel($config_source) ?? $label_default;
    }
    elseif ($json_source !== NULL) {
      $source = $this->build(fn (): BuiltSource => $this->sources->fromJsonSchema($id, $module, $json_source));
      $document = ResourceSources::document($json_source);
      $label_default = is_string($document['title'] ?? NULL) ? $document['title'] : $label_default;
    }

    $label = $this->ask($ir, 'Label', $label_default);
    $class = $this->ask($ir, 'Class name, before Resource and Storage', $class_default, static function (mixed $value): string {
      if (!is_string($value) || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $value)) {
        throw new \UnexpectedValueException('A class name is UpperCamelCase letters and digits.');
      }
      return $value;
    });

    $operation = $this->choose('Operation', [
      'configure' => 'configure: a settings object, one of its kind',
      'identity' => 'create, update and delete: things an identity key names',
    ], 'configure');
    if ($operation === 'identity') {
      $identity = $this->ask($ir, 'Identity key', 'id', static fn (mixed $value): string => (new RequiredMachineName())($value));
      throw new RuntimeException(sprintf('A resource with identity (%s) is stored in a config entity type, which this generator does not write yet; only a config object is offered. Example 15 of surface_examples shows one written by hand, its identity a title and a machine name: $inputs->compute(\'%s\', \'machine_name\', t(\'Machine name\'), from: [\'label\'], using: MachineName::class, when: Compute::UNLESS_SENT) (docs/recipes.md#a-title-and-a-machine-name).', $identity, $identity));
    }
    $operation_label = $this->ask($ir, 'Operation label', 'Configure the ' . lcfirst($label));
    $operation_description = $this->ask($ir, 'Operation description, the first thing an agent reads about the tool', NULL, static function (mixed $value): string {
      if (!is_string($value) || trim($value) === '') {
        throw new \UnexpectedValueException('Say what the operation does, in a sentence.');
      }
      return $value;
    });

    if ($config_source !== NULL) {
      $config = $config_source;
      $this->io()->text(sprintf('Storage: the config object %s, as its config schema describes it.', $config));
    }
    else {
      $this->io()->text('Storage: a config object. A config entity type, for a resource with identity, is not offered yet.');
      $config = $this->ask($ir, 'Config object', $module . '.' . Utils::camel2machine($class), static function (mixed $value) use ($module): string {
        if (!is_string($value) || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $value) || !ConfigSchemaGenerator::owns($module, $value)) {
          throw new \UnexpectedValueException(sprintf('A config object of %s is named %s.<name>.', $module, $module));
        }
        return $value;
      });
    }

    $permission = $this->ask($ir, 'Permission', 'administer ' . $module);
    $permissions_file = $destination . '/' . $module . '.permissions.yml';
    $declared = is_file($permissions_file) ? (array) Yaml::decode((string) file_get_contents($permissions_file)) : [];
    $permission_title = isset($declared[$permission]) ? NULL : $this->ask($ir, 'Permission title', $operation_label);
    $route = $module . '.' . Utils::camel2machine($class);
    $path = $this->ask($ir, 'Route path', '/admin/config/' . $module . '/' . Utils::camel2machine($class), static function (mixed $value): string {
      if (!is_string($value) || !str_starts_with($value, '/')) {
        throw new \UnexpectedValueException('A route path starts with a slash.');
      }
      return $value;
    });

    $install = [];
    if ($source === NULL) {
      [$keys, $install] = $this->askKeys($ir);
      $source = $this->build(fn (): BuiltSource => $this->sources->fromAnswers($id, $module, $keys));
    }

    $code = (new ResourceCode($module, $id, $source->left))->write($source->built);
    foreach ($source->notes as $pointer => $note) {
      $this->io()->text(sprintf('Read from %s, %s: %s', $source->source, $pointer === '' ? '/' : $pointer, $note));
    }
    if ($code['comments'] > 0) {
      $this->io()->note(sprintf('%s in the class %s what was not written from %s: each says what to write by hand.', $code['comments'] === 1 ? 'A comment' : $code['comments'] . ' comments', $code['comments'] === 1 ? 'names' : 'name', $source->source));
    }

    $owned = ConfigSchemaGenerator::owns($module, $config);
    $uses = [
      'Drupal\Core\StringTranslation\TranslatableMarkup' => TRUE,
      'Drupal\surface\Resource\Attribute\Operation' => TRUE,
      'Drupal\surface\Resource\Attribute\Resource' => TRUE,
      'Drupal\surface\Resource\BlueprintInterface' => TRUE,
      'Drupal\surface\Resource\ResourceInterface' => TRUE,
      'Drupal\\' . $module . '\Storage\\' . $class . 'Storage' => TRUE,
    ] + array_fill_keys($code['uses'], TRUE);
    $uses = array_keys($uses);
    usort($uses, 'strcmp');
    $about = lcfirst($label);
    $summary = [sprintf('The %s: a form, a tool and a schema, from this class.', $about)];
    if ($config_source !== NULL || $json_source !== NULL) {
      $written_from = sprintf('Written by drush generate surface:resource from %s; a comment in defineInputs() names each thing it could not write.', $source->source);
      $summary = [...$summary, '', ...static::wrapped($written_from)];
    }
    $storage_summary = $owned
      ? static::wrapped(sprintf('Keeps the %s in %s config object.', $about, str_ends_with($label, 's') ? 'their' : 'its'))
      : static::wrapped(sprintf('Keeps the %s in %s, which %s\'s config schema describes and this resource is held to.', $about, $config, explode('.', $config, 2)[0]));
    $vars['r'] = (object) [
      'module' => $module,
      'uses' => $uses,
      'summary' => static::wrappedAll($summary),
      'id_literal' => PhpLiteral::string($id),
      'label_literal' => PhpLiteral::string($label),
      'storage_class' => $class . 'Storage',
      'resource_class' => $class . 'Resource',
      'operation_label_literal' => PhpLiteral::string($operation_label),
      'operation_description_literal' => PhpLiteral::string($operation_description),
      'permission_literal' => PhpLiteral::string($permission),
      'constants' => $code['constants'],
      'inputs' => $code['inputs'],
      'storage_summary' => $storage_summary,
      'config_literal' => PhpLiteral::string($config),
      'always_held' => $code['always_held'] === [] ? '' : PhpLiteral::write($code['always_held'], 2),
      'route_name' => $route,
      'path_yaml' => static::yamlString($path),
      'title_yaml' => static::yamlString($label),
      'resource_fqcn_yaml' => static::yamlString('Drupal\\' . $module . '\Resource\\' . $class . 'Resource'),
      'permission_yaml' => preg_match('/^[a-z][a-z0-9 _-]*$/', $permission) ? $permission : static::yamlString($permission),
      'permission_title_yaml' => static::yamlString((string) $permission_title),
    ];

    $io = $this->io();
    $assets->addFile($module . '.permissions.yml', 'permissions.yml.twig')
      ->resolver(new YamlEntryResolver($permission, 'permission', $io));
    $assets->addFile($module . '.routing.yml', 'routing.yml.twig')
      ->resolver(new YamlEntryResolver($route, 'route', $io));
    $assets->addFile('src/Resource/' . $class . 'Resource.php', 'resource.php.twig');
    $assets->addFile('src/Storage/' . $class . 'Storage.php', 'storage.php.twig');
    foreach ($code['structures'] as $structure) {
      $structure_uses = [
        'Drupal\surface\Resource\Attribute\Structure',
        'Drupal\surface\Resource\BlueprintInterface',
        'Drupal\surface\Resource\StructureInterface',
        ...$structure['uses'],
      ];
      usort($structure_uses, 'strcmp');
      $assets->addFile('src/Structure/' . $structure['class'] . '.php', 'structure.php.twig')
        ->vars([
          's' => (object) [
            'class' => $structure['class'],
            'uses' => array_values(array_unique($structure_uses)),
            'summary' => static::wrapped(sprintf('%s, a part of the %s, written from %s.', $structure['label'], $about, $source->source)),
            'id_literal' => PhpLiteral::string($structure['id']),
            'constants' => $structure['constants'],
            'inputs' => $structure['inputs'],
          ],
        ]);
    }

    $this->written = ['module' => $module, 'schema' => NULL, 'install' => NULL];
    if (!$owned) {
      $this->io()->text(sprintf('No config schema entry and no install file: %s is %s\'s, whose schema describes it.', $config, explode('.', $config, 2)[0]));
      return;
    }
    $schema_path = 'config/schema/' . $module . '.schema.yml';
    $install_path = 'config/install/' . $config . '.yml';
    $schema_file = $destination . '/' . $schema_path;
    $install_file = $destination . '/' . $install_path;
    $entries = is_file($schema_file) ? (array) Yaml::decode((string) file_get_contents($schema_file)) : [];
    $entries[$config]['label'] = $label;
    $installed = is_file($install_file) ? (array) Yaml::decode((string) file_get_contents($install_file)) : [];
    $described = $this->schemas->describeBuilt($source->built, $source->owner, $config, $code['always_held'], $entries, $installed + $install);
    $by_name = [];
    foreach ($described['entries'] as $entry) {
      if ($entry['module'] === $module) {
        $by_name[$entry['name']] = $entry;
      }
    }
    $assets->addFile($schema_path)
      ->content(ConfigSchemaGenerator::merge('', $by_name))
      ->resolver(new SchemaEntryResolver($by_name, $io));
    $assets->addFile($install_path)
      ->content(ConfigSchemaGenerator::installFile($described['install']))
      ->resolver(new RewriteResolver($io));
    $this->written = ['module' => $module, 'schema' => $schema_path, 'install' => $install_path];
    $this->io()->text(sprintf('Config schema entry and install file: written from a build of %s, by the mapping drush surface:config-schema uses.', $source->source));
  }

  /**
   * {@inheritdoc}
   *
   * Once the files are in the module's own directory, the config schema
   * generator reads the module from its code, installed or not, builds
   * the class just written, and writes the schema entry and install file
   * from it; where they differ from what was written from the source,
   * the class's are written, and a note says so.
   */
  protected function dump(AssetCollection $assets, string $destination): AssetCollection {
    $dumped = parent::dump($assets, $destination);
    $written = $this->written;
    $this->written = NULL;
    if ($written === NULL || $written['schema'] === NULL || $written['install'] === NULL) {
      return $dumped;
    }
    if ($this->io()->getInput()->getOption('dry-run')) {
      $this->io()->text('Not checked against the class: a dry run writes no class to build.');
      return $dumped;
    }
    $module = $written['module'];
    $directory = $this->root . '/' . $this->moduleList->getPath($module);
    if (realpath($destination) === FALSE || realpath($destination) !== realpath($directory)) {
      $this->io()->text(sprintf('Not checked against the class: the files are not in %s\'s directory. Run drush surface:config-schema --module=%s once they are.', $module, $module));
      return $dumped;
    }
    try {
      $files = $this->schemas->including([$module], fromCode: TRUE)->files([$module]);
    }
    catch (\Throwable $e) {
      $this->io()->warning(sprintf('The class just written could not be built, so the schema entry and install file stay as written from the source: %s', $e->getMessage()));
      return $dumped;
    }
    $differ = [];
    foreach ([$written['schema'], $written['install']] as $file) {
      $path = $this->moduleList->getPath($module) . '/' . $file;
      $now = (string) @file_get_contents($this->root . '/' . $path);
      if (isset($files[$path]) && $files[$path] !== $now) {
        file_put_contents($this->root . '/' . $path, $files[$path]);
        $differ[] = $file;
      }
    }
    $this->io()->text($differ === []
      ? sprintf('Checked against a build of the class just written (drush surface:config-schema --module=%s): the schema entry and install file agree.', $module)
      : sprintf('Checked against a build of the class just written: %s differed, and %s written from the class.', implode(' and ', $differ), count($differ) === 1 ? 'is' : 'are'));
    return $dumped;
  }

  /**
   * {@inheritdoc}
   */
  protected function printHeader(): void {
    $this->io()->title(sprintf('Welcome to %s generator!', $this->getName()));
  }

  /**
   * {@inheritdoc}
   *
   * None: the module is one the code base has, whose info file stays as
   * it is.
   */
  protected function generateInfoFile(array &$vars, AssetCollection $assets): void {
  }

  /**
   * {@inheritdoc}
   *
   * The module's own directory, wherever it is in the code base: a
   * module that is not installed is in the module list too.
   */
  protected function getDestination(array $vars): string {
    return $this->root . '/' . $this->moduleList->getPath((string) $vars['machine_name']);
  }

  /**
   * Asks for the module, any in the code base, installed or not.
   *
   * @return string
   *   Its machine name.
   */
  protected function askModule(): string {
    $modules = array_keys($this->moduleList->getList());
    $question = new Question('Module machine name');
    $question->setAutocompleterValues($modules);
    $question->setValidator(function (mixed $value): string {
      $value = is_string($value) ? trim($value) : '';
      if ($value === '' || !$this->moduleList->exists($value)) {
        throw new \UnexpectedValueException('Name a module in the code base, installed or not; create one first with drush generate module.');
      }
      return $value;
    });
    return Utils::stripSlashes((string) $this->io()->askQuestion($question));
  }

  /**
   * Asks the keys, one after another.
   *
   * @param \DrupalCodeGenerator\InputOutput\Interviewer $ir
   *   The interviewer.
   *
   * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
   *   The keys, as KeyAnswers reads them, and the value each is installed
   *   with, by key, where one was given.
   */
  protected function askKeys(Interviewer $ir): array {
    $keys = [];
    $install = [];
    while (TRUE) {
      $key = $this->ask($ir, 'Key, its machine name (empty when done)', NULL, static function (mixed $value) use (&$keys): string {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
          throw new \UnexpectedValueException('A key is lowercase letters, digits and underscores.');
        }
        if (in_array($value, array_column($keys, 'key'), TRUE)) {
          throw new \UnexpectedValueException(sprintf('%s is declared already.', $value));
        }
        return $value;
      });
      if ($key === '') {
        break;
      }
      $type = $this->choose('Type', self::TYPES, 'string');
      if ($type === 'reference') {
        $type = 'entity:' . $this->ask($ir, 'Entity type it refers to', 'node');
      }
      $answer = [
        'key' => $key,
        'type' => $type,
        'label' => $this->ask($ir, 'Label', Utils::machine2human($key)),
        'description' => $this->ask($ir, 'Description, under the field (empty for none)', NULL) ?? '',
        'required' => $ir->confirm('Required?', FALSE),
        'max_length' => NULL,
        'min' => NULL,
        'max' => NULL,
        'choices' => [],
        'default' => NULL,
      ];
      $integer = static function (mixed $value): ?int {
        if ($value === NULL || $value === '') {
          return NULL;
        }
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
          throw new \UnexpectedValueException('A whole number, or nothing.');
        }
        return (int) $value;
      };
      if ($type === 'string') {
        $answer['max_length'] = $integer($this->ask($ir, 'Maximum length (empty for none)', NULL, $integer));
      }
      if ($type === 'integer') {
        $answer['min'] = $integer($this->ask($ir, 'Minimum (empty for none)', NULL, $integer));
        $answer['max'] = $integer($this->ask($ir, 'Maximum (empty for none)', NULL, $integer));
      }
      if ($type === KeyAnswers::CHOICES) {
        while (TRUE) {
          $value = $this->ask($ir, 'Choice, the value stored (empty when done)', NULL) ?? '';
          if ($value === '') {
            break;
          }
          $answer['choices'][$value] = $this->ask($ir, 'Choice label', Utils::machine2human($value));
        }
      }
      $typed = $this->typed($answer);
      $answer['default'] = $typed($this->ask($ir, 'Default (empty for none)', NULL, $typed));
      $installed = $typed($this->ask($ir, 'Value it is installed with (empty for none)', $answer['default'] === NULL ? NULL : static::answerOf($answer['default']), $typed));
      if ($installed !== NULL) {
        $install[$key] = $installed;
      }
      $keys[] = $answer;
    }
    return [$keys, $install];
  }

  /**
   * Gives what reads an answer as a value of a key's type.
   *
   * @param array<string, mixed> $answer
   *   The key so far.
   *
   * @return \Closure
   *   Reads an answer, NULL for an empty one, and refuses one of another
   *   type.
   */
  protected function typed(array $answer): \Closure {
    return static function (mixed $value) use ($answer): mixed {
      if ($value === NULL || $value === '' || !is_scalar($value)) {
        return NULL;
      }
      $value = (string) $value;
      return match (TRUE) {
        $answer['type'] === 'integer' => is_numeric($value) && (string) (int) $value === $value ? (int) $value : throw new \UnexpectedValueException('A whole number, or nothing.'),
        $answer['type'] === 'boolean' => match (strtolower($value)) {
          'yes', 'true', '1' => TRUE,
          'no', 'false', '0' => FALSE,
          default => throw new \UnexpectedValueException('yes or no, or nothing.'),
        },
        $answer['type'] === KeyAnswers::CHOICES && !array_key_exists($value, $answer['choices']) => throw new \UnexpectedValueException(sprintf('One of %s, or nothing.', implode(', ', array_keys($answer['choices'])))),
        default => $value,
      };
    };
  }

  /**
   * Writes a value as the answer that gives it.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The answer.
   */
  protected static function answerOf(mixed $value): string {
    return is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
  }

  /**
   * Asks one question.
   *
   * What the interviewer asks, read back as typed: the braces it escapes
   * unescaped, and an empty answer the default, as at a prompt.
   *
   * @param \DrupalCodeGenerator\InputOutput\Interviewer $ir
   *   The interviewer.
   * @param string $question
   *   The question.
   * @param string|null $default
   *   The default; NULL for none.
   * @param callable|null $validator
   *   What checks the answer.
   *
   * @return mixed
   *   The answer.
   */
  protected function ask(Interviewer $ir, string $question, ?string $default = NULL, ?callable $validator = NULL): mixed {
    $wrapped = static function (mixed $value) use ($default, $validator): mixed {
      if (($value === NULL || $value === '') && $default !== NULL) {
        $value = $default;
      }
      if (is_string($value)) {
        $value = Utils::stripSlashes($value);
      }
      return $validator === NULL ? $value : $validator($value);
    };
    $answer = $ir->ask(addcslashes($question, '{}'), $default === NULL ? NULL : addcslashes($default, '{}'), $wrapped);
    return is_string($answer) ? Utils::stripSlashes($answer) : $answer;
  }

  /**
   * Asks for one of a list, by its number or its words.
   *
   * As the interviewer's choice(), and an empty answer the default, as at
   * a prompt, `--answer` included.
   *
   * @param string $question
   *   The question.
   * @param array<string, string> $choices
   *   The words of each choice, by the value it gives.
   * @param string $default
   *   The value the default gives.
   *
   * @return string
   *   The value chosen.
   */
  protected function choose(string $question, array $choices, string $default): string {
    $labels = array_values($choices);
    array_unshift($labels, NULL);
    unset($labels[0]);
    $choice = new ChoiceQuestion($question, $labels, $choices[$default]);
    $validator = $choice->getValidator();
    $choice->setValidator(static fn (mixed $value): mixed => $validator === NULL ? $value : $validator($value === NULL || $value === '' ? $choices[$default] : $value));
    $answer = $this->io()->askQuestion($choice);
    return (string) array_search(Utils::stripSlashes((string) $answer), $choices, TRUE);
  }

  /**
   * Reads a source option, from this command or from Drush's.
   *
   * @param string $name
   *   The option.
   *
   * @return string|null
   *   Its value, NULL when not given.
   */
  protected function sourceOption(string $name): ?string {
    $value = $this->io()->getInput()->getOption($name);
    if (($value === NULL || $value === '') && $this->drushInput !== NULL && $this->drushInput->hasOption($name)) {
      $value = $this->drushInput->getOption($name);
    }
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Builds a source, as a refusal the generator prints.
   *
   * @param callable(): \Drupal\surface\Drush\Generators\Resource\BuiltSource $build
   *   What builds it.
   *
   * @return \Drupal\surface\Drush\Generators\Resource\BuiltSource
   *   What was built.
   */
  protected function build(callable $build): BuiltSource {
    try {
      return $build();
    }
    catch (\InvalidArgumentException | \LogicException $e) {
      throw new RuntimeException($e->getMessage(), previous: $e);
    }
  }

  /**
   * Writes a string as a single-quoted YAML scalar.
   *
   * @param string $value
   *   The string.
   *
   * @return string
   *   The scalar.
   */
  protected static function yamlString(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
  }

  /**
   * Wraps a paragraph to fit a docblock.
   *
   * @param string $text
   *   The paragraph.
   *
   * @return string[]
   *   Its lines.
   */
  protected static function wrapped(string $text): array {
    return explode("\n", wordwrap($text, 77, "\n", FALSE));
  }

  /**
   * Wraps each paragraph of a docblock, an empty line between them kept.
   *
   * @param string[] $paragraphs
   *   The paragraphs; an empty one is a blank line.
   *
   * @return string[]
   *   The lines.
   */
  protected static function wrappedAll(array $paragraphs): array {
    $lines = [];
    foreach ($paragraphs as $paragraph) {
      $lines = [...$lines, ...($paragraph === '' ? [''] : static::wrapped($paragraph))];
    }
    return $lines;
  }

}

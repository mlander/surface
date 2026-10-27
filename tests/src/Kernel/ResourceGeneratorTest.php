<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests drush generate surface:resource, through Drupal Code Generator.
 *
 * ResourceGeneratorTestBase says how it is run.
 *
 * - The newsletter's answers write "Your first resource"'s module byte for
 *   byte: what the page quotes is what the generator writes, and the page
 *   stays the truth this holds the generator to.
 * - A routing or permissions file that is there keeps every entry; the
 *   generated one is added, or left out where one of its name is there.
 * - node.settings and a JSON Schema document (a component's props, and a
 *   fixture with a reference, a list of values, a nested object and a
 *   conditional) write their keys as add() calls, what the reader keeps
 *   as a foreign constraint as a comment naming the keyword and the key.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceGeneratorTest extends ResourceGeneratorTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'data_surface',
  ];

  /**
   * The module the page's files are, from the module root.
   */
  protected const FIRST = __DIR__ . '/../../../modules/data_surface_first';

  /**
   * The answers that write the first page's module.
   */
  public const NEWSLETTER = [
    'data_surface_first',
    'first.newsletter',
    'Newsletter settings',
    // Class name: Newsletter.
    '',
    // Operation: configure.
    '1',
    'Configure the newsletter',
    'Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.',
    // Config object: data_surface_first.newsletter.
    '',
    // Permission: administer data_surface_first.
    '',
    'Configure the newsletter and the store location',
    '/admin/config/services/surface-newsletter',
    'sender_name', '1', 'Sender name', '', 'yes', '64', '', 'The newsletter',
    'sender_email', '4', 'Sender email', '', 'yes', '', 'newsletter@example.com',
    'frequency', '6', 'Frequency', '', 'yes', 'weekly', 'Weekly', 'monthly', 'Monthly', '', 'weekly', '',
    'max_recipients', '2', 'Maximum recipients per send', 'Larger lists are sent in batches of this size.', 'no', '1', '10000', '500', '',
    // No more keys.
    '',
  ];

  /**
   * Gives a shipped file's first block: what precedes its first blank line.
   *
   * @param string $file
   *   The file, from data_surface_first.
   *
   * @return string
   *   The block, with its line end.
   */
  protected static function firstBlock(string $file): string {
    return explode("\n\n", (string) file_get_contents(self::FIRST . '/' . $file), 2)[0] . "\n";
  }

  /**
   * Tests the newsletter's answers write the first page's files, byte for byte.
   *
   * The resource, the storage, the install file and the permissions file
   * whole; the route and the schema entry as the module's first entries,
   * since the module's files hold the second page's too. Then, the files
   * being in the module's directory, the class just written is built and
   * its schema entry and install file agree.
   */
  public function testTheNewsletterIsTheFirstPage(): void {
    $display = $this->generate(self::NEWSLETTER);
    foreach ([
      'src/Resource/NewsletterResource.php',
      'src/Storage/NewsletterStorage.php',
      'config/install/data_surface_first.newsletter.yml',
      'data_surface_first.permissions.yml',
    ] as $file) {
      $this->assertSame((string) file_get_contents(self::FIRST . '/' . $file), $this->written($file), sprintf('%s differs from the shipped module.', $file));
    }
    $this->assertSame(self::firstBlock('data_surface_first.routing.yml'), $this->written('data_surface_first.routing.yml'));
    $this->assertSame(self::firstBlock('config/schema/data_surface_first.schema.yml'), $this->written('config/schema/data_surface_first.schema.yml'));
    $this->assertStringContainsString('Config schema entry and install file: written from a build of the interview', $display);
    $this->assertStringContainsString('Checked against a build of the class just written (drush data_surface:config-schema --module=data_surface_first): the schema entry and install file agree.', $display);
    $this->assertFileDoesNotExist($this->moduleDirectory() . '/data_surface_first.info.yml', 'The module\'s info file is left as it is.');
  }

  /**
   * Tests a routing and a permissions file that are there keep their entries.
   */
  public function testTheYamlFilesAreAddedToNeverReplaced(): void {
    $directory = $this->moduleDirectory();
    (new Filesystem())->mkdir($directory . '/config/schema');
    $store_route = (string) preg_replace('/\A.*?\n\n/s', '', (string) file_get_contents(self::FIRST . '/data_surface_first.routing.yml'));
    $store_schema = (string) preg_replace('/\A.*?\n\n/s', '', (string) file_get_contents(self::FIRST . '/config/schema/data_surface_first.schema.yml'));
    file_put_contents($directory . '/data_surface_first.routing.yml', $store_route);
    file_put_contents($directory . '/config/schema/data_surface_first.schema.yml', $store_schema);
    $permissions = (string) file_get_contents(self::FIRST . '/data_surface_first.permissions.yml');
    file_put_contents($directory . '/data_surface_first.permissions.yml', $permissions);

    // The permission is declared already, so its title is not asked.
    $answers = self::NEWSLETTER;
    array_splice($answers, 9, 1);
    $display = $this->generate($answers);

    $route = self::firstBlock('data_surface_first.routing.yml');
    $this->assertSame($store_route . "\n" . $route, $this->written('data_surface_first.routing.yml'), 'The store route is kept byte for byte, the newsletter route added after a blank line.');
    $this->assertSame($permissions, $this->written('data_surface_first.permissions.yml'), 'The permission that is there is kept.');
    $this->assertStringContainsString('data_surface_first.permissions.yml already declares the permission administer data_surface_first: kept as it is', $display);
    $this->assertStringContainsString('data_surface_first.routing.yml: the route data_surface_first.newsletter added at its end; every other entry kept as it was.', $display);
    $schema = $this->written('config/schema/data_surface_first.schema.yml');
    $this->assertStringStartsWith(rtrim($store_schema, "\n") . "\n\n# Generated from the first.newsletter resource", $schema, 'The store entry is kept, the newsletter entry added.');

    // Again: the route is there now, and is kept.
    $display = $this->generate($answers, ['--replace' => TRUE]);
    $this->assertSame($store_route . "\n" . $route, $this->written('data_surface_first.routing.yml'));
    $this->assertStringContainsString('data_surface_first.routing.yml already declares the route data_surface_first.newsletter: kept as it is', $display);
  }

  /**
   * Tests node.settings writes its key, and no schema or install file.
   */
  public function testTheKeysOfNodeSettings(): void {
    $out = $this->sandbox . '/node_settings';
    $display = $this->generate([
      'data_surface_first',
      // Resource id: node.settings; label: Node settings; class.
      '', '', '',
      '1',
      '',
      'Sets whether the administration theme is used when content is edited or added.',
      '', '', '',
    ], ['--from-config-schema' => 'node.settings'], $out);
    $resource = $this->written('src/Resource/NodeSettingsResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('use_admin_theme', 'boolean', t('Use administration theme when editing or creating content'), default: TRUE)
      ->setRequired(TRUE);
  }
PHP, $resource, 'Required in a fully validatable object, its default the value the site holds; _core and langcode are the storage\'s.');
    $this->assertStringContainsString("#[Resource('node.settings', storage: NodeSettingsStorage::class)]", $resource);
    $this->assertStringContainsString('Written by drush generate surface:resource from the config schema of', $resource);
    $this->assertStringContainsString("public const CONFIG = 'node.settings';", $this->written('src/Storage/NodeSettingsStorage.php', $out));
    $this->assertFileDoesNotExist($out . '/config/schema/data_surface_first.schema.yml', 'node.settings is node\'s: its schema describes it.');
    $this->assertFileDoesNotExist($out . '/config/install/node.settings.yml');
    $this->assertStringContainsString('No config schema entry and no install file: node.settings is node\'s', $display);
    $this->assertParses(['src/Resource/NodeSettingsResource.php', 'src/Storage/NodeSettingsStorage.php'], $out);
  }

  /**
   * Tests a component's props, a draft-04 document, write their keys.
   */
  public function testTheKeysOfComponentProps(): void {
    $out = $this->sandbox . '/card';
    $this->generate([
      'data_surface_first', 'first.card', 'Card', '', '1', '',
      'Sets the card: its heading, its tone and its link.',
      '', '', '', '',
    ], ['--from-json-schema' => dirname(__DIR__, 3) . '/modules/data_surface_examples/components/card/card.component.yml'], $out);
    $resource = $this->written('src/Resource/CardResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add('heading', 'string', t('Heading'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 80]);
    $inputs->add('tone', 'string', t('Tone'), default: 'plain')
      ->addConstraint('LabeledChoice', [
        'choices' => ['plain', 'highlight'],
        'labels' => [
          'plain' => t('Plain'),
          'highlight' => t('Highlighted'),
        ],
      ]);
    $inputs->addDefinition('link', MapDataDefinition::create()
      ->setPropertyDefinition('url', DataDefinition::create('uri')
        ->setLabel(t('Address')))
      ->setPropertyDefinition('label', DataDefinition::create('string')
        ->setLabel(t('Link text'))))
      ->setLabel(t('Link'));
PHP, $resource);
    $this->assertStringContainsString("public const ALWAYS_HELD = ['tone'];", $this->written('src/Storage/CardStorage.php', $out), 'An optional key that refuses null and has a default is one its storage always holds.');
    $schema = $this->written('config/schema/data_surface_first.schema.yml', $out);
    $this->assertStringContainsString("data_surface_first.card:\n  type: config_object\n  label: 'Card'", $schema);
    $this->assertStringContainsString("    tone:\n      type: string\n      label: 'Tone'\n", $schema, 'Always held: not nullable.');
    $this->assertSame("heading: ~\ntone: plain\nlink: ~\n", $this->written('config/install/data_surface_first.card.yml', $out), 'As the schema command writes a map with no default: nothing.');
    $this->assertParses(['src/Resource/CardResource.php', 'src/Storage/CardStorage.php'], $out);
  }

  /**
   * Tests a document with a reference, a list, a nested object and a rule.
   */
  public function testTheKeysOfDocument(): void {
    $out = $this->sandbox . '/event';
    $display = $this->generate([
      'data_surface_first', 'first.event', '', '', '1', '',
      'Sets the event: its name, kind, seats, venue and contact.',
      '', '', '', '',
    ], ['--from-json-schema' => dirname(__DIR__, 2) . '/fixtures/generator/event.schema.json'], $out);
    $resource = $this->written('src/Resource/EventResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add('kind', 'string', t('Kind'), default: 'meetup')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['meetup', 'conference']]);
PHP, $resource, 'An enum is a Choice.');
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add('slug', 'string', t('Slug'))
      ->addConstraint('Regex', ['pattern' => '/^[a-z0-9-]+$/D']);
    $inputs->attach('venue', VenueStructure::class)
      ->setLabel(t('Venue'));
    $inputs->addDefinition('contact', MapDataDefinition::create()
PHP, $resource, 'A $ref to a scalar def is read in place; one to an object def is a structure of its own; a nested object is a map.');
    $this->assertStringContainsString(<<<'PHP'
    // From the schema, not written here: multipleOf on seats, which no
    // constraint here states; the reader keeps it as a JsonSchema constraint.
    // Write a constraint for it.
    $inputs->add('seats', 'integer', t('Seats'))
      ->addConstraint('Range', ['min' => 1]);
PHP, $resource, 'A foreign constraint is a comment naming the keyword and the key.');
    $this->assertStringContainsString(<<<'PHP'
    $inputs->addList('tags', 'string', t('Tags'))
      ->addConstraint('UniqueItems', []);
    $inputs->narrow('seats')
      ->when(Condition::equals('kind', 'conference'))
      ->require();
PHP, $resource, 'uniqueItems is the stock UniqueItems; the if/then is a declared rule.');
    $structure = $this->written('src/Structure/VenueStructure.php', $out);
    $this->assertStringContainsString("#[Structure('first.event.venue')]", $structure);
    $this->assertStringContainsString("    \$inputs->add('city', 'string', t('City'))\n      ->setRequired(TRUE);", $structure);
    $this->assertStringContainsString('A comment in the class names what was not written from the JSON Schema', $display);
    $this->assertStringContainsString('/properties/seats/multipleOf: Kept as a JsonSchema constraint', $display);
    $this->assertParses([
      'src/Resource/EventResource.php',
      'src/Storage/EventStorage.php',
      'src/Structure/VenueStructure.php',
    ], $out);
  }

  /**
   * Tests a resource with identity is refused, naming what is not offered.
   */
  public function testIdentityIsRefusedForNow(): void {
    $out = $this->sandbox . '/identity';
    $display = $this->generate(['data_surface_first', 'first.venue', 'Venue', '', '2', 'id'], [], $out, 1);
    $this->assertStringContainsString('A resource with identity (id) is stored in a config entity type', $display);
    // What to write by hand: the title and machine name pair.
    $this->assertStringContainsString("compute('id', 'machine_name', t('Machine name'), from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)", $display);
    $this->assertDirectoryDoesNotExist($out . '/src');
  }

}

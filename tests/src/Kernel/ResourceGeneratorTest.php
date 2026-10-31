<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Drush\Generators\Resource\ResourceCode;
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
 * - In every mode each key is named once, as a constant of its class in
 *   declaration order, and every call and comment names it by the
 *   constant; keys whose names would clash take a suffix, and every class
 *   written parses.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ResourceGeneratorTest extends ResourceGeneratorTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'surface',
  ];

  /**
   * The module the page's files are, from the module root.
   */
  protected const FIRST = __DIR__ . '/../../../modules/surface_examples_first';

  /**
   * The answers that write the first page's module.
   */
  public const NEWSLETTER = [
    'surface_examples_first',
    'first.newsletter',
    'Newsletter settings',
    // Class name: Newsletter.
    '',
    // Operation: configure.
    '1',
    'Configure the newsletter',
    'Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.',
    // Config object: surface_examples_first.newsletter.
    '',
    // Permission: administer surface_examples_first.
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
   *   The file, from surface_examples_first.
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
      'config/install/surface_examples_first.newsletter.yml',
      'surface_examples_first.permissions.yml',
    ] as $file) {
      $this->assertSame((string) file_get_contents(self::FIRST . '/' . $file), $this->written($file), sprintf('%s differs from the shipped module.', $file));
    }
    $this->assertSame(self::firstBlock('surface_examples_first.routing.yml'), $this->written('surface_examples_first.routing.yml'));
    $this->assertSame(self::firstBlock('config/schema/surface_examples_first.schema.yml'), $this->written('config/schema/surface_examples_first.schema.yml'));
    $this->assertStringContainsString('Config schema entry and install file: written from a build of the interview', $display);
    $this->assertStringContainsString('Checked against a build of the class just written (drush surface:config-schema --module=surface_examples_first): the schema entry and install file agree.', $display);
    $this->assertFileDoesNotExist($this->moduleDirectory() . '/surface_examples_first.info.yml', 'The module\'s info file is left as it is.');
    $this->assertStringContainsString(<<<'PHP'
  public const SENDER_NAME = 'sender_name';
  public const SENDER_EMAIL = 'sender_email';
PHP, $this->written('src/Resource/NewsletterResource.php'), 'The interview names each key once, as a constant.');
    $this->assertParses(['src/Resource/NewsletterResource.php', 'src/Storage/NewsletterStorage.php']);
  }

  /**
   * Tests a routing and a permissions file that are there keep their entries.
   */
  public function testTheYamlFilesAreAddedToNeverReplaced(): void {
    $directory = $this->moduleDirectory();
    (new Filesystem())->mkdir($directory . '/config/schema');
    $store_route = (string) preg_replace('/\A.*?\n\n/s', '', (string) file_get_contents(self::FIRST . '/surface_examples_first.routing.yml'));
    $store_schema = (string) preg_replace('/\A.*?\n\n/s', '', (string) file_get_contents(self::FIRST . '/config/schema/surface_examples_first.schema.yml'));
    file_put_contents($directory . '/surface_examples_first.routing.yml', $store_route);
    file_put_contents($directory . '/config/schema/surface_examples_first.schema.yml', $store_schema);
    $permissions = (string) file_get_contents(self::FIRST . '/surface_examples_first.permissions.yml');
    file_put_contents($directory . '/surface_examples_first.permissions.yml', $permissions);

    // The permission is declared already, so its title is not asked.
    $answers = self::NEWSLETTER;
    array_splice($answers, 9, 1);
    $display = $this->generate($answers);

    $route = self::firstBlock('surface_examples_first.routing.yml');
    $this->assertSame($store_route . "\n" . $route, $this->written('surface_examples_first.routing.yml'), 'The store route is kept byte for byte, the newsletter route added after a blank line.');
    $this->assertSame($permissions, $this->written('surface_examples_first.permissions.yml'), 'The permission that is there is kept.');
    $this->assertStringContainsString('surface_examples_first.permissions.yml already declares the permission administer surface_examples_first: kept as it is', $display);
    $this->assertStringContainsString('surface_examples_first.routing.yml: the route surface_examples_first.newsletter added at its end; every other entry kept as it was.', $display);
    $schema = $this->written('config/schema/surface_examples_first.schema.yml');
    $this->assertStringStartsWith(rtrim($store_schema, "\n") . "\n\n# Generated from the first.newsletter resource", $schema, 'The store entry is kept, the newsletter entry added.');

    // Again: the route is there now, and is kept.
    $display = $this->generate($answers, ['--replace' => TRUE]);
    $this->assertSame($store_route . "\n" . $route, $this->written('surface_examples_first.routing.yml'));
    $this->assertStringContainsString('surface_examples_first.routing.yml already declares the route surface_examples_first.newsletter: kept as it is', $display);
  }

  /**
   * Tests node.settings writes its key, and no schema or install file.
   */
  public function testTheKeysOfNodeSettings(): void {
    $out = $this->sandbox . '/node_settings';
    $display = $this->generate([
      'surface_examples_first',
      // Resource id: node.settings; label: Node settings; class.
      '', '', '',
      '1',
      '',
      'Sets whether the administration theme is used when content is edited or added.',
      '', '', '',
    ], ['--from-config-schema' => 'node.settings'], $out);
    $resource = $this->written('src/Resource/NodeSettingsResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
  public const USE_ADMIN_THEME = 'use_admin_theme';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::USE_ADMIN_THEME, 'boolean', t('Use administration theme when editing or creating content'), default: TRUE)
      ->setRequired(TRUE);
  }
PHP, $resource, 'Required in a fully validatable object, its default the value the site holds; _core and langcode are the storage\'s.');
    $this->assertStringContainsString("#[Resource('node.settings',\n  label: new TranslatableMarkup('Node settings'),\n  storage: NodeSettingsStorage::class,\n)]", $resource, 'The label the interview offers, from the config schema\'s.');
    $this->assertStringContainsString('Written by drush generate surface:resource from the config schema of', $resource);
    $this->assertStringContainsString("public const CONFIG = 'node.settings';", $this->written('src/Storage/NodeSettingsStorage.php', $out));
    $this->assertFileDoesNotExist($out . '/config/schema/surface_examples_first.schema.yml', 'node.settings is node\'s: its schema describes it.');
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
      'surface_examples_first', 'first.card', 'Card', '', '1', '',
      'Sets the card: its heading, its tone and its link.',
      '', '', '', '',
    ], ['--from-json-schema' => dirname(__DIR__, 3) . '/modules/surface_examples/components/card/card.component.yml'], $out);
    $resource = $this->written('src/Resource/CardResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add(self::HEADING, 'string', t('Heading'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 80]);

    $inputs->add(self::TONE, 'string', t('Tone'), default: 'plain')
      ->addConstraint('LabeledChoice', [
        'labels' => [
          'plain' => t('Plain'),
          'highlight' => t('Highlighted'),
        ],
      ]);

    $inputs->addDefinition(self::LINK, MapDataDefinition::create()
      ->setPropertyDefinition('url', DataDefinition::create('uri')
        ->setLabel(t('Address')))
      ->setPropertyDefinition('label', DataDefinition::create('string')
        ->setLabel(t('Link text'))))
      ->setLabel(t('Link'));
PHP, $resource);
    $this->assertStringContainsString("public const ALWAYS_HELD = ['tone'];", $this->written('src/Storage/CardStorage.php', $out), 'An optional key that refuses null and has a default is one its storage always holds.');
    $schema = $this->written('config/schema/surface_examples_first.schema.yml', $out);
    $this->assertStringContainsString("surface_examples_first.card:\n  type: config_object\n  label: 'Card'", $schema);
    $this->assertStringContainsString("    tone:\n      type: string\n      label: 'Tone'\n", $schema, 'Always held: not nullable.');
    $this->assertSame("heading: ~\ntone: plain\nlink: ~\n", $this->written('config/install/surface_examples_first.card.yml', $out), 'As the schema command writes a map with no default: nothing.');
    $this->assertParses(['src/Resource/CardResource.php', 'src/Storage/CardStorage.php'], $out);
  }

  /**
   * Tests a document with a reference, a list, a nested object and a rule.
   */
  public function testTheKeysOfDocument(): void {
    $out = $this->sandbox . '/event';
    $display = $this->generate([
      'surface_examples_first', 'first.event', '', '', '1', '',
      'Sets the event: its name, kind, seats, venue and contact.',
      '', '', '', '',
    ], ['--from-json-schema' => dirname(__DIR__, 2) . '/fixtures/generator/event.schema.json'], $out);
    $resource = $this->written('src/Resource/EventResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add(self::KIND, 'string', t('Kind'), default: 'meetup')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['meetup', 'conference']]);
PHP, $resource, 'An enum is a Choice.');
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add(self::SLUG, 'string', t('Slug'))
      ->addConstraint('Regex', ['pattern' => '/^[a-z0-9-]+$/D']);

    $inputs->attach(self::VENUE, VenueStructure::class)
      ->setLabel(t('Venue'));

    $inputs->addDefinition(self::CONTACT, MapDataDefinition::create()
PHP, $resource, 'A $ref to a scalar def is read in place; one to an object def is a structure of its own; a nested object is a map.');
    $this->assertStringContainsString(<<<'PHP'
    // From the schema, not written here: multipleOf on self::SEATS, which no
    // constraint here states; the reader keeps it as a JsonSchema constraint.
    // Write a constraint for it.
    $inputs->add(self::SEATS, 'integer', t('Seats'))
      ->addConstraint('Range', ['min' => 1]);
PHP, $resource, 'A foreign constraint is a comment naming the keyword and the key by its constant.');
    $this->assertStringContainsString(<<<'PHP'
    $inputs->addList(self::TAGS, 'string', t('Tags'))
      ->addConstraint('UniqueItems', []);

    $inputs->narrow(self::SEATS)
      ->when(Condition::equals(self::KIND, 'conference'))
      ->require();
PHP, $resource, 'uniqueItems is the stock UniqueItems; the if/then is a declared rule.');
    $this->assertStringContainsString(<<<'PHP'
    $inputs->add(self::LEVEL, 'integer', t('Level'))
      ->addConstraint('LabeledChoice', [
        'labels' => [
          1 => t('Open'),
          2 => t('Invited'),
        ],
      ]);

    $inputs->add(self::SIZE, 'string', t('Size'))
      ->addConstraint('LabeledChoice', [
        'choices' => ['1', '2'],
        'labels' => [
          1 => t('Small'),
          2 => t('Large'),
        ],
      ]);
PHP, $resource, 'A labeled choice is its labels alone, unless a value is a numeric string, which PHP would key as an integer.');
    $structure = $this->written('src/Structure/VenueStructure.php', $out);
    $this->assertStringContainsString("#[Structure('first.event.venue')]", $structure);
    $this->assertStringContainsString("  public const CITY = 'city';\n  public const CAPACITY = 'capacity';\n\n", $structure, 'A structure names its own keys.');
    $this->assertStringContainsString("    \$inputs->add(self::CITY, 'string', t('City'))\n      ->setRequired(TRUE);", $structure);
    $this->assertStringContainsString('A comment in the class names what was not written from the JSON Schema', $display);
    $this->assertStringContainsString('/properties/seats/multipleOf: Kept as a JsonSchema constraint', $display);
    $this->assertParses([
      'src/Resource/EventResource.php',
      'src/Storage/EventStorage.php',
      'src/Structure/VenueStructure.php',
    ], $out);
  }

  /**
   * Tests keys whose constants would clash are named apart.
   *
   * A camelCase key splits at its capitals, a run of other characters is
   * one underscore, a key starting with a digit is prefixed KEY_, and a
   * later key whose name is taken, or would be the reserved CLASS, takes
   * the first free suffix.
   */
  public function testKeysAreNamedOnceAsConstants(): void {
    $this->assertSame([
      'fooBar' => 'FOO_BAR',
      'foo_bar' => 'FOO_BAR_2',
      'foo-bar' => 'FOO_BAR_3',
      'class' => 'CLASS_2',
      '2nd' => 'KEY_2ND',
      'a.b' => 'A_B',
      'ID' => 'ID',
    ], ResourceCode::constantNames(['fooBar', 'foo_bar', 'foo-bar', 'class', '2nd', 'a.b', 'ID']));

    $document = $this->sandbox . '/clash.schema.json';
    file_put_contents($document, (string) json_encode([
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      'type' => 'object',
      'properties' => [
        'fooBar' => ['type' => 'string', 'title' => 'Foo'],
        'foo_bar' => ['type' => ['string', 'null'], 'title' => 'URL of the bar'],
        'class' => ['type' => ['integer', 'null'], 'title' => 'Class', 'multipleOf' => 5],
      ],
      'allOf' => [
        [
          'if' => ['properties' => ['fooBar' => ['const' => 'x']], 'required' => ['fooBar']],
          'then' => ['required' => ['foo_bar']],
        ],
      ],
    ]));
    $out = $this->sandbox . '/clash';
    $this->generate([
      'surface_examples_first', 'first.clash', 'Clash', '', '1', '',
      'Sets the clash.',
      '', '', '', '',
    ], ['--from-json-schema' => $document], $out);
    $resource = $this->written('src/Resource/ClashResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
final class ClashResource implements ResourceInterface {

  public const FOO_BAR = 'fooBar';
  public const FOO_BAR_2 = 'foo_bar';
  public const CLASS_2 = 'class';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::FOO_BAR, 'string', t('Foo'));

    $inputs->add(self::FOO_BAR_2, 'string', t('URL of the bar'));

    // From the schema, not written here: multipleOf on self::CLASS_2, which no
    // constraint here states; the reader keeps it as a JsonSchema constraint.
    // Write a constraint for it.
    $inputs->add(self::CLASS_2, 'integer', t('Class'));

    $inputs->narrow(self::FOO_BAR_2)
      ->when(Condition::equals(self::FOO_BAR, 'x'))
      ->require();
  }
PHP, $resource, 'Each key is a constant, in declaration order; every call and comment names it by the constant.');
    $this->assertParses(['src/Resource/ClashResource.php', 'src/Storage/ClashStorage.php'], $out);
  }

  /**
   * Tests a resource with identity is refused, naming what is not offered.
   */
  public function testIdentityIsRefusedForNow(): void {
    $out = $this->sandbox . '/identity';
    $display = $this->generate(['surface_examples_first', 'first.venue', 'Venue', '', '2', 'id'], [], $out, 1);
    $this->assertStringContainsString('A resource with identity (id) is stored in a config entity type', $display);
    // What to write by hand: the title and machine name pair.
    $this->assertStringContainsString("compute('id', 'machine_name', t('Machine name'), from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)", $display);
    $this->assertDirectoryDoesNotExist($out . '/src');
  }

}

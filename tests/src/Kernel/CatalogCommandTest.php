<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Drush\Commands\CatalogCommand;
use Drupal\surface\ResourceBuild\SiteCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests `drush surface:catalog`: the live map, one resource, the refusals.
 *
 * On a site with the first resource, the tool and HTTP doors on, and the
 * resource test module, whose broken fixtures are refused when built: the
 * table, the JSON and the markdown list every resource and structure with
 * each operation's doors; `--resource` shows one in full; `--refused`
 * lists only what did not build, each with its message and its file, and
 * fails. The table ends with the URL of the catalog page, which lists the
 * same rows (CatalogPageTest holds the two to each other).
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CatalogCommandTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_examples_react',
    'surface_examples_first',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples_first', 'surface_resource_test']);
  }

  /**
   * Runs the command.
   *
   * @param array<string, mixed> $options
   *   Its options, by name with the dashes.
   *
   * @return array{0: int, 1: string}
   *   The exit status and what it printed.
   */
  protected function catalog(array $options = []): array {
    $tester = new CommandTester(new CatalogCommand($this->container->get('surface.site_catalog')));
    $status = $tester->execute($options);
    return [$status, $tester->getDisplay()];
  }

  /**
   * Tests the table lists each operation with the doors it reaches.
   */
  public function testTheTableListsEveryOperationAndItsDoors(): void {
    [$status, $display] = $this->catalog();
    $this->assertSame(0, $status, $display);
    $line = static fn (string $resource): string => (string) preg_replace('/\s+/', ' ', implode("\n", preg_grep('/^\s*' . preg_quote($resource, '/') . '\s/', explode("\n", $display)) ?: []));
    $this->assertSame(' first.newsletter resource configure (update) administer surface_examples_first nothing form, http, tool, read tool, openapi, react ', $line('first.newsletter'));
    // A structure no operation asks for on its own.
    $this->assertStringContainsString(' resource_test.digest.daily structure none ', $line('resource_test.digest.daily'));
    $this->assertMatchesRegularExpression('/\d+ resources and \d+ structures, \d+ operations, \d+ structures read from a schema; [1-9]\d* refused \(drush surface:catalog --refused lists them\)\./', (string) preg_replace('/\s+/', ' ', $display));
    // Last, the page that lists the same with every door linked.
    $this->assertMatchesRegularExpression('#The catalog page lists the same, each door linked: http\S*/admin/config/development/surfaces\s*$#', $display);
  }

  /**
   * Tests the JSON names each door, and the markdown is the site catalog's.
   */
  public function testJsonAndMarkdown(): void {
    [$status, $json] = $this->catalog(['--format' => 'json']);
    $this->assertSame(0, $status);
    $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['resources', 'derivers', 'refused'], array_keys($decoded));
    $configure = $decoded['resources']['first.store']['operations'][0];
    $this->assertSame('configure', $configure['id']);
    $this->assertSame([
      SiteCatalog::FORM => '/admin/config/services/surface-store (surface_examples_first.store)',
      SiteCatalog::HTTP => 'PATCH /surface-api/first.store',
      SiteCatalog::TOOL => 'surface:first.store:configure',
      SiteCatalog::READ_TOOL => 'surface:first.store:configure:read',
      SiteCatalog::OPENAPI => 'first.store:configure:write',
      SiteCatalog::REACT => '/surface-react/first.store/configure',
    ], $configure['doors']);
    $this->assertNotSame([], $decoded['refused']);
    // A structure read from a schema, a variant the poster's provider
    // lists, by the name it is built by.
    $read = array_filter($decoded['resources'], static fn (array $entry): bool => $entry['read'] !== NULL);
    $this->assertNotSame([], $read);
    foreach ($read as $name => $entry) {
      $this->assertStringStartsWith('schema:' . $entry['id'] . ':', (string) $name);
      $this->assertSame('structure', $entry['kind']);
    }
    [$status, $one] = $this->catalog(['--resource' => (string) array_key_first($read), '--format' => 'json']);
    $this->assertSame(0, $status, $one);
    $this->assertNotSame([], json_decode($one, TRUE, 512, JSON_THROW_ON_ERROR)['keys']);

    [$status, $markdown] = $this->catalog(['--format' => 'markdown']);
    $this->assertSame(0, $status);
    $this->assertSame($this->container->get('surface.site_catalog')->markdown(), $markdown);
    $this->assertStringStartsWith("# Catalog\n", $markdown);
    $this->assertStringContainsString("\n## Refused\n", $markdown);
    $this->assertStringContainsString("\n## Read from a schema\n", $markdown);

    [$status, $display] = $this->catalog(['--format' => 'yaml']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('--format is table, json or markdown, not "yaml".', $display);
  }

  /**
   * Tests one resource in full: its keys, its refiner, its doors.
   */
  public function testOneResourceInFull(): void {
    [$status, $json] = $this->catalog(['--resource' => 'first.store', '--format' => 'json']);
    $this->assertSame(0, $status, $json);
    $entry = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('configure', $entry['built']);
    $this->assertSame(['Store location', NULL], [$entry['label'], $entry['description']], 'Its label, and no description.');
    $this->assertSame(['class' => NULL, 'alters' => [], 'kind' => 'none'], $entry['answers']);
    $keys = array_column($entry['keys'], NULL, 'key');
    $this->assertSame(['country', 'region'], array_keys($keys));
    $this->assertSame('string', $keys['region']['type']);
    $this->assertTrue($keys['region']['required']);
    $this->assertSame('CA-ON', $keys['region']['default']);
    $this->assertSame(['LabeledChoice'], array_keys($keys['region']['constraints']));
    $this->assertSame(['Drupal\surface_examples_first\Resource\StoreResource::regionOfCountry() watching country'], $keys['region']['refiners']);
    $this->assertSame(['country'], $keys['region']['watches']);

    // A table, with the keys and the doors.
    [$status, $display] = $this->catalog(['--resource' => 'first.store']);
    $this->assertSame(0, $status);
    $said_all = [
      'Label',
      'Store location',
      'Keys read in',
      'regionOfCountry',
      'PATCH /surface-api/first.store',
      'LabeledChoice',
    ];
    foreach ($said_all as $said) {
      $this->assertStringContainsString($said, $display);
    }
    $this->assertMatchesRegularExpression('#Its page in the catalog: http\S*/admin/config/development/surfaces/first\.store\s*$#', $display);
    [$status, $markdown] = $this->catalog(['--resource' => 'first.store', '--format' => 'markdown']);
    $this->assertSame(0, $status);
    $this->assertStringStartsWith("# first.store\n\n- Label: Store location\n- Kind: resource\n", $markdown);
    $this->assertStringContainsString('| `region` | string, required | `"CA-ON"` |', $markdown);

    // Children, variants and items by their dotted path.
    [, $json] = $this->catalog(['--resource' => 'resource_test.cellar', '--format' => 'json']);
    $cellar = array_column(json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR)['keys'], NULL, 'key');
    $this->assertSame('list', $cellar['racks']['type']);
    $this->assertArrayHasKey('racks.*.shelf', $cellar);

    [$status, $display] = $this->catalog(['--resource' => 'no.such.thing']);
    $this->assertSame(1, $status);
    $this->assertStringContainsString('Discovery found no resource or structure "no.such.thing"', (string) preg_replace('/\s+/', ' ', $display));
  }

  /**
   * Tests `--refused` lists a refused fixture with its message and its file.
   */
  public function testRefusedListsWhatDidNotBuild(): void {
    [$status, $json] = $this->catalog(['--refused' => TRUE, '--format' => 'json']);
    $this->assertSame(1, $status, 'It fails when anything did not build.');
    $refused = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $identity = array_values(array_filter($refused, static fn (array $refusal): bool => $refusal['resource'] === 'resource_test.broken.identity'));
    $this->assertCount(1, $identity);
    [$refusal] = $identity;
    $this->assertSame(SiteCatalog::BUILD, $refusal['stage']);
    $this->assertNull($refusal['operation']);
    $this->assertStringContainsString('ghost', $refusal['message']);
    $this->assertSame('modules/custom/surface/tests/modules/surface_resource_test/src/Resource/Broken/UndeclaredIdentityResource.php', $refusal['file']);
    // Only what did not build: nothing of the first resource.
    $this->assertSame([], array_filter($refused, static fn (array $refusal): bool => str_starts_with((string) $refusal['resource'], 'first.')));

    [$status, $display] = $this->catalog(['--refused' => TRUE]);
    $this->assertSame(1, $status);
    $flat = (string) preg_replace('/\s+/', ' ', $display);
    $this->assertStringContainsString('resource_test.broken.identity', $flat);
    $this->assertStringContainsString(count($refused) . ' refused.', $flat);
    $this->assertStringNotContainsString('first.store', $flat);

    [, $markdown] = $this->catalog(['--refused' => TRUE, '--format' => 'markdown']);
    $this->assertStringStartsWith("## Refused\n", $markdown);
    $this->assertStringContainsString('| `resource_test.broken.identity` | the whole |', $markdown);
  }

}

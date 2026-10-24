<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every form of the API's paths: contract 3, the operation the method.
 *
 * A singleton's plain path and a thing's identified one, each with its
 * `/schema`; a custom method, `:{operation}`, on a singleton (example
 * 10's check, a read) and its schema; a path, a method, an operation or
 * an id that names nothing; and every contract 2 path, gone (410) with
 * its replacement named, under the operation id it carried then (`create`,
 * `update`) as under the one it carries now.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedPathsTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'data_surface_examples',
    'data_surface_react',
    'data_surface_demo_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
      'administer content types',
      'administer data surface node type demo',
    ]));
  }

  /**
   * Tests a custom method on a singleton: example 10's check, a read.
   */
  public function testCustomMethod(): void {
    // The default update is the plain path's; the read is named.
    $this->assertSame('configure', $this->decoded($this->send('GET', 'surface-api/event.program'))['operation']);
    $read = $this->send('GET', 'surface-api/event.program:check');
    $envelope = $this->decoded($read);
    $this->assertSame('check', $envelope['operation']);
    $this->assertSame('read', $envelope['schema']['x-surface']['operation']['kind']);
    $this->assertStringEndsWith('/surface-api/event.program:check', $envelope['schema']['$id']);
    $this->assertStringContainsString('/surface-api/event.program:check/schema>', $read->getHeaderLine('Link'));
    $full = $this->decoded($this->send('GET', 'surface-api/event.program:check/schema'), 200, 'application/schema+json');
    $this->assertSame('check', $full['x-surface']['operation']['id']);
    // The colon may arrive encoded, as a client may write it.
    $this->assertSame('check', $this->decoded($this->send('GET', 'surface-api/event.program%3Acheck'))['operation']);
    $narrowed = $this->decoded($this->send('POST', 'surface-api/event.program:check/schema', ['sessions' => 4]));
    $this->assertSame(4, $narrowed['values']['sessions']);

    // A run of it checks and writes nothing at all, dry or not.
    $before = $this->config('data_surface_examples.program')->get();
    $checked = $this->decoded($this->send('POST', 'surface-api/event.program:check', [
      'headline' => 'Checked',
      'sessions' => 4,
    ]));
    $this->assertTrue($checked['ok']);
    $this->assertFalse($checked['committed']);
    $this->assertNull($checked['contract']);
    $refused = $this->problem($this->send('POST', 'surface-api/event.program:check', ['sessions' => 99]), 422);
    $this->assertSame(['/sessions'], array_column($refused['errors'], 'path'));
    $this->container->get('config.factory')->reset();
    $this->assertSame($before, $this->config('data_surface_examples.program')->get());

    // A custom method of an operation that is no update is GET and POST,
    // never PATCH.
    $patch = $this->send('PATCH', 'surface-api/event.program:check', ['sessions' => 4]);
    $this->problem($patch, 405);
    $this->assertSame('GET, POST, HEAD', $patch->getHeaderLine('Allow'));
    // An operation that names nothing is no path.
    $this->problem($this->send('GET', 'surface-api/event.program:nothing'), 404);
    $this->problem($this->send('POST', 'surface-api/event.program:nothing', []), 404);
  }

  /**
   * Tests a thing with identity: the collection, the item, their schemas.
   */
  public function testIdentifiedPaths(): void {
    // The collection: GET is the create's envelope, POST the create.
    $collection = $this->decoded($this->send('GET', 'surface-api/node.type'));
    $this->assertSame('create', $collection['operation']);
    $this->assertStringEndsWith('/surface-api/node.type', $collection['schema']['$id']);
    $this->assertSame('create', $this->decoded($this->send('GET', 'surface-api/node.type/schema'), 200, 'application/schema+json')['x-surface']['operation']['id']);
    $patch = $this->send('PATCH', 'surface-api/node.type', ['name' => 'X']);
    $this->problem($patch, 405);
    $this->assertSame('GET, POST, HEAD', $patch->getHeaderLine('Allow'));

    // The item: its identity is the path.
    $item = $this->send('GET', 'surface-api/node.type/article');
    $envelope = $this->decoded($item);
    $this->assertSame('update', $envelope['operation']);
    $this->assertSame('article', $envelope['values']['type']);
    $this->assertNotSame('', $item->getHeaderLine('ETag'));
    $full = $this->decoded($this->send('GET', 'surface-api/node.type/article/schema'), 200, 'application/schema+json');
    $this->assertStringEndsWith('/surface-api/node.type/article/schema', $full['$id']);
    $this->assertTrue($full['properties']['type']['x-surface']['locked']);
    $narrowed = $this->decoded($this->send('POST', 'surface-api/node.type/article/schema', ['name' => 'Story']));
    $this->assertSame('Story', $narrowed['values']['name']);
    $dry = $this->decoded($this->send('PATCH', 'surface-api/node.type/article?dryRun=true', ['name' => 'Story'], ['If-Match' => $item->getHeaderLine('ETag')]));
    $this->assertSame(['/name'], $dry['changes']);
    $post = $this->send('POST', 'surface-api/node.type/article', ['name' => 'Story']);
    $this->problem($post, 405);
    $this->assertSame('GET, PATCH, HEAD', $post->getHeaderLine('Allow'));

    // An id that names nothing, too many segments, and a singleton given
    // an identity: 404.
    $this->problem($this->send('GET', 'surface-api/node.type/nothing'), 404);
    $this->problem($this->send('GET', 'surface-api/node.type/article/extra'), 404);
    $this->problem($this->send('GET', 'surface-api/registration.step2/main'), 404);
    // A create named on an identity is no path; nor is a method the route
    // has none of.
    $this->problem($this->send('POST', 'surface-api/node.type/article:create', []), 404);
    $delete = $this->send('DELETE', 'surface-api/node.type/article');
    $this->problem($delete, 405);
  }

  /**
   * Tests every contract 2 path is gone, naming what replaces it.
   */
  public function testContractTwoPathsAreGone(): void {
    $gone = [
      ['GET', 'surface-api/registration.step2/configure', 'GET', '/surface-api/registration.step2'],
      ['GET', 'surface-api/registration.step2/configure/schema', 'GET', '/surface-api/registration.step2/schema'],
      ['POST', 'surface-api/registration.step2/configure/refine', 'POST', '/surface-api/registration.step2/schema'],
      [
        'POST',
        'surface-api/registration.step2/configure/validate',
        'PATCH',
        '/surface-api/registration.step2?dryRun=true',
      ],
      ['POST', 'surface-api/registration.step2/configure/submit', 'PATCH', '/surface-api/registration.step2'],
      ['GET', 'surface-api/event.program/check', 'GET', '/surface-api/event.program:check'],
      ['POST', 'surface-api/event.program/check/submit', 'POST', '/surface-api/event.program:check'],
      ['GET', 'surface-api/node.type/add', 'GET', '/surface-api/node.type'],
      ['POST', 'surface-api/node.type/add/submit', 'POST', '/surface-api/node.type'],
      ['GET', 'surface-api/node.type/edit?type=article', 'GET', '/surface-api/node.type/article'],
      ['GET', 'surface-api/node.type/edit/schema?type=article', 'GET', '/surface-api/node.type/article/schema'],
      [
        'POST',
        'surface-api/node.type/edit/validate?type=article',
        'PATCH',
        '/surface-api/node.type/article?dryRun=true',
      ],
      ['POST', 'surface-api/node.type/edit/submit', 'PATCH', '/surface-api/node.type/{type}'],
      // Under the id the operation carries now, as under the one it did.
      ['GET', 'surface-api/node.type/update?type=article', 'GET', '/surface-api/node.type/article'],
    ];
    foreach ($gone as [$method, $path, $moved, $href]) {
      $problem = $this->problem($this->send($method, $path, $method === 'POST' ? [] : NULL), 410);
      $this->assertStringEndsWith('served-contract.md#gone', $problem['type'], $path);
      $this->assertSame($moved, $problem['moved']['method'], $path);
      $this->assertStringEndsWith($href, $problem['moved']['href'], $path);
      $this->assertStringContainsString($moved . ' ', $problem['detail'], $path);
    }
    // A suffix contract 2 never had is no path at all.
    $this->problem($this->send('GET', 'surface-api/registration.step2/configure/other'), 404);
  }

}

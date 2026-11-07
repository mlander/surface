<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Http\ServedPaths;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\surface\Traits\ServedApiTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a stage over HTTP: its own paths, and the header that names it.
 *
 * `X-Surface-Stage` on a page's PATCH keeps the write in the stage: the
 * answer is the one every write answers, its contract and its ETag those
 * of the page as it stands in the stage, and If-Match is held to that
 * view. GET with the header reads it, without it what is stored. The
 * stage's own paths open, read, rename, list, apply and discard it, and
 * a discarded stage's path is refused as any id that names nothing is
 * where the operation declares no permission; another account's stage is
 * forbidden at its path and through the header alike, a header that
 * names nothing is not found, and the header on the stage's own path is
 * refused.
 *
 * @see docs/staging.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageApiTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'surface',
    'surface_examples_entity',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The page resource's collection.
   */
  protected const PAGES = 'surface-api/entity.content:node.page';

  /**
   * The stage resource's collection.
   */
  protected const STAGES = 'surface-api/surface.stage';

  /**
   * An id no stage has.
   */
  protected const NONE = '00000000-0000-4000-8000-000000000000';

  /**
   * The page the tests stage writes to.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->container->get('cache_tags.invalidator')->invalidateTags(['entity_field_info']);
    $this->node = Node::create(['type' => 'page', 'title' => 'Spring meetup']);
    $this->node->save();
    $this->drupalLogin($this->editor());
  }

  /**
   * Creates an account that may edit pages.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   */
  protected function editor(): UserInterface {
    $account = $this->drupalCreateUser(['access content', 'edit any page content']);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * Opens a stage as the logged-in account.
   *
   * @param string $label
   *   Its label.
   *
   * @return string
   *   Its id.
   */
  protected function open(string $label = 'Move the meetup'): string {
    $response = $this->send('POST', self::STAGES, ['label' => $label]);
    $opened = $this->decoded($response, 201);
    $id = $opened['outputs']['id'] ?? NULL;
    $this->assertIsString($id);
    $this->assertStringEndsWith('/' . self::STAGES . '/' . $id, $response->getHeaderLine('Location'));
    return $id;
  }

  /**
   * Gets the page's title as stored.
   *
   * @return string
   *   The title.
   */
  protected function storedTitle(): string {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    return (string) $node->label();
  }

  /**
   * Tests the header keeps a PATCH in the stage and reads its view.
   */
  public function testTheHeaderStagesThePatch(): void {
    $page = self::PAGES . '/' . $this->node->id();
    $stored = $this->send('GET', $page);
    $this->assertSame('Spring meetup', $this->decoded($stored)['values']['title']['value']);
    $stored_tag = $stored->getHeaderLine('ETag');
    $this->assertNotSame('', $stored_tag);

    $stage = $this->open();
    $header = [ServedPaths::STAGE_HEADER => $stage];
    $response = $this->send('PATCH', $page, ['title' => ['value' => 'Spring meetup, moved']], $header);
    $answer = $this->decoded($response);
    $this->assertSame([...ResultDocument::KEYS, 'outputs', 'contract', 'created'], array_keys($answer), 'The answer every write gives, and nothing naming the stage.');
    $this->assertTrue($answer['committed']);
    $this->assertSame(['/title/value'], $answer['changes']);
    $this->assertSame('Spring meetup, moved', $answer['contract']['values']['title']['value'], 'The contract is the staged view.');
    $staged_tag = $response->getHeaderLine('ETag');
    $this->assertNotSame('', $staged_tag);
    $this->assertNotSame($stored_tag, $staged_tag, 'The ETag is the staged view\'s.');
    $this->assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame('Spring meetup', $this->storedTitle(), 'The page is as it was.');

    // Read with the header, the staged view; without, what is stored.
    $read = $this->send('GET', $page, NULL, $header);
    $this->assertSame('Spring meetup, moved', $this->decoded($read)['values']['title']['value']);
    $this->assertSame($staged_tag, $read->getHeaderLine('ETag'));
    $this->assertSame('no-store, private', $read->getHeaderLine('Cache-Control'));
    $plain = $this->send('GET', $page);
    $this->assertSame('Spring meetup', $this->decoded($plain)['values']['title']['value']);
    $this->assertSame($stored_tag, $plain->getHeaderLine('ETag'));

    // If-Match is held to the staged view.
    $this->problem($this->send('PATCH', $page, ['title' => ['value' => 'Moved again']], $header + ['If-Match' => $stored_tag]), 412);
    $again = $this->decoded($this->send('PATCH', $page, ['title' => ['value' => 'Moved again']], $header + ['If-Match' => $staged_tag]));
    $this->assertSame(['/title/value'], $again['changes']);
    $this->assertSame('Moved again', $again['contract']['values']['title']['value']);
    $this->assertSame('Spring meetup', $this->storedTitle());
  }

  /**
   * Tests the stage's own paths, and who may use one.
   */
  public function testTheStagePaths(): void {
    $id = $this->open();
    $stage = self::STAGES . '/' . $id;
    $this->assertSame('Move the meetup', $this->decoded($this->send('GET', $stage))['values']['label']);
    $renamed = $this->decoded($this->send('PATCH', $stage, ['label' => 'Move the spring meetup']));
    $this->assertTrue($renamed['committed']);
    $this->assertSame('Move the spring meetup', $this->decoded($this->send('GET', $stage))['values']['label']);

    $page = self::PAGES . '/' . $this->node->id();
    $this->decoded($this->send('PATCH', $page, ['title' => ['value' => 'Spring meetup, moved']], [ServedPaths::STAGE_HEADER => $id]));
    $changes = $this->decoded($this->send('POST', $stage . ':changes', []));
    $this->assertSame(1, $changes['outputs']['total']);
    $this->assertSame('entity.content:node.page', $changes['outputs']['items'][0]['resource']);
    $this->assertSame([['name' => 'id', 'value' => (string) $this->node->id()]], $changes['outputs']['items'][0]['parameters']);
    $this->assertSame(['/title'], $changes['outputs']['items'][0]['changed']);
    $envelope = $this->decoded($this->send('GET', $stage . ':changes'));
    $this->assertSame('changes', $envelope['operation']);
    $this->assertArrayHasKey('schema', $envelope);

    $rehearsed = $this->decoded($this->send('PATCH', $stage . ':apply?dryRun=true', []));
    $this->assertTrue($rehearsed['x-surface-dry-run']);
    $this->assertFalse($rehearsed['committed']);
    $this->assertSame('Spring meetup', $this->storedTitle(), 'A dry run writes nothing.');
    $applied = $this->decoded($this->send('PATCH', $stage . ':apply', []));
    $this->assertTrue($applied['committed']);
    $this->assertSame([''], $applied['changes']);
    $this->assertSame((string) $this->node->id(), $applied['outputs']['applied'][0]['id']);
    $this->assertIsArray($applied['contract']);
    $this->assertSame('Spring meetup, moved', $this->storedTitle());
    $twice = $this->problem($this->send('PATCH', $stage . ':apply', []), 422);
    $this->assertSame(['does_not_apply'], array_column($twice['errors'], 'code'));
    $this->decoded($this->send('GET', $stage), 200);
    $this->assertTrue($this->decoded($this->send('DELETE', $stage))['committed']);
    // Gone: the stage's operations declare no permission, so the route's
    // access check lets no account hear that an id names nothing, and
    // answers with its one fixed refusal, as for any such operation.
    $this->problem($this->send('DELETE', $stage), 403);
    $this->problem($this->send('GET', $stage), 403);

    // A header naming no stage names nothing.
    $this->problem($this->send('PATCH', $page, ['title' => ['value' => 'Nowhere']], [ServedPaths::STAGE_HEADER => self::NONE]), 404);
    $this->problem($this->send('GET', $page, NULL, [ServedPaths::STAGE_HEADER => self::NONE]), 404);

    // The header on the stage's own path is refused, with why.
    $mine = $this->open('Mine');
    $refused = $this->problem($this->send('PATCH', self::STAGES . '/' . $mine, ['label' => 'Renamed'], [ServedPaths::STAGE_HEADER => $mine]), 400);
    $this->assertStringContainsString(ServedPaths::STAGE_HEADER, $refused['detail']);

    // Another account's stage is forbidden at its path and through the
    // header alike; without the header the page is that account's to edit.
    $this->drupalLogin($this->editor());
    $this->problem($this->send('GET', self::STAGES . '/' . $mine), 403);
    $this->problem($this->send('PATCH', self::STAGES . '/' . $mine . ':apply', []), 403);
    $this->problem($this->send('PATCH', $page, ['title' => ['value' => 'Theirs']], [ServedPaths::STAGE_HEADER => $mine]), 403);
    $this->problem($this->send('GET', $page, NULL, [ServedPaths::STAGE_HEADER => $mine]), 403);
    $this->decoded($this->send('GET', $page));
    $this->assertSame('Spring meetup, moved', $this->storedTitle());
  }

}

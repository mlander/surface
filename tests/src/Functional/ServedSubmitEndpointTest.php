<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\data_surface\Pipeline\ResultDocument;
use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\data_surface\Traits\ServedApiTrait;

/**
 * Tests the API's writes over HTTP: contract 3.
 *
 * Example 2 is written with PATCH: a valid document is stored and
 * answered with the envelope rebuilt from storage and the new ETag, a
 * room of another venue is refused as problem details (422) with nothing
 * written, a request without access or without the session's token never
 * runs (403, problem details), a body of another media type is a 415,
 * and an If-Match from before someone else's write refuses the write
 * (412) while leaving that write alone. A content type is created with a
 * POST to the collection, answered 201 with its Location, and edited with
 * a PATCH on that Location.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedSubmitEndpointTest extends BrowserTestBase {

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
   * The config object example 2 keeps its settings in.
   */
  protected const CONFIG = 'data_surface_examples.registration_step2';

  /**
   * Example 2's path.
   */
  protected const STEP2 = 'surface-api/registration.step2';

  /**
   * A valid example 2 document, every key sent, as the app sends them.
   */
  protected const VALID = [
    'title' => 'Autumn meetup',
    'capacity' => 90,
    'open' => TRUE,
    'venue' => 'harbour',
    'room' => 'harbour_deck',
  ];

  /**
   * Reads example 2's stored settings, as storage holds them now.
   *
   * @return array
   *   The config object's data.
   */
  protected function stored(): array {
    $this->container->get('config.factory')->reset();
    return $this->config(self::CONFIG)->get();
  }

  /**
   * Tests a valid PATCH writes and answers the fresh envelope.
   */
  public function testPatchWritesAndAnswersTheFreshEnvelope(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $loaded = $this->send('GET', self::STEP2);
    $tag = $loaded->getHeaderLine('ETag');
    $this->assertNotSame('', $tag);

    $response = $this->send('PATCH', self::STEP2, self::VALID, ['If-Match' => $tag]);
    $answer = $this->decoded($response);
    // The one result shape, then this door's own keys.
    $keys = [...ResultDocument::KEYS, 'outputs', 'contract', 'created'];
    $this->assertSame($keys, array_keys($answer));
    $this->assertTrue($answer['changed']);
    $this->assertSame('harbour_deck', $answer['values']['room']);
    $this->assertTrue($answer['committed']);
    $this->assertTrue($answer['ok']);
    $this->assertSame([], $answer['violations']);
    $this->assertSame([], $answer['stale']);
    $this->assertSame([], $answer['outputs']);
    // What moved, by JSON Pointer; sent again, nothing does, and nothing
    // is saved.
    $this->assertSame(['/title', '/venue', '/room', '/capacity'], $answer['changes']);
    $again = $this->decoded($this->send('PATCH', self::STEP2, self::VALID));
    $this->assertTrue($again['committed']);
    $this->assertSame([], $again['changes']);
    // The operation configures; it creates nothing to move to.
    $this->assertNull($answer['created']);

    // Written, and the envelope answered is the one storage now builds:
    // the harbour's rooms, the deck chosen, a new ETag.
    $stored = $this->stored();
    $this->assertSame('Autumn meetup', $stored['title']);
    $this->assertSame('harbour_deck', $stored['room']);
    $this->assertSame(90, $stored['capacity']);
    $contract = $answer['contract'];
    $this->assertSame('registration.step2', $contract['resource']);
    $this->assertArrayNotHasKey('fingerprint', $contract);
    $this->assertEquals(self::VALID, array_intersect_key($contract['values'], self::VALID));
    $this->assertContains('harbour_deck', array_column($contract['schema']['properties']['room']['oneOf'], 'const'));
    $this->assertNotSame($tag, $response->getHeaderLine('ETag'));
    $this->assertSame($response->getHeaderLine('ETag'), $this->send('GET', self::STEP2)->getHeaderLine('ETag'));

    // A partial document changes what it sends and keeps the rest; null
    // clears.
    $partial = $this->decoded($this->send('PATCH', self::STEP2, ['title' => 'Winter meetup', 'open' => NULL]));
    $this->assertSame(['/title', '/open'], $partial['changes']);
    $this->assertSame('harbour_deck', $this->stored()['room']);
  }

  /**
   * Tests a refused write, and who may not write at all.
   */
  public function testRefusalWritesNothing(): void {
    $before = $this->stored();
    $this->problem($this->send('PATCH', self::STEP2, self::VALID, [], FALSE), 403);

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    // Without the session's token, refused before it is read.
    $this->assertSame('X-CSRF-Token request header is missing', $this->problem($this->send('PATCH', self::STEP2, self::VALID, [], FALSE), 403)['detail']);
    // A body that is not a JSON object is the caller's mistake, and so is
    // a media type a PATCH does not take.
    $this->problem($this->send('PATCH', self::STEP2, '{not json'), 400);
    $this->problem($this->send('PATCH', self::STEP2, '["harbour"]'), 400);
    $unsupported = $this->send('PATCH', self::STEP2, self::VALID, ['Content-Type' => 'text/plain']);
    $this->problem($unsupported, 415);
    $this->assertSame('application/merge-patch+json, application/json', $unsupported->getHeaderLine('Accept-Patch'));
    // A singleton has no create: POST on it is not allowed.
    $post = $this->send('POST', self::STEP2, self::VALID);
    $this->problem($post, 405);
    $this->assertSame('GET, PATCH, HEAD', $post->getHeaderLine('Allow'));

    // The garden room is the library's: a refusal, problem details.
    $refused = $this->problem($this->send('PATCH', self::STEP2, ['room' => 'library_garden', 'capacity' => 20] + self::VALID), 422);
    $this->assertSame('The values were refused', $refused['title']);
    $this->assertSame('One value was refused; nothing was written.', $refused['detail']);
    $this->assertSame(['/room' => 'no_such_choice'], array_column($refused['errors'], 'code', 'path'));
    // A person's door: the message reads as the form's, by labels alone,
    // while the path and the code are the tool's.
    $this->assertSame('The value you selected is not a valid choice. This applies when Venue is Harbour Centre. Choose Auditorium or Upper deck.', $refused['errors'][0]['message']);
    $this->assertSame([], $refused['notes']);
    $this->assertSame([], $refused['stale']);

    $this->assertSame($before, $this->stored());
  }

  /**
   * Tests an If-Match from before another write refuses the write.
   */
  public function testStaleIfMatchIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $tag = $this->send('GET', self::STEP2)->getHeaderLine('ETag');

    // Someone else saves between the read and the write.
    $this->config(self::CONFIG)->set('title', 'Changed elsewhere')->save();
    $elsewhere = $this->stored();

    foreach ([self::STEP2, self::STEP2 . '?dryRun=true'] as $path) {
      $refused = $this->problem($this->send('PATCH', $path, self::VALID, ['If-Match' => $tag]), 412);
      $this->assertStringEndsWith('served-contract.md#conflict', $refused['type']);
      $this->assertCount(1, $refused['errors']);
      $this->assertSame('', $refused['errors'][0]['path']);
      $this->assertSame('conflict', $refused['errors'][0]['code']);
      $this->assertStringContainsString('changed since this form was loaded', $refused['errors'][0]['message']);
    }
    $this->assertSame($elsewhere, $this->stored());

    // The tag a proxy weakened still names the same stored values, and `*`
    // any.
    $now = $this->send('GET', self::STEP2)->getHeaderLine('ETag');
    $this->assertTrue($this->decoded($this->send('PATCH', self::STEP2 . '?dryRun=true', self::VALID, ['If-Match' => 'W/' . $now]))['ok']);
    $this->assertTrue($this->decoded($this->send('PATCH', self::STEP2 . '?dryRun=true', self::VALID, ['If-Match' => '"other", ' . $now]))['ok']);
    $this->assertTrue($this->decoded($this->send('PATCH', self::STEP2 . '?dryRun=true', self::VALID, ['If-Match' => '*']))['ok']);

    // The rule is opt-in: without If-Match the last write wins.
    $written = $this->decoded($this->send('PATCH', self::STEP2, self::VALID));
    $this->assertTrue($written['committed']);
    $this->assertSame('Autumn meetup', $this->stored()['title']);
  }

  /**
   * Tests a content type created with POST answers 201 and its Location.
   */
  public function testCreateAnswersWhereTheCreatedTypeLives(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer content types',
      'administer data surface node type demo',
    ]));
    // What the app does: the collection's envelope, the create's declared
    // defaults, two of them answered.
    $collection = $this->send('GET', 'surface-api/node.type');
    $form = $this->decoded($collection);
    $this->assertSame('add', $form['operation']);
    $this->assertNull($form['values']['type']);
    $this->assertSame('', $collection->getHeaderLine('ETag'), 'Nothing stored, nothing to match.');
    $document = ['name' => 'Recipe', 'type' => 'recipe', 'title_label' => 'Recipe name'] + array_filter($form['values'], static fn ($value): bool => $value !== NULL);

    // A dry run creates nothing: 200, no Location.
    $dry = $this->send('POST', 'surface-api/node.type?dryRun=true', $document);
    $this->assertTrue($this->decoded($dry)['x-surface-dry-run']);
    $this->assertSame('', $dry->getHeaderLine('Location'));
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('node_type')->loadMultiple(['recipe']));

    $response = $this->send('POST', 'surface-api/node.type', $document);
    $answer = $this->decoded($response, 201);
    $this->assertTrue($answer['committed'], (string) json_encode($answer['violations']));
    $this->assertSame([
      'resource' => 'node.type',
      'operation' => 'edit',
      'parameters' => ['type' => 'recipe'],
    ], $answer['created']);
    $location = $response->getHeaderLine('Location');
    $this->assertStringEndsWith('/surface-api/node.type/recipe', $location);

    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $type);
    $this->assertSame('Recipe', $type->label());

    // Where it lives answers with what was written, the machine name
    // locked: the identity is the path.
    $read = $this->getHttpClient()->request('GET', $location, [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    $edit = $this->decoded($read);
    $this->assertSame('edit', $edit['operation']);
    $this->assertSame('Recipe', $edit['values']['name']);
    $this->assertSame('recipe', $edit['values']['type']);
    $this->assertSame('Recipe name', $edit['values']['title_label']);
    $this->assertTrue($edit['schema']['properties']['type']['x-surface']['locked']);
    $this->assertStringEndsWith('/surface-api/node.type/recipe', $edit['schema']['$id']);

    // Editing it creates nothing, so it says nowhere.
    $edited = $this->decoded($this->send('PATCH', 'surface-api/node.type/recipe', ['name' => 'Recipes'], ['If-Match' => $read->getHeaderLine('ETag')]));
    $this->assertTrue($edited['committed']);
    $this->assertNull($edited['created']);
    $this->assertSame('Recipes', $edited['contract']['values']['name']);
    $this->assertSame([], $edited['notes'], 'Nothing was sent for the locked machine name.');

    // Another machine name sent for it is kept as stored, not refused,
    // and the answer says so, by JSON Pointer.
    $renamed = $this->decoded($this->send('PATCH', 'surface-api/node.type/recipe', ['type' => 'recipes']));
    $this->assertTrue($renamed['committed'], (string) json_encode($renamed['violations']));
    $this->assertSame([
      [
        'path' => '/type',
        'code' => 'locked',
        'message' => 'Machine name is locked and kept as stored; the value sent was not taken.',
      ],
    ], $renamed['notes']);
    $this->assertSame('recipe', $renamed['contract']['values']['type']);

    // An id that names nothing is a 404 for an account that may edit
    // content types; one taken is refused as the form refuses it.
    $this->problem($this->send('GET', 'surface-api/node.type/nothing'), 404);
    $taken = $this->problem($this->send('POST', 'surface-api/node.type', $document), 422);
    $this->assertSame(['/type'], array_column($taken['errors'], 'path'));

    $type->delete();
    $this->assertNull(NodeType::load('recipe'));
  }

}

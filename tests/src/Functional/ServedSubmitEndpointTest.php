<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the served contract's submit endpoint over HTTP.
 *
 * Example 2 is written through it: a valid payload is stored and answered
 * with the contract rebuilt from storage, a room of another venue is
 * refused as data with nothing written, a request without access or
 * without the session's token never runs, and a fingerprint from before
 * someone else's write refuses the submit while leaving that write
 * alone. A content type added through it answers where it now lives,
 * its edit situation, by the machine name it was given.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ServedSubmitEndpointTest extends BrowserTestBase {

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
   * Example 2's endpoints.
   */
  protected const STEP2 = 'surface-api/registration.step2/configure';

  /**
   * A valid example 2 payload, every key sent, as the app sends them.
   */
  protected const VALID = [
    'title' => 'Autumn meetup',
    'capacity' => 90,
    'open' => TRUE,
    'venue' => 'harbour',
    'room' => 'harbour_deck',
  ];

  /**
   * Sends a POST to an endpoint as the logged-in account.
   *
   * @param string $path
   *   The path.
   * @param array|string $body
   *   The body, sent as JSON; a string is sent as it is.
   * @param bool $token
   *   Whether to send the session's CSRF token in the header.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function post(string $path, array|string $body, bool $token = TRUE): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token) {
      $this->drupalGet('session/token');
      $headers['X-CSRF-Token'] = $this->getSession()->getPage()->getContent();
    }
    return $this->getHttpClient()->request('POST', $this->buildUrl($path), [
      'headers' => $headers,
      'cookies' => $this->getSessionCookies(),
      'body' => is_string($body) ? $body : (string) json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Decodes a 200 JSON response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return array
   *   The decoded body.
   */
  protected function json(ResponseInterface $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Reads a contract with GET.
   *
   * @param string $path
   *   The contract's path.
   * @param array $query
   *   The situation's parameters.
   *
   * @return array
   *   The contract.
   */
  protected function contract(string $path, array $query = []): array {
    $this->drupalGet($path, ['query' => $query]);
    $this->assertSession()->statusCodeEquals(200);
    return json_decode($this->getSession()->getPage()->getContent(), TRUE);
  }

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
   * Tests a valid submit writes and answers the fresh contract.
   */
  public function testSubmitWritesAndAnswersTheFreshContract(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $loaded = $this->contract(self::STEP2);
    $this->assertIsString($loaded['fingerprint']);

    $answer = $this->json($this->post(self::STEP2 . '/submit', [
      'values' => self::VALID,
      'stale' => [],
      'fingerprint' => $loaded['fingerprint'],
    ]));
    $this->assertSame(['committed', 'valid', 'violations', 'stale', 'outputs', 'contract', 'created'], array_keys($answer));
    $this->assertTrue($answer['committed']);
    $this->assertTrue($answer['valid']);
    $this->assertSame([], $answer['violations']);
    $this->assertSame([], $answer['stale']);
    $this->assertSame([], $answer['outputs']);
    // The situation configures; it creates nothing to move to.
    $this->assertNull($answer['created']);

    // Written, and the contract answered is the one storage now builds:
    // the harbour's rooms, the deck chosen, a new fingerprint.
    $stored = $this->stored();
    $this->assertSame('Autumn meetup', $stored['title']);
    $this->assertSame('harbour_deck', $stored['room']);
    $this->assertSame(90, $stored['capacity']);
    $contract = $answer['contract'];
    $this->assertSame('registration.step2', $contract['surface']);
    $this->assertEquals(self::VALID, array_intersect_key($contract['values'], self::VALID));
    $this->assertSame('harbour_deck', $contract['values']['room']);
    $this->assertContains('harbour_deck', array_column($contract['schema']['properties']['room']['oneOf'], 'const'));
    $this->assertNotSame($loaded['fingerprint'], $contract['fingerprint']);
    $this->assertSame($contract['fingerprint'], $this->contract(self::STEP2)['fingerprint']);
  }

  /**
   * Tests a refused submit, and who may not submit at all.
   */
  public function testRefusalWritesNothing(): void {
    $before = $this->stored();
    $path = self::STEP2 . '/submit';
    $this->assertSame(403, $this->post($path, ['values' => self::VALID], FALSE)->getStatusCode());

    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    // Without the session's token, refused before it is read.
    $this->assertSame(403, $this->post($path, ['values' => self::VALID], FALSE)->getStatusCode());
    // A body that is not a JSON object, or whose parts are the wrong
    // shapes, is the caller's mistake.
    $this->assertSame(400, $this->post($path, '{not json')->getStatusCode());
    $this->assertSame(400, $this->post($path, ['values' => 'harbour'])->getStatusCode());
    $this->assertSame(400, $this->post($path, ['values' => self::VALID, 'stale' => 'room'])->getStatusCode());
    $this->assertSame(400, $this->post($path, ['values' => self::VALID, 'fingerprint' => 7])->getStatusCode());

    // The garden room is the library's: a refusal, answered as data.
    $refused = $this->json($this->post($path, ['values' => ['room' => 'library_garden', 'capacity' => 20] + self::VALID]));
    $this->assertFalse($refused['committed']);
    $this->assertFalse($refused['valid']);
    $this->assertSame(['room'], array_column($refused['violations'], 'path'));
    $this->assertNotSame('', $refused['violations'][0]['message']);
    $this->assertNull($refused['contract']);
    $this->assertNull($refused['created']);

    $this->assertSame($before, $this->stored());
  }

  /**
   * Tests a fingerprint from before another write refuses the submit.
   */
  public function testStaleFingerprintIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $loaded = $this->contract(self::STEP2);

    // Someone else saves between the load and the submit.
    $this->config(self::CONFIG)->set('title', 'Changed elsewhere')->save();
    $elsewhere = $this->stored();

    $refused = $this->json($this->post(self::STEP2 . '/submit', [
      'values' => self::VALID,
      'fingerprint' => $loaded['fingerprint'],
    ]));
    $this->assertFalse($refused['committed']);
    $this->assertFalse($refused['valid']);
    $this->assertCount(1, $refused['violations']);
    $this->assertSame('', $refused['violations'][0]['path']);
    $this->assertStringContainsString('changed since this form was loaded', $refused['violations'][0]['message']);
    $this->assertSame($elsewhere, $this->stored());

    // The rule is opt-in: without a fingerprint the last write wins.
    $written = $this->json($this->post(self::STEP2 . '/submit', ['values' => self::VALID]));
    $this->assertTrue($written['committed']);
    $this->assertSame('Autumn meetup', $this->stored()['title']);
  }

  /**
   * Tests a content type added through the API says where it now lives.
   */
  public function testAddAnswersWhereTheCreatedTypeLives(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer content types',
      'administer data surface node type demo',
    ]));
    // What the app does: the add contract's values, two of them answered.
    $form = $this->contract('surface-api/node.type/add');
    $this->assertNull($form['values']['type']);
    $answer = $this->json($this->post('surface-api/node.type/add/submit', [
      'values' => ['name' => 'Recipe', 'type' => 'recipe', 'title_label' => 'Recipe name'] + $form['values'],
      'stale' => $form['stale'],
      'fingerprint' => $form['fingerprint'],
    ]));
    $this->assertTrue($answer['committed'], (string) json_encode($answer['violations']));
    $this->assertSame([
      'surface' => 'node.type',
      'situation' => 'edit',
      'parameters' => ['type' => 'recipe'],
    ], $answer['created']);

    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $type);
    $this->assertSame('Recipe', $type->label());

    // Where it lives answers with what was written, the machine name
    // locked.
    $edit = $this->contract('surface-api/node.type/edit', $answer['created']['parameters']);
    $this->assertSame('Recipe', $edit['values']['name']);
    $this->assertSame('recipe', $edit['values']['type']);
    $this->assertSame('Recipe name', $edit['values']['title_label']);
    $this->assertTrue($edit['schema']['properties']['type']['x-surface']['locked']);

    // Editing it through the API creates nothing, so it says nowhere.
    $edited = $this->json($this->post('surface-api/node.type/edit/submit', [
      'values' => ['name' => 'Recipes'] + $edit['values'],
      'parameters' => ['type' => 'recipe'],
      'fingerprint' => $edit['fingerprint'],
    ]));
    $this->assertTrue($edited['committed']);
    $this->assertNull($edited['created']);
    $this->assertSame('Recipes', $edited['contract']['values']['name']);

    $type->delete();
    $this->assertNull(NodeType::load('recipe'));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Drupal\Component\Serialization\Yaml;

/**
 * Tests the OpenAPI document over HTTP.
 *
 * `/surface-api/openapi.json` answers 200 as JSON, with the contract
 * version header, to an account that holds the newsletter's permission
 * and to anonymous alike, and the two documents are the same: only the
 * per-request contract and schema differ by account, and the schema
 * route still refuses anonymous. `/surface-api/openapi.yaml` is the same
 * document as YAML.
 *
 * @see docs/decisions.md#an-openapi-document-is-one-more-door
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OpenApiEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_first',
    'data_surface_openapi',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Requests a path as the current session.
   *
   * @param string $path
   *   The path.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function fetch(string $path): ResponseInterface {
    return $this->getHttpClient()->request('GET', $this->buildUrl($path), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Tests the document is the same for an account and for anonymous.
   */
  public function testTheDocumentIsTheSameForEveryone(): void {
    $anonymous = $this->fetch('surface-api/openapi.json');
    $this->assertSame(200, $anonymous->getStatusCode());
    $this->assertStringStartsWith('application/json', $anonymous->getHeaderLine('Content-Type'));
    $this->assertSame('2', $anonymous->getHeaderLine('Contract-Version'));
    $document = Json::decode((string) $anonymous->getBody());
    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertArrayHasKey('/surface-api/first.newsletter/configure/submit', $document['paths']);
    $this->assertSame(403, $this->fetch('surface-api/first.newsletter/configure/schema')->getStatusCode(), 'The per-request schema is still the account\'s.');

    $this->drupalLogin($this->drupalCreateUser(['administer data_surface_first']));
    $account = $this->fetch('surface-api/openapi.json');
    $this->assertSame(200, $account->getStatusCode());
    $this->assertStringStartsWith('application/json', $account->getHeaderLine('Content-Type'));
    $this->assertSame($document, Json::decode((string) $account->getBody()), 'The same document.');
    $this->assertSame(200, $this->fetch('surface-api/first.newsletter/configure/schema')->getStatusCode());

    $yaml = $this->fetch('surface-api/openapi.yaml');
    $this->assertSame(200, $yaml->getStatusCode());
    $this->assertStringStartsWith('application/yaml', $yaml->getHeaderLine('Content-Type'));
    $this->assertSame('2', $yaml->getHeaderLine('Contract-Version'));
    $parsed = Yaml::decode((string) $yaml->getBody());
    $this->assertSame('3.1.0', $parsed['openapi']);
    $this->assertSame(array_keys($document['paths']), array_keys($parsed['paths']));
    $this->assertSame($document['components']['schemas']['first.newsletter.configure'], $parsed['components']['schemas']['first.newsletter.configure']);
  }

}

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
 * document as YAML. Swagger UI (`/surface-api/docs`) and Redoc
 * (`/surface-api/redoc`) are pages over it, under the permission
 * `access data surface api docs`, each script from a CDN pinned and held
 * to its integrity hash.
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
    $this->assertSame('4', $anonymous->getHeaderLine('Contract-Version'));
    $document = Json::decode((string) $anonymous->getBody());
    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertSame(['get', 'patch'], array_keys($document['paths']['/surface-api/first.newsletter']));
    $this->assertSame(403, $this->fetch('surface-api/first.newsletter/schema')->getStatusCode(), 'The per-request schema is still the account\'s.');

    $this->drupalLogin($this->drupalCreateUser(['administer data_surface_first']));
    $account = $this->fetch('surface-api/openapi.json');
    $this->assertSame(200, $account->getStatusCode());
    $this->assertStringStartsWith('application/json', $account->getHeaderLine('Content-Type'));
    $this->assertSame($document, Json::decode((string) $account->getBody()), 'The same document.');
    $this->assertSame(200, $this->fetch('surface-api/first.newsletter/schema')->getStatusCode());

    $yaml = $this->fetch('surface-api/openapi.yaml');
    $this->assertSame(200, $yaml->getStatusCode());
    $this->assertStringStartsWith('application/yaml', $yaml->getHeaderLine('Content-Type'));
    $this->assertSame('4', $yaml->getHeaderLine('Contract-Version'));
    $parsed = Yaml::decode((string) $yaml->getBody());
    $this->assertSame('3.1.0', $parsed['openapi']);
    $this->assertSame(array_keys($document['paths']), array_keys($parsed['paths']));
    $this->assertSame($document['components']['schemas']['first.newsletter.configure'], $parsed['components']['schemas']['first.newsletter.configure']);
  }

  /**
   * Tests the Swagger UI and Redoc pages.
   */
  public function testTheDocumentationPages(): void {
    // Under a permission of their own: each runs a CDN's script.
    $this->assertSame(403, $this->fetch('surface-api/docs')->getStatusCode());
    $this->assertSame(403, $this->fetch('surface-api/redoc')->getStatusCode());
    $this->drupalLogin($this->drupalCreateUser(['access data surface api docs']));

    $this->drupalGet('surface-api/docs');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#data-surface-openapi-docs');
    $this->assertSession()->elementExists('css', 'script[src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.29.1/swagger-ui-bundle.min.js"][integrity^="sha512-"][crossorigin="anonymous"]');
    $this->assertSession()->elementExists('css', 'link[href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.29.1/swagger-ui.min.css"][integrity^="sha512-"]');
    $settings = $this->getDrupalSettings()['dataSurfaceOpenApi'];
    $this->assertStringEndsWith('/surface-api/openapi.json', $settings['document']);
    $this->assertStringEndsWith('/session/token', $settings['token']);

    $this->drupalGet('surface-api/redoc');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#data-surface-openapi-reference');
    $this->assertSession()->elementExists('css', 'script[src="https://cdn.jsdelivr.net/npm/redoc@2.5.1/bundles/redoc.standalone.js"][integrity^="sha256-"]');
  }

}

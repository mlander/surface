<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\NestedArray;
use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\ResourceBuild\ServedPathsInterface;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the React app's fixtures hold the documents the emitter writes now.
 *
 * Each fixture under modules/surface_examples_react/app/src/test/fixtures
 * holds one or more documents: an envelope (`resource`, `operation`,
 * `values`, `schema`, a stale path in `stale`), whose schema is emit()'s
 * for its values, or a full document (`$schema`, `$id`), emitFull()'s for
 * the choices in its `$id`. Each is emitted again here, in a site holding
 * the example modules the fixture was taken from, and the fixture's bytes
 * must be what that emission writes: the file decoded, each document
 * replaced by its emission, encoded as the file was (four spaces or two).
 * A refine fixture's steps are asked of the server again, each answer
 * whole (answered()).
 * SURFACE_REACT_FIXTURES_WRITE=1 writes the fixtures again.
 *
 * @see docs/decisions.md#the-checks-the-document-names
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReactFixturesTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use UserCreationTrait;

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
    'surface_http',
    'surface_examples',
    'surface_resource_test',
  ];

  /**
   * The fixtures' directory.
   */
  protected const FIXTURES = __DIR__ . '/../../../modules/surface_examples_react/app/src/test/fixtures/';

  /**
   * The flag that writes the fixtures again.
   */
  protected const WRITE = 'SURFACE_REACT_FIXTURES_WRITE';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples', 'surface_resource_test']);
  }

  /**
   * Tests the fixtures taken without the compliance module.
   */
  public function testWithoutCompliance(): void {
    $this->setUpCurrentUser(admin: TRUE);
    $this->assertFixtures([
      'example2-library.json',
      'example2.json',
      'refine-bulletin.json',
      'refine-example2.json',
      'refine-example3.json',
      'refine-sales.json',
    ]);
  }

  /**
   * Tests the journal's fixture, for an account that configures the site.
   */
  public function testJournal(): void {
    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->assertFixtures(['refine-journal.json']);
  }

  /**
   * Tests the spotlight's fixture, a list of two spelled out.
   */
  public function testSpotlight(): void {
    $this->setUpCurrentUser(admin: TRUE);
    $this->config('surface.settings')->set('site_list_limit', 2)->save();
    $this->assertFixtures(['deferred-spotlight.json']);
  }

  /**
   * Tests the fixtures taken with the compliance module.
   */
  public function testWithCompliance(): void {
    $this->setUpCurrentUser(admin: TRUE);
    $this->enableModules(['surface_examples_compliance']);
    $this->assertFixtures(['example3.json', 'refine-example3-compliance.json', 'refine-example3-license.json']);
  }

  /**
   * Tests every fixture is one of the four sites above.
   */
  public function testEveryFixtureIsHeld(): void {
    $held = [
      'deferred-spotlight.json', 'example2-library.json', 'example2.json', 'example3.json', 'refine-bulletin.json', 'refine-example2.json',
      'refine-example3-compliance.json', 'refine-example3-license.json', 'refine-example3.json', 'refine-journal.json', 'refine-sales.json',
    ];
    $files = array_map('basename', glob(self::FIXTURES . '*.json') ?: []);
    sort($files);
    $this->assertSame($held, $files, 'Each fixture is emitted again by one test of this class.');
  }

  /**
   * Holds each fixture named to what the emitter writes, or writes it.
   *
   * @param string[] $names
   *   The fixtures' file names.
   */
  protected function assertFixtures(array $names): void {
    $emitter = $this->emitter();
    foreach ($names as $name) {
      $file = self::FIXTURES . $name;
      $raw = (string) file_get_contents($file);
      $fixture = json_decode($raw);
      $this->assertNotNull($fixture, $name);
      $count = 0;
      $fixture = $this->emitted($fixture, $emitter, $name, $count);
      $this->assertGreaterThan(0, $count, $name . ' holds a document.');
      if ($fixture instanceof \stdClass && isset($fixture->steps, $fixture->contract)) {
        $this->answered($fixture);
      }
      $json = (string) json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
      if (str_starts_with($raw, "{\n  \"")) {
        // Written with two spaces a level.
        $json = (string) preg_replace_callback('/^( +)/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);
      }
      if (getenv(self::WRITE)) {
        file_put_contents($file, $json);
        continue;
      }
      $this->assertSame($raw, $json, sprintf('%s is what the emitter writes now; written again with %s=1.', $name, self::WRITE));
    }
    if (getenv(self::WRITE)) {
      $this->markTestSkipped('The React fixtures were written.');
    }
  }

  /**
   * Asks the server each step of a recorded chain, as the app sends it.
   *
   * Each step's values are the answer before it (the envelope, for the
   * first) with the step's changes set, posted to the schema with the
   * stale paths that answer named, less those the changes set, in
   * X-Surface-Stale; a step recorded with its `sent` values sends those.
   * The step's `sent` and `answer` become what was sent and what the
   * server answers: the envelope whole, its values, stale paths, notes
   * and discarded answers included.
   *
   * @param \stdClass $fixture
   *   The fixture: its `contract` and its `steps`.
   */
  protected function answered(\stdClass $fixture): void {
    $resource = (string) $fixture->contract->resource;
    $previous = json_decode((string) json_encode($fixture->contract), TRUE);
    foreach ($fixture->steps as $step) {
      $values = $previous['values'];
      $stale = $previous['stale'] ?? [];
      foreach ($step->changes as $change) {
        $parents = explode('.', (string) $change->path);
        NestedArray::setValue($values, $parents, json_decode((string) json_encode($change->value), TRUE), TRUE);
        $pointer = '/' . implode('/', $parents);
        $stale = array_values(array_filter($stale, static fn (string $path): bool => $path !== $pointer && !str_starts_with($path, $pointer . '/')));
      }
      if (isset($step->sent->values)) {
        // Recorded as sent: values the app holds that no answer gave.
        $values = json_decode((string) json_encode($step->sent->values), TRUE);
        $stale = array_map('strval', $step->sent->stale ?? []);
      }
      else {
        $step->sent = (object) ['stale' => $stale];
      }
      $answer = $this->served('POST', '/surface-api/' . $resource . '/schema', $values, ['X-Surface-Stale' => implode(',', $stale)])->getContent();
      $step->answer = json_decode((string) $answer);
      $previous = json_decode((string) $answer, TRUE);
    }
  }

  /**
   * Replaces each document in a fixture's value by its emission.
   *
   * @param mixed $node
   *   The value, as JSON decodes it to objects.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter.
   * @param string $name
   *   The fixture's name, for the messages.
   * @param int $count
   *   Counts the documents.
   *
   * @return mixed
   *   The value, each document emitted again.
   */
  protected function emitted(mixed $node, ContractEmitter $emitter, string $name, int &$count): mixed {
    if (is_array($node)) {
      return array_map(fn (mixed $item): mixed => $this->emitted($item, $emitter, $name, $count), $node);
    }
    if (!$node instanceof \stdClass) {
      return $node;
    }
    if (isset($node->resource, $node->operation, $node->schema) && $node->schema instanceof \stdClass) {
      $count++;
      $node->schema = $this->decoded($this->envelope($node, $emitter, $name));
      return $node;
    }
    if (isset($node->{'$schema'}, $node->{'$id'})) {
      $count++;
      return $this->decoded($this->full($node, $emitter, $name));
    }
    foreach (get_object_vars($node) as $key => $value) {
      $node->{$key} = $this->emitted($value, $emitter, $name, $count);
    }
    return $node;
  }

  /**
   * Emits an envelope's schema for its values.
   *
   * @param \stdClass $envelope
   *   The envelope.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter.
   * @param string $name
   *   The fixture's name.
   *
   * @return array
   *   The schema.
   */
  protected function envelope(\stdClass $envelope, ContractEmitter $emitter, string $name): array {
    $resource = (string) $envelope->resource;
    $operation = (string) $envelope->operation;
    $id = (string) ($envelope->schema->{'$id'} ?? '');
    [$class, $context, $label, $facts] = $this->operation($resource, $operation, $name);
    $values = json_decode((string) json_encode($envelope->values ?? []), TRUE);
    // A stale path stands for its stored value, as the narrowing's
    // refinement overlay names it.
    foreach ($envelope->stale ?? [] as $pointer) {
      $parents = explode('/', ltrim((string) $pointer, '/'));
      $values[SurfaceFormBuilderInterface::STANDING_KEY][implode('.', $parents)] = NestedArray::getValue($values, $parents);
      NestedArray::setValue($values, $parents, NULL, TRUE);
    }
    $base = strtok($id, '?');
    $resources = $this->container->get('surface.resources');
    return $emitter->emit($resources->build($class, $context), $values, $resource, $operation, $label, TRUE, $facts, $id, schema_url: $base . '/schema', lookup_url: $this->lookup($id, $resource))->document['schema'];
  }

  /**
   * Emits a full document for the choices its `$id` names.
   *
   * @param \stdClass $document
   *   The document.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter.
   * @param string $name
   *   The fixture's name.
   *
   * @return array
   *   The document.
   */
  protected function full(\stdClass $document, ContractEmitter $emitter, string $name): array {
    $id = (string) $document->{'$id'};
    $this->assertMatchesRegularExpression('#/surface-api/([^/?]+)/schema#', $id, $name);
    preg_match('#/surface-api/([^/?]+)/schema#', $id, $match);
    $resource = rawurldecode($match[1]);
    $operation = (string) ($document->{'x-surface'}->operation->id ?? 'configure');
    [$class, $context, $label, $facts] = $this->operation($resource, $operation, $name);
    $declared = $this->container->get('surface.resources')->buildStatic($class, $context);
    $choices = Choices::resolve($declared, Choices::fromQuery((string) parse_url($id, PHP_URL_QUERY)));
    return $emitter->emitFull($declared, $resource, $operation, $label, TRUE, $facts, $id, $choices, (string) strtok($id, '?'), $this->lookup($id, $resource))->document['schema'];
  }

  /**
   * Finds an operation of a resource.
   *
   * @param string $resource
   *   The resource id.
   * @param string $operation
   *   The operation id.
   * @param string $name
   *   The fixture's name.
   *
   * @return array{0: string, 1: \Drupal\surface\Resource\OperationContext, 2: string|\Stringable|null, 3: array}
   *   The resource's class, the operation's context, its label and its
   *   facts.
   */
  protected function operation(string $resource, string $operation, string $name): array {
    $registry = $this->container->get('surface.resource_registry');
    $class = $registry->getDefinition($resource)->name();
    $this->assertNotEmpty($class, $name . ': ' . $resource);
    $declared = $registry->getOperation($class, $operation);
    $context = $this->container->get('surface.resources')->operation($class, $operation);
    return [$class, $context, $declared->label, $declared->facts()];
  }

  /**
   * Writes the lookup link a document's `$id` implies.
   *
   * @param string $id
   *   The document's `$id`.
   * @param string $resource
   *   The resource id.
   *
   * @return string
   *   The lookup URL, on the `$id`'s origin.
   */
  protected function lookup(string $id, string $resource): string {
    $origin = preg_match('#^(https?://[^/]+)/#', $id, $match) ? $match[1] : '';
    return $origin . '/surface-api/' . $resource . ':lookup';
  }

  /**
   * Decodes an emitted document as the fixture's JSON decodes.
   *
   * @param array $document
   *   The document.
   *
   * @return mixed
   *   It, objects as objects.
   */
  protected function decoded(array $document): mixed {
    return json_decode((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
  }

  /**
   * Builds the emitter with the paths the fixtures were served on.
   *
   * @return \Drupal\surface\Contract\ContractEmitter
   *   The emitter.
   */
  protected function emitter(): ContractEmitter {
    $paths = new class implements ServedPathsInterface {

      /**
       * {@inheritdoc}
       */
      public function schemaPath(string $resource, string $operation): string {
        return '/surface-api/' . $resource . '/schema';
      }

      /**
       * {@inheritdoc}
       */
      public function lookupPath(string $resource, string $operation): string {
        return '/surface-api/' . $resource . ':lookup';
      }

    };
    $c = $this->container;
    return new ContractEmitter($c->get('surface.options'), $c->get('string_translation'), $c->get('validation.constraint'), $c->get('surface.site_list_budget'), $c->get('surface.widget_choice'), $paths);
  }

}

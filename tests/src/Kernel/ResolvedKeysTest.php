<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\Resolve;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_resource_test\Compute\VaultToken;
use Drupal\surface_resource_test\OutsideDiscovery\NorthVaultAlter;
use Drupal\surface_resource_test\OutsideDiscovery\RelayHubResource;
use Drupal\surface_resource_test\OutsideDiscovery\RelayLinkStructure;
use Drupal\surface_resource_test\OutsideDiscovery\SouthVaultAlter;
use Drupal\surface_resource_test\Resource\Relay\PlainRelayResource;
use Drupal\surface_resource_test\Resource\Relay\RelayResource;
use Drupal\surface_resource_test\Storage\RelayHubStorage;
use Drupal\surface_resource_test\Storage\RelayStorage;
use Drupal\surface_resource_test\Structure\ThresholdStructure;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a key resolved at use: declared, read through resolved(), unstated.
 *
 * The relay fixture stores a pasted token and the name of a vault entry,
 * and resolves the token from the entry with VaultToken; its twin declares
 * the same keys with no resolve.
 *
 * @see docs/decisions.md#a-key-may-stand-for-another-at-use
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ResolvedKeysTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Stores the relay's values, as storage holds them.
   *
   * @param array $values
   *   The values.
   */
  protected function store(array $values): void {
    $this->container->get('state')->set(RelayStorage::KEY, $values);
  }

  /**
   * Reads the relay's values as the code that uses it needs them.
   *
   * @return array
   *   The values, the resolve applied.
   */
  protected function resolved(): array {
    return $this->container->get('surface.resources')->resolved(RelayResource::class);
  }

  /**
   * Tests resolved() puts the entry's token at the key, and stores nothing.
   */
  public function testResolvedAppliesTheResolve(): void {
    $this->store(['token' => 'pasted-1', 'vault_id' => 'north']);
    $this->assertSame(['token' => 'tok-north-1', 'vault_id' => 'north'], $this->resolved());
    $this->assertSame('pasted-1', $this->container->get('state')->get(RelayStorage::KEY)['token'], 'What is resolved is never written back.');

    // A write reads what is stored, never what is resolved.
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation(RelayResource::class, 'configure');
    $built = $resources->build(RelayResource::class, $context);
    $this->assertSame(['token' => 'pasted-1', 'vault_id' => 'north'], $resources->storage(RelayResource::class, $context, $built)->load($built));
    $result = $this->pipeline()->submit($built, ['vault_id' => 'south'], $resources->storage(RelayResource::class, $context, $built));
    $this->assertTrue($result->isValid());
    $this->assertSame(['token' => 'pasted-1', 'vault_id' => 'south'], $this->container->get('state')->get(RelayStorage::KEY));
    $this->assertSame('tok-south-2', $this->resolved()['token']);
    $declared = Resolve::declared($built);
    $this->assertSame(['token'], array_keys($declared));
    $this->assertEquals(new Resolve(['vault_id' => 'vault_id'], VaultToken::class, 'resource_test.relay'), $declared['token'][0]);
  }

  /**
   * Tests the resolve is skipped while the entry holds nothing.
   *
   * Absent, NULL or empty, the stored token stands; an entry the class
   * knows no token for resolves to nothing, and the stored token does not
   * stand in for it.
   */
  public function testTheStoredValueStandsWhileTheSourceHoldsNothing(): void {
    $this->store(['token' => 'pasted-1']);
    $this->assertSame(['token' => 'pasted-1'], $this->resolved());
    $this->store(['token' => 'pasted-1', 'vault_id' => NULL]);
    $this->assertSame('pasted-1', $this->resolved()['token']);
    $this->store(['token' => 'pasted-1', 'vault_id' => '']);
    $this->assertSame('pasted-1', $this->resolved()['token']);
    $this->store(['token' => 'pasted-1', 'vault_id' => 'west']);
    $this->assertNull($this->resolved()['token'], 'An entry the class knows nothing of resolves to nothing.');
    $this->store([]);
    $this->assertSame([], $this->resolved(), 'Nothing stored, nothing resolved.');
  }

  /**
   * Tests neither document nor the tool carries the resolve.
   *
   * The relay and its twin emit one full schema, one schema for the
   * values stored and one tool input schema; no `x-surface` says resolve.
   */
  public function testTheContractDoesNotCarryIt(): void {
    $this->store(['token' => 'pasted-1', 'vault_id' => 'north']);
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $documents = [];
    foreach ([RelayResource::class, PlainRelayResource::class] as $class) {
      $built = $resources->buildOperation($class, 'configure');
      $values = $resources->storage($class, $resources->operation($class, 'configure'), $built)->load($built);
      $documents[$class] = [
        'full' => $emitter->emitFull($built, 'resource_test.relay', 'configure', 'Configure the relay')->document,
        'these' => $emitter->emit($built, $values, 'resource_test.relay', 'configure', 'Configure the relay', TRUE)->document,
      ];
    }
    $this->assertSame($documents[PlainRelayResource::class], $documents[RelayResource::class]);
    $full = $documents[RelayResource::class]['full']['schema'];
    $this->assertArrayNotHasKey('resolve', $full['properties']['token']['x-surface'] ?? []);
    $this->assertArrayNotHasKey('computed', $full['properties']['token']['x-surface'] ?? []);
    $this->assertArrayNotHasKey('readOnly', $full['properties']['token']);
    $encoded = (string) json_encode($documents[RelayResource::class]);
    $this->assertStringNotContainsString('tok-north-1', $encoded, 'The resolved value is in no document.');
    $this->assertStringNotContainsString('VaultToken', $encoded);

    $tools = $this->container->get('plugin.manager.tool');
    $serializer = $this->container->get('tool.definition_serializer');
    $input = static fn (string $id): array => $serializer->normalizeInputSchema($tools->createInstance('surface:' . $id . ':configure'))['properties'][OperationInputs::VALUES]['properties'];
    $this->assertSame($input('resource_test.relay.plain'), $input('resource_test.relay'));
    $read = $tools->createInstance('surface:resource_test.relay:configure:read');
    $read->execute();
    $this->assertSame(['vault_id' => 'north'], (array) $read->getResult()->getContextValues()[OperationInputs::VALUES], 'The read answers what is stored, less the secret.');
  }

  /**
   * Tests what resolve() refuses where it is declared.
   */
  public function testTheBlueprintRefusesWhatIsNoResolve(): void {
    $typed = $this->container->get('typed_data_manager');
    $registry = $this->container->get('surface.resource_registry');
    $blueprint = static fn (bool $outputs = FALSE, ?string $owner = NULL): ResourceBlueprint => new ResourceBlueprint(new SurfaceBuilder(), $typed, $outputs, $registry, $owner);
    $refused = function (callable $declare, string $message): void {
      try {
        $declare();
        $this->fail('Refused: ' . $message);
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    };
    $refused(fn () => $blueprint(TRUE)->resolve('token', ['vault_id'], VaultToken::class), 'resolves an output');
    // A structure resolves among its own keys, as a resource does.
    $structure = $blueprint(owner: ThresholdStructure::class);
    $structure->add('token', 'string', 'Token');
    $structure->add('vault_id', 'string', 'Vault entry');
    $structure->resolve('token', ['vault_id'], VaultToken::class);
    $inputs = $blueprint();
    $token = $inputs->add('token', 'string', 'Token');
    $inputs->add('vault_id', 'string', 'Vault entry');
    $inputs->add('settings', 'map', 'Settings');
    $refused(fn () => $inputs->resolve('secret', ['vault_id'], VaultToken::class), 'resolve("secret") in this blueprint names a key nothing has declared');
    $refused(fn () => $inputs->resolve('token', ['vault'], VaultToken::class), 'is resolved from "vault", which is no key declared');
    $refused(fn () => $inputs->resolve('token', ['token'], VaultToken::class), 'is resolved from "token", which is the key itself');
    $refused(fn () => $inputs->resolve('token', [], VaultToken::class), 'names no key it is resolved from');
    $refused(fn () => $inputs->resolve('token', ['vault_id'], DataDefinition::class), 'which implements no');
    $refused(fn () => $inputs->resolve('settings', ['vault_id'], VaultToken::class), 'names a map or a list');
    $inputs->resolve('token', ['vault_id'], VaultToken::class);
    $this->assertEquals(new Resolve(['vault_id' => 'vault_id'], VaultToken::class, 'this blueprint'), Resolve::of($token));
    $refused(fn () => $inputs->resolve('token', ['vault_id'], VaultToken::class), 'resolve("token") in this blueprint names a key this blueprint resolves already. A key is resolved once');
  }

  /**
   * Tests a structure's resolve applies wherever the structure is held.
   *
   * The relay hub holds the link structure three ways: mixed in, its keys
   * the hub's own; attached at `primary`; and in each item of `backups`.
   * The link resolves its token from its own vault entry, by paths of its
   * own, applied under the key it is held at.
   */
  public function testResolveInsideStructureIsWalked(): void {
    $registry = new ResourceRegistry([
      'resources' => [
        RelayHubResource::class => 'surface_resource_test',
        RelayLinkStructure::class => 'surface_resource_test',
      ],
      'operations' => [RelayHubResource::class => 'surface_resource_test'],
    ], $this->container->get('cache.discovery'));
    $resources = $this->container->get('surface.resources')->withRegistry($registry, $this->container->get('class_resolver'));
    $built = $resources->buildOperation(RelayHubResource::class, 'configure');
    $declared = Resolve::declared($built);
    $this->assertSame(['token', 'primary.token', 'backups.*.token'], array_keys($declared));
    $this->assertSame(['vault_id' => 'vault_id'], $declared['primary.token'][0]->from, 'The structure\'s own path, applied under its key.');

    $this->container->get('state')->set(RelayHubStorage::KEY, [
      'token' => 'pasted-0',
      'vault_id' => 'north',
      'primary' => ['token' => 'pasted-1', 'vault_id' => 'south'],
      'backups' => [
        ['token' => 'pasted-2', 'vault_id' => 'north'],
        ['token' => 'pasted-3'],
      ],
    ]);
    $this->assertSame([
      'token' => 'tok-north-1',
      'vault_id' => 'north',
      'primary' => ['token' => 'tok-south-2', 'vault_id' => 'south'],
      'backups' => [
        ['token' => 'tok-north-1', 'vault_id' => 'north'],
        ['token' => 'pasted-3'],
      ],
    ], $resources->resolved(RelayHubResource::class), 'Mixed in, attached and in each item; an item whose entry holds nothing keeps its token.');
    $this->assertSame('pasted-1', $this->container->get('state')->get(RelayHubStorage::KEY)['primary']['token'], 'Nothing resolved is written back.');
  }

  /**
   * Tests two alters resolving one key are refused, naming both.
   *
   * Two resolves cannot compose: while both entries held a value there
   * would be no right answer, and each alter rules out the owner's key
   * alone, never the other alter's.
   */
  public function testTwoAltersResolvingOneKeyAreRefused(): void {
    $registry = new ResourceRegistry([
      'resources' => [PlainRelayResource::class => 'surface_resource_test'],
      'operations' => [PlainRelayResource::class => 'surface_resource_test'],
      'alters' => [NorthVaultAlter::class => 'north_vault', SouthVaultAlter::class => 'south_vault'],
    ], $this->container->get('cache.discovery'));
    $resources = $this->container->get('surface.resources')->withRegistry($registry, $this->container->get('class_resolver'));
    try {
      $resources->buildOperation(PlainRelayResource::class, 'configure');
      $this->fail('Two alters resolving one key were not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame('resolve("token") in the south_vault alter names a key the north_vault alter resolves already. A key is resolved once: while both resolves\' sources held a value there would be no right answer, and each alter rules out the owner\'s key alone, never another alter\'s. One module stands in for a key.', $e->getMessage());
    }
  }

  /**
   * Tests an alter's own keys are read from its mount, and no other's.
   */
  public function testAnAltersKeysAreReadFromItsMount(): void {
    $typed = $this->container->get('typed_data_manager');
    $builder = new SurfaceBuilder();
    (new ResourceBlueprint($builder, $typed))->add('token', 'string', 'Token');
    $vault = new ResourceBlueprintAdditions($builder, $typed, 'vault');
    $vault->add('vault_id', 'string', 'Vault entry');
    try {
      (new ResourceBlueprintAdditions($builder, $typed, 'other'))->resolve('token', from: ['vault_id'], using: VaultToken::class);
      $this->fail('Another module\'s key is no source of this alter\'s.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('resolve("token") in the other alter is resolved from "vault_id", which is no key declared', $e->getMessage());
    }
    $vault->resolve('token', from: ['vault_id'], using: VaultToken::class);
    $this->assertEquals(new Resolve(['vault_id' => 'third_party_settings.vault.vault_id'], VaultToken::class, 'the vault alter'), Resolve::of($builder->getDefinition('token')));
    $built = $builder->seal();
    $declared = Resolve::declared($built);
    $this->assertSame(['token'], array_keys($declared));
    $this->assertSame(['vault_id' => 'third_party_settings.vault.vault_id'], $declared['token'][0]->from);
    $this->assertSame(
      ['token' => 'tok-south-2', 'third_party_settings' => ['vault' => ['vault_id' => 'south']]],
      Resolve::apply($built, ['token' => 'pasted-1', 'third_party_settings' => ['vault' => ['vault_id' => 'south']]]),
    );
  }

}

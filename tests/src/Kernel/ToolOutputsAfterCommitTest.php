<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\surface\Pipeline\NotEmitted;
use Drupal\surface\Pipeline\SurfacePipeline;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests a committed write is never answered as a refusal.
 *
 * The site's pipeline is wrapped so its outputs() answers what a storage
 * with a bug would answer after its write: outputs that do not hold to
 * the declaration. The runner leaves out every NotEmitted key whether or
 * not they conform, and the write tool answers only the outputs the Tool
 * API's own check takes, so the write is answered as written. When that
 * leaves out an output the Tool API requires, the Tool API fails the
 * call, and the failure carries the document of the write, which says it
 * was committed.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ToolOutputsAfterCommitTest extends SurfaceKernelTestBase {

  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'tool',
    'surface',
    'surface_stage_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('surface_test.inner_pipeline', SurfacePipeline::class)->setArguments([
      new Reference('typed_data_manager'),
      new Reference('surface.options'),
      new Reference('string_translation'),
    ]);
    $container->register('surface.pipeline', MisreportingPipeline::class)->setArguments([new Reference('surface_test.inner_pipeline')]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
  }

  /**
   * Gets the wrapped pipeline.
   *
   * @return \Drupal\Tests\surface\Kernel\MisreportingPipeline
   *   The pipeline.
   */
  protected function pipeline(): MisreportingPipeline {
    $pipeline = $this->container->get('surface.pipeline');
    assert($pipeline instanceof MisreportingPipeline);
    return $pipeline;
  }

  /**
   * Opens a stage holding one write to the ledger row `a`.
   *
   * @return array{0: string, 1: array}
   *   The stage's id, and the outputs a dry run of its apply reported.
   */
  protected function oneWrite(): array {
    $this->ledger('a', 'Alpha');
    $id = $this->open('Mine');
    $staged = $this->runTool('surface:stage_test.ledger:update', [
      'id' => 'a',
      OperationInputs::STAGE => $id,
      OperationInputs::VALUES => ['title' => 'Beta'],
    ]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->assertTrue($this->stageTool('apply', $id, TRUE)['success']);
    return [$id, $this->pipeline()->real];
  }

  /**
   * Tests outputs the storage misreports leave the write answered as one.
   *
   * Each applied write's id is NotEmitted, and a key no output declares is
   * added: the surface refuses them, which the runner logs. The id is left
   * out, the extra key too, and the result says so in its message.
   */
  public function testMisreportedOutputsAreLeftOut(): void {
    [$id, $real] = $this->oneWrite();
    $this->assertNotEmpty($real['applied']);
    foreach (array_keys($real['applied']) as $index) {
      $real['applied'][$index]['id'] = NotEmitted::value();
    }
    $this->pipeline()->outputs = $real + ['bogus' => 'Not declared.'];

    // A dry run answers a dry run, and writes nothing.
    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertFalse($dry['outputs'][OperationInputs::COMMITTED]);
    $this->assertStringStartsWith('Dry run of ', $dry['message']);
    $this->assertSame('Alpha', $this->row('a')['title']);

    // The apply is written, and answered as written, through the Tool
    // API's runner.
    $result = $this->container->get('tool.runner')->run('surface:surface.stage:apply', [
      'id' => $id,
      OperationInputs::VALUES => [],
      OperationInputs::DRY_RUN => FALSE,
    ])->getResult();
    $this->assertSame('Beta', $this->row('a')['title'], 'The apply was written.');
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $outputs = $result->getContextValues();
    $this->assertTrue($outputs[OperationInputs::OK]);
    $this->assertTrue($outputs[OperationInputs::COMMITTED]);
    $this->assertArrayNotHasKey('bogus', $outputs);
    $this->assertCount(count($real['applied']), $outputs['applied']);
    foreach ($outputs['applied'] as $applied) {
      $this->assertArrayNotHasKey('id', $applied, 'A key that is not emitted is left out.');
    }
    $this->assertStringContainsString('Outputs left out, since the tool does not declare them in that shape; they were logged: bogus.', (string) $result->getMessage());
  }

  /**
   * Tests a required output left out fails the call, saying it committed.
   *
   * The applied writes are answered as text, not a list: the Tool API's
   * check does not take them, so they are left out, and the Tool API
   * requires them. It fails the call; its document is the write's.
   */
  public function testRequiredOutputLeftOutFailsSayingItCommitted(): void {
    [$id] = $this->oneWrite();
    $this->pipeline()->outputs = ['applied' => 'Not a list.'];

    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertFalse($dry['success']);
    $this->assertSame(FailureCategory::Runtime, $dry['category']);
    $this->assertFalse($dry['outputs'][OperationInputs::COMMITTED], 'A dry run commits nothing.');
    $this->assertTrue($dry['outputs'][OperationInputs::OK]);

    $applied = $this->stageTool('apply', $id);
    $this->assertSame('Beta', $this->row('a')['title'], 'The apply was written.');
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Runtime, $applied['category']);
    $this->assertStringContainsString('applied: This output is required but was not provided.', $applied['message']);
    $this->assertTrue($applied['outputs'][OperationInputs::OK]);
    $this->assertTrue($applied['outputs'][OperationInputs::COMMITTED], 'The document says the write was committed.');
    $this->assertSame([], $applied['outputs'][OperationInputs::VIOLATIONS]);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests drush generate surface:resource on a contributed module's settings.
 *
 * Pathauto's settings, `--from-config-schema=pathauto.settings`: a config
 * object that is not fully validatable, whose keys mostly have no label
 * and whose punctuation is a sequence stored as a map. Skipped where the
 * code base has no Pathauto.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ResourceGeneratorPathautoTest extends ResourceGeneratorTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'path',
    'token',
    'pathauto',
    'data_surface',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Where Composer and a hand install put a contributed module.
    $root = dirname(__DIR__, 6);
    foreach (['pathauto', 'token'] as $module) {
      if (glob($root . '/modules/{,*/}' . $module . '/' . $module . '.info.yml', GLOB_BRACE) === []) {
        $this->markTestSkipped(sprintf('The code base has no %s.', $module));
      }
    }
    parent::setUp();
  }

  /**
   * Tests each key is written, and what the schema could not say is named.
   */
  public function testTheKeysOfPathautoSettings(): void {
    $out = $this->sandbox . '/pathauto';
    $display = $this->generate([
      'data_surface_first', 'adopter.generated.pathauto', 'Pathauto settings', '', '1',
      'Configure Pathauto',
      'Sets how Pathauto writes aliases: the separator, case, lengths, punctuation and the words it removes.',
      '', '', '',
    ], ['--from-config-schema' => 'pathauto.settings'], $out);
    $resource = $this->written('src/Resource/PathautoResource.php', $out);
    $this->assertStringContainsString(<<<'PHP'
  public static function defineInputs(BlueprintInterface $inputs): void {
    // The schema gives no label to punctuation, verbose, separator, max_length,
    // max_component_length, transliterate, reduce_ascii, case, ignore_words,
    // update_action: each is named from its key.
    $inputs->addList('enabled_entity_types', 'string', t('Enabled entity types'), default: ['user']);
    // The site holds {"hyphen":1} at punctuation now, which is not a value of
    // the key as its schema reads: no default is written.
    $inputs->addList('punctuation', 'integer', t('Punctuation'));
    $inputs->add('verbose', 'boolean', t('Verbose'), default: FALSE);
    $inputs->add('separator', 'string', t('Separator'), default: '-');
PHP, $resource, 'Defaults from the install file; no key required, the object not being fully validatable.');
    $storage = $this->written('src/Storage/PathautoStorage.php', $out);
    $this->assertStringContainsString("public const CONFIG = 'pathauto.settings';", $storage);
    $this->assertStringContainsString("    'safe_tokens',\n  ];", $storage, 'Each optional key with a default that refuses null is always held.');
    $this->assertStringNotContainsString("'punctuation'", $storage);
    $this->assertFileDoesNotExist($out . '/config/schema/data_surface_first.schema.yml');
    $this->assertStringContainsString('No config schema entry and no install file: pathauto.settings is pathauto\'s', $display);
    $this->assertParses(['src/Resource/PathautoResource.php', 'src/Storage/PathautoStorage.php'], $out);
  }

}

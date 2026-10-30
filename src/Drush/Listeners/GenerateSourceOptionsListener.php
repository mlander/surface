<?php

declare(strict_types=1);

namespace Drupal\surface\Drush\Listeners;

use Drupal\surface\Drush\Generators\ResourceGenerator;
use Drush\Event\ConsoleDefinitionsEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Lets `drush generate` take the resource generator's two source options.
 *
 * Drush hands a generator only its answers and its own few options, so
 * `--from-config-schema` and `--from-json-schema` are declared on the
 * `generate` command too, where the generator reads them from Drush's
 * input (ResourceGenerator::sourceOption()).
 */
#[AsEventListener]
final class GenerateSourceOptionsListener {

  /**
   * Adds the options to the `generate` command.
   *
   * @param \Drush\Event\ConsoleDefinitionsEvent $event
   *   The event, fired once the commands are known.
   */
  public function __invoke(ConsoleDefinitionsEvent $event): void {
    $application = $event->getApplication();
    if (!$application->has('generate')) {
      return;
    }
    $command = $application->get('generate');
    if (!$command->getDefinition()->hasOption(ResourceGenerator::FROM_CONFIG_SCHEMA)) {
      $command->addOption(ResourceGenerator::FROM_CONFIG_SCHEMA, '', InputOption::VALUE_REQUIRED, 'surface:resource: read the keys from a config schema entry on the site, such as node.settings.');
    }
    if (!$command->getDefinition()->hasOption(ResourceGenerator::FROM_JSON_SCHEMA)) {
      $command->addOption(ResourceGenerator::FROM_JSON_SCHEMA, '', InputOption::VALUE_REQUIRED, 'surface:resource: read the keys from a JSON Schema document, a .json or .yml file, or a component\'s .component.yml.');
    }
  }

}

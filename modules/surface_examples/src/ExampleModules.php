<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

/**
 * Every example module, in the two groups the examples list uses.
 *
 * The landing page lists each module from here, enabled or not, with its
 * name and description read from its info file, so the list says what
 * the modules say. docs/examples/index.md and docs/installation.md list
 * the same modules in the same two groups; ExamplesRoutesTest holds this
 * list to the modules/ directory.
 */
final class ExampleModules {

  /**
   * The modules that teach the pattern, in reading order.
   */
  public const LEARN = [
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_keys',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_examples_first',
    'surface_examples_plugins',
    'surface_examples_classic',
    'surface_examples_extras',
    'surface_examples_react',
  ];

  /**
   * The modules that apply the pattern to what Drupal already has.
   */
  public const OWN_THINGS = [
    'surface_examples_block',
    'surface_examples_component_block',
    'surface_examples_entity',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_field',
    'surface_examples_address',
  ];

}

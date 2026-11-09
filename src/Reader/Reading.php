<?php

declare(strict_types=1);

namespace Drupal\surface\Reader;

/**
 * What a reader made of a source, besides the verbs it called.
 *
 * Each list is by dotted key path from the frame read (empty for the
 * frame itself, `<key>.*` for a sequence's items), so a generator can
 * write it as a comment and a census can count it:
 *
 * - left(): what no verb took, each with why, a key declared open in its
 *   place among them;
 * - leaves(): the source's own name for each value's type, where it has
 *   one beside the blueprint's (a config schema `machine_name` read as a
 *   string with its constraints);
 * - expressions(): each type the source names through another key, its
 *   form, and what became of it;
 * - structures(): each structure a key attaches by derived id.
 *
 * A value: added to while reading, read afterwards.
 */
final class Reading {

  /**
   * What was left out, by path: each reason once.
   *
   * @var array<string, list<string>>
   */
  protected array $left = [];

  /**
   * The source's name for each value's type, by path.
   *
   * @var array<string, string>
   */
  protected array $leaves = [];

  /**
   * Each type named through another key, by path.
   *
   * @var array<string, array{expression: string, form: string, by: string|null, became: string}>
   */
  protected array $expressions = [];

  /**
   * Each structure attached by derived id, by path.
   *
   * @var array<string, string>
   */
  protected array $structures = [];

  /**
   * Notes what was left out at a path, and why.
   *
   * @param string $path
   *   The dotted path; empty for the frame.
   * @param string $why
   *   Why: what the source says that no verb takes.
   *
   * @return $this
   */
  public function leave(string $path, string $why): static {
    if (!in_array($why, $this->left[$path] ?? [], TRUE)) {
      $this->left[$path][] = $why;
    }
    return $this;
  }

  /**
   * Notes the source's own name for a value's type.
   *
   * @param string $path
   *   The dotted path.
   * @param string $type
   *   The source's type name.
   *
   * @return $this
   */
  public function leaf(string $path, string $type): static {
    $this->leaves[$path] = $type;
    return $this;
  }

  /**
   * Notes a type named through another key, and what became of it.
   *
   * @param string $path
   *   The dotted path of the value so typed.
   * @param string $expression
   *   The type as written: `block.settings.[%parent.plugin]`.
   * @param string $form
   *   Its form: `parent`, `ancestor`, `own key` or `entry key`.
   * @param string|null $by
   *   The key that decides it, by name; NULL when none can be read.
   * @param string $became
   *   What the reader declared for it: `variants`, or `open`, a value of
   *   any shape (LeafRules::open()).
   *
   * @return $this
   */
  public function expression(string $path, string $expression, string $form, ?string $by, string $became): static {
    $this->expressions[$path] = ['expression' => $expression, 'form' => $form, 'by' => $by, 'became' => $became];
    return $this;
  }

  /**
   * Notes a structure attached by derived id.
   *
   * @param string $path
   *   The dotted path of the key holding it.
   * @param string $id
   *   The structure's id.
   *
   * @return $this
   */
  public function structure(string $path, string $id): static {
    $this->structures[$path] = $id;
    return $this;
  }

  /**
   * Gets what was left out.
   *
   * @return array<string, list<string>>
   *   Each reason, by path, sorted by path.
   */
  public function left(): array {
    $left = $this->left;
    ksort($left);
    return $left;
  }

  /**
   * Gets the source's own type names.
   *
   * @return array<string, string>
   *   Each type name, by path.
   */
  public function leaves(): array {
    return $this->leaves;
  }

  /**
   * Gets the types named through another key.
   *
   * @return array<string, array{expression: string, form: string, by: string|null, became: string}>
   *   Each, by path.
   */
  public function expressions(): array {
    return $this->expressions;
  }

  /**
   * Gets the structures attached by derived id.
   *
   * @return array<string, string>
   *   Each structure's id, by the path of the key holding it.
   */
  public function structures(): array {
    return $this->structures;
  }

  /**
   * Adds what another reading says, under a path.
   *
   * @param string $at
   *   The path the other was read at; empty for this frame.
   * @param \Drupal\surface\Reader\Reading $other
   *   The other reading: a map's, read inside this frame.
   *
   * @return $this
   */
  public function nest(string $at, Reading $other): static {
    $under = static fn (string $path): string => $at === '' ? $path : ($path === '' ? $at : $at . '.' . $path);
    foreach ($other->left as $path => $reasons) {
      foreach ($reasons as $why) {
        $this->leave($under((string) $path), $why);
      }
    }
    foreach ($other->leaves as $path => $type) {
      $this->leaves[$under((string) $path)] = $type;
    }
    foreach ($other->expressions as $path => $expression) {
      $this->expressions[$under((string) $path)] = $expression;
    }
    foreach ($other->structures as $path => $id) {
      $this->structures[$under((string) $path)] = $id;
    }
    return $this;
  }

}

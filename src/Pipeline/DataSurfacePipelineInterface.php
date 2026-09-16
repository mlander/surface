<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\data_surface\DataSurfaceInterface;

/**
 * The one entry point from raw values to stored values.
 *
 * Whether values arrive through Form API, a service endpoint, a config
 * action, or an agent, they pass the same four stages: accept coerces
 * partial untyped input into a complete typed value set, validate runs
 * the surface's constraints, prepare hands the values to a target for
 * shaping, and commit writes. The form path calls accept() through the
 * form builder rather than casting in its widgets, so the generated form
 * and a JSON payload cannot drift apart.
 *
 * Only commit has side effects, which is what makes a dry run a matter
 * of not calling it.
 *
 * This is also the only place values are validated. The surface is pure
 * data and reaches no service; running a surface's constraints needs the
 * typed data manager, so it belongs here — and putting it here is what
 * guarantees every validation refines the surface against the values
 * first, which a caller holding only a surface could forget to do.
 */
interface DataSurfacePipelineInterface {

  /**
   * The key a refusal by access is filed under in a violation set.
   *
   * Reserved, and spelled so it cannot collide: a surface key is a
   * definition name and no definition is named with a leading "@", so
   * the whole "@" prefix is the pipeline's own namespace for a refusal
   * that belongs to no particular key. A caller reporting per key skips
   * it or renders it as the run's own message; a generated form has no
   * element for it, so it becomes a form-level error.
   */
  public const ACCESS_VIOLATION_KEY = '@access';

  /**
   * The value a caller sends to empty a secret key on purpose.
   *
   * A secret key keeps what it holds when the input says nothing, which
   * is the only way a form that can never echo a stored secret can leave
   * one unchanged. That rule needs an escape, or a secret could be set
   * and never unset, so this is it: a caller that means "there is no
   * secret any more" sends this marker and the key becomes NULL.
   *
   * Spelled in the same reserved "@" namespace as ACCESS_VIOLATION_KEY
   * and long enough that nobody types it as a password by accident. A
   * host offering a companion "remove the stored token" checkbox sends
   * this when the box is ticked; an API caller sends it directly. It is
   * the marker for secret keys and nothing else: sent for any other key
   * it is an ordinary string.
   *
   * @see docs/semantics.md
   */
  public const CLEAR_SECRET = '@data_surface:clear-secret';

  /**
   * The option a generated select offers in place of a stale value.
   *
   * A stored value that has fallen outside the list its key now offers
   * cannot be rendered as a chosen option, and must not be injected into
   * the list as though it could still be chosen. So the select renders
   * with this marker selected instead, labeled with the value that is no
   * longer available, and extraction maps it back to the stored value
   * through the marker the element carries. Leaving the select alone
   * therefore means "keep what is stored", which is what leaving a
   * control alone has always meant everywhere else.
   *
   * Spelled in the same reserved "@" namespace as ACCESS_VIOLATION_KEY
   * and CLEAR_SECRET, for the same reason: no definition is named with a
   * leading "@", so the namespace cannot collide with a real value.
   *
   * Unlike CLEAR_SECRET this is the form path's own marker and not a
   * word a payload says: a caller with no form in front of it keeps a
   * stale value by sending it, or not sending the key at all. Sent as a
   * value by a payload it is an ordinary string, refused by the choice
   * constraint like any other value the key does not offer.
   *
   * @see \Drupal\data_surface\Plugin\DataSurfaceWidget\OptionsWidget
   * @see docs/forms.md
   */
  public const KEEP_STALE = '@data_surface:keep-stale';

  /**
   * Produces a complete, typed value set from partial, untyped input.
   *
   * Values merge in one order at every level: the surface's declared
   * defaults, then the current stored values (so a partial update is
   * legal), then the input, cast through each definition. Input that is
   * not configured — NULL or the empty string — means the key holds
   * nothing and becomes NULL, for every data type. Secret keys are the
   * documented exception: input that is not configured keeps what they
   * hold, because a form cannot echo a stored secret, and CLEAR_SECRET
   * is how a caller empties one on purpose. Locked keys take what
   * storage holds, falling back to the declared default, and ignore
   * whatever was submitted for them. Keys the surface does not declare
   * are refused rather than dropped, at any depth, and so is input in a
   * shape the definition cannot hold. The casting table and the
   * configured/not configured rule are stated in docs/semantics.md.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface describing what the values may be.
   * @param array $input
   *   Raw input keyed by surface key; may be partial.
   * @param array $current
   *   The stored values, in surface shape.
   *
   * @return array
   *   The accepted values: every surface key present, each in its
   *   definition's native type.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When the input carries a key no definition declares.
   * @throws \Drupal\data_surface\Pipeline\ShapeMismatchException
   *   When the input carries a value in a shape its definition cannot
   *   hold, such as a string where a map was advertised.
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array;

  /**
   * Validates values against the surface, refined against those values.
   *
   * The surface is refined first, so the constraints the values are held
   * to are the ones the values themselves selected — the same set the
   * generated form rendered.
   *
   * A value is "not configured" when it is NULL or the empty string:
   * acceptable for an optional key, and for a required key a violation
   * carrying the surface's own message rather than a type-specific one.
   * Everything else is configured, so FALSE, 0 and the empty array
   * satisfy a required key and are then held to its constraints like any
   * other value.
   *
   * One refusal is not a refusal. A key whose value the refined surface
   * will not take, which is exactly what is stored for that key, and
   * which is no longer among the values the key offers, is **stale**:
   * nothing about this run tried to change it, so refusing it would
   * punish a caller for something the site did. It is reported as a
   * stale entry in the returned set, which does not block — see
   * ViolationSet::stale() and docs/semantics.md. A value that differs
   * from what is stored is an ordinary violation however far out of the
   * list it is, because that one was chosen.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, as advertised.
   * @param array $values
   *   The accepted values.
   * @param array $current
   *   The stored values, in surface shape; what a key already holds is
   *   the only thing that can be stale. The default says nothing is
   *   stored, so nothing can be stale, which is right for a caller
   *   validating values that are not on their way to storage at all.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, each carrying its surface key, the property path
   *   within that key ('' for the value itself) and an unrendered
   *   message, with any stale entries held to one side. Empty when the
   *   values are valid.
   */
  public function validate(DataSurfaceInterface $surface, array $values, array $current = []): ViolationSet;

  /**
   * Checks an emitted array against what the surface says it emits.
   *
   * The output half of validate(), and the reason a declared output is
   * worth more than a docblock: a host that says what it emits can be
   * held to it, by its own tests and by anything generating them.
   *
   * What it does, in order:
   * 1. Refines the outputs against the input values, so the definitions
   *    the emitted values are held to are the ones those inputs
   *    selected — the same move validate() makes on the input side.
   * 2. Strips every Omitted key, at every depth. A producer says "not
   *    emitted" with the sentinel, and after this step that is the same
   *    thing as never having sent the key.
   * 3. Refuses keys no output definition declares, at any depth, in the
   *    same spirit accept() refuses unknown input keys: a key nobody
   *    declared is a promise nobody made.
   * 4. Checks every key that is present against its definition's type
   *    and constraints, through typed data.
   *
   * What it deliberately does **not** do is cast. Input arrives from
   * people and payloads and is coerced through the casting table;
   * outputs are produced by code that has the declaration in front of
   * it, so a string where an integer was declared is a bug in the
   * producer, and reporting it is the whole point.
   *
   * Presence, stated once: an absent key — never sent, or sent as
   * Omitted — is legal exactly when its definition is not required.
   * NULL is a value, not an absence: it is checked against the
   * definition like any other value, so a required key emitted as NULL
   * is refused and an optional one is not.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface whose outputs the values are held to.
   * @param array $output
   *   The emitted values, keyed by output key, Omitted included.
   * @param array $input_values
   *   The accepted input values the host ran with, which the outputs
   *   are refined against. Empty when the caller has none, in which
   *   case no output refines and the advertised definitions stand.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, each carrying its output key, the property path
   *   within that key and an unrendered message. Empty when the emitted
   *   values conform.
   *
   * @see \Drupal\data_surface\Pipeline\Omitted
   * @see docs/outputs.md
   */
  public function conformOutput(DataSurfaceInterface $surface, array $output, array $input_values = []): ViolationSet;

  /**
   * Shapes accepted values for a storage target without writing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface the values belong to.
   * @param array $values
   *   The accepted, validated values.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target that owns the storage shape.
   *
   * @return \Drupal\data_surface\Pipeline\PreparedValues
   *   The prepared artifact.
   *
   * @throws \Drupal\data_surface\Pipeline\TargetViolationsException
   *   When the target's storage refuses the values.
   */
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceTargetInterface $target): PreparedValues;

  /**
   * Writes a prepared artifact through its target.
   *
   * @param \Drupal\data_surface\Pipeline\PreparedValues $prepared
   *   The artifact to store.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target that owns the storage.
   */
  public function commit(PreparedValues $prepared, DataSurfaceTargetInterface $target): void;

  /**
   * Runs every stage: access, accept, validate, prepare, and commit.
   *
   * Stops at the first stage that refuses the values, so a caller reads
   * one result rather than orchestrating five calls.
   *
   * Access comes first and is the caller's own resolved answer rather
   * than a provider the pipeline would have to hold: a host asks its
   * provider's surfaceAccess() for the operation it is running and hands
   * the result over, so a form, a tool, a config action and an agent all
   * pass the same answer through the same gate. A forbidden answer
   * refuses before the target is read at all — nothing is loaded,
   * nothing is accepted, nothing is written — and comes back as a
   * violation under ACCESS_VIOLATION_KEY carrying the refusal's reason.
   * Neutral and allowed both proceed, identically: a provider with no
   * opinion blocks nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface describing what the values may be.
   * @param array $input
   *   Raw input keyed by surface key; may be partial.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface $target
   *   The target to read current values from and write the result to.
   * @param bool $dry_run
   *   TRUE to prepare the artifact but skip the write.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The provider's answer for this operation and account, already
   *   resolved by the caller, or NULL when the caller has no access
   *   answer to apply. NULL is not "allowed": it means nothing was
   *   asked, which is what every caller written before this stage
   *   existed means, and what a host that gates elsewhere means.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The accepted values, any violations, the prepared artifact when
   *   the values were valid, whether the write happened, and the access
   *   answer it was given, so a caller can merge its cacheability.
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceTargetInterface $target, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult;

}

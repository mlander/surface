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
 * partial untyped input into a complete typed value set, validate runs the
 * data surface's constraints, prepare hands the values to a storage for
 * shaping, and commit writes. The form path calls accept() through the
 * form builder rather than coercing in its widgets, so the generated form
 * and a JSON payload cannot drift apart.
 *
 * Only commit has side effects, which is what makes a dry run a matter
 * of not calling it.
 *
 * This is also the only place values are validated. The data surface is
 * pure data and reaches no service; running a data surface's constraints
 * needs the typed data manager, so it belongs here, and putting it here
 * is what guarantees every validation refines the data surface against
 * the values first, which a caller holding only a data surface could
 * forget to do.
 */
interface DataSurfacePipelineInterface {

  /**
   * The value a caller sends to empty a secret key on purpose.
   *
   * A secret key keeps what it holds when the input says nothing, which
   * is the only way a form that can never echo a stored secret can leave
   * one unchanged. That rule needs an escape, or a secret could be set
   * and never unset, so this is it: a caller that means "there is no
   * secret any more" sends this marker and the key becomes NULL.
   *
   * Spelled in a reserved "@" namespace, since no definition is named
   * with a leading "@", and long enough that nobody types it as a
   * password by accident. A host offering a companion "remove the stored
   * token" checkbox sends this when the box is ticked; an API caller
   * sends it directly. It is the marker for secret keys and nothing else:
   * sent for any other key it is an ordinary string.
   *
   * @see docs/semantics.md
   */
  public const CLEAR_SECRET = '@data_surface:clear-secret';

  /**
   * Produces a complete, typed value set from partial, untyped input.
   *
   * Values merge in one order at every level: the data surface's declared
   * defaults, then the current stored values (so a partial update is
   * legal), then the input, coerced through each definition by the Tool
   * API's coercion table. A key left out keeps what it holds; input
   * that is not configured (NULL or the empty string) means the key holds
   * nothing and becomes NULL, for every data type but a list, which keeps
   * what it holds. Secret keys are the documented exception: input that
   * is not configured keeps what they hold, because a form cannot echo a
   * stored secret, and CLEAR_SECRET is how a caller empties one on
   * purpose. Locked keys take
   * what storage holds, falling back to the declared default, and ignore
   * whatever was submitted for them. Keys the data surface does not
   * declare are refused rather than dropped, at any depth, and so is
   * input in a shape the definition cannot hold. The coercion table and
   * the configured/not configured rule are stated in docs/semantics.md.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing what the values may be.
   * @param array $input
   *   Raw input keyed by data surface key; may be partial.
   * @param array $current
   *   The stored values, in input layout.
   *
   * @return array
   *   The accepted values: every data surface key present, each in its
   *   definition's native type.
   *
   * @throws \Drupal\data_surface\Pipeline\UnknownKeysException
   *   When the input carries a key no definition declares.
   * @throws \Drupal\data_surface\Pipeline\TypeMismatchException
   *   When the input carries a value in a shape its definition cannot
   *   hold, such as a string where a map was advertised.
   * @throws \Drupal\data_surface\Pipeline\VariantMismatchException
   *   When the input at a key with variants carries keys of a variant
   *   its deciding key did not choose.
   */
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array;

  /**
   * Validates values against the data surface, refined against them.
   *
   * The data surface is refined first, so the constraints the values are
   * held to are the ones the values themselves selected: the same set the
   * generated form rendered.
   *
   * A value is "not configured" when it is NULL or the empty string:
   * acceptable for an optional key, and for a required key a violation
   * carrying the data surface's own message rather than a type-specific
   * one. Everything else is configured, so FALSE, 0 and the empty array
   * satisfy a required key and are then held to its constraints like any
   * other value.
   *
   * One refusal is not a refusal. A key whose value the refined data
   * surface will not take, which is exactly what is stored for that key,
   * and which is no longer among the values the key offers, is **stale**:
   * nothing about this run tried to change it, so refusing it would punish
   * a caller for something the site did. It is reported as a stale entry
   * in the returned set, which does not block (see ViolationSet::stale()
   * and docs/semantics.md). A value that differs from what is stored is an
   * ordinary violation however far out of the list it is, because that one
   * was chosen.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface, as advertised.
   * @param array $values
   *   The accepted values.
   * @param array $current
   *   The stored values, in input layout; what a key already holds is
   *   the only thing that can be stale. The default says nothing is
   *   stored, so nothing can be stale, which is right for a caller
   *   validating values that are not on their way to storage at all.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, each carrying its data surface key, the property
   *   path within that key ('' for the value itself) and an unrendered
   *   message, with any stale entries held to one side. Empty when the
   *   values are valid.
   */
  public function validate(DataSurfaceInterface $surface, array $values, array $current = []): ViolationSet;

  /**
   * Checks an emitted array against what the data surface says it emits.
   *
   * The output half of validate(), and the reason a declared output is
   * worth more than a docblock: a host that says what it emits can be
   * held to it, by its own tests and by anything generating them.
   *
   * What it does, in order:
   * 1. Strips every NotEmitted key, at every depth. A producer says "not
   *    emitted" with the sentinel, and after this step that is the same
   *    thing as never having sent the key.
   * 2. Refuses keys no output definition declares, at any depth, in the
   *    same spirit accept() refuses unknown input keys: a key nobody
   *    declared is a promise nobody made.
   * 3. Checks every key that is present against its definition's type
   *    and constraints, through typed data.
   *
   * What it deliberately does **not** do is coerce. Input arrives from
   * people and payloads and is coerced through the coercion table;
   * outputs are produced by code that has the declaration in front of
   * it, so a string where an integer was declared is a bug in the
   * producer, and reporting it is the whole point.
   *
   * Presence, stated once: an absent key — never sent, or sent as
   * NotEmitted — is legal exactly when its definition is not required.
   * NULL is a value, not an absence: it is checked against the
   * definition like any other value, so a required key emitted as NULL
   * is refused and an optional one is not.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface whose outputs the values are held to.
   * @param array $output
   *   The emitted values, keyed by output key, NotEmitted included.
   *
   * @return \Drupal\data_surface\Pipeline\ViolationSet
   *   The violations, each carrying its output key, the property path
   *   within that key and an unrendered message. Empty when the emitted
   *   values conform.
   *
   * @see \Drupal\data_surface\Pipeline\NotEmitted
   * @see docs/outputs.md
   */
  public function conformOutput(DataSurfaceInterface $surface, array $output): ViolationSet;

  /**
   * Shapes accepted values for a storage without writing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values belong to.
   * @param array $values
   *   The accepted, validated values.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface $storage
   *   The storage that owns the storage layout.
   *
   * @return \Drupal\data_surface\Pipeline\PreparedValues
   *   The prepared artifact.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When the storage refuses the values.
   */
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceStorageInterface $storage): PreparedValues;

  /**
   * Puts back what storage holds under the keys that do not apply.
   *
   * The accepted document leaves out a key a refiner ruled out for its
   * values, and storage keeps what it holds there, so the key holds it
   * again when it applies: a weekly send day survives a month of monthly
   * sends. submit() hands storage the document with what is kept put
   * back, so a storage that writes its whole object from what it is
   * handed keeps it too; a host loading a plugin's configuration does
   * the same.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   *
   * Given what was sent, it also writes back what storage holds as it
   * holds it: a key sent a value whose accepted form is what storage
   * holds keeps the stored representation (the empty string a read
   * returned, sent back, stays the empty string), and a key storage never
   * held, on a thing that exists, reads as its declared default and is
   * not written while it holds that default, sent or not. So writing back
   * what a read returned, or what a form drew, leaves storage as it was.
   *
   * Decision: see docs/decisions.md#absent-keeps-null-clears.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing the values.
   * @param array $values
   *   The accepted values.
   * @param array $current
   *   What storage holds.
   * @param array|null $input
   *   What was sent for this level, or NULL when it is not known (a
   *   host's own configuration), which writes every accepted value.
   *
   * @return array
   *   The values, each key left out put back from what storage holds.
   */
  public function kept(DataSurfaceInterface $surface, array $values, array $current, ?array $input = NULL): array;

  /**
   * Writes a prepared artifact through its storage.
   *
   * @param \Drupal\data_surface\Pipeline\PreparedValues $prepared
   *   The artifact to store.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface $storage
   *   The storage that prepared it.
   */
  public function commit(PreparedValues $prepared, DataSurfaceStorageInterface $storage): void;

  /**
   * Projects values as any door may return them to its caller.
   *
   * The read tool's projection, for every door and every answer: a key
   * the build left out for the caller is not there at any depth, nor is a
   * secret, nor a key that does not apply; a locked key is there as the
   * values hold it, which is as stored.
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values are for, built for the caller.
   * @param array $values
   *   Accepted values, or what storage loads.
   *
   * @return array
   *   The values a caller may be handed.
   *
   * @see \Drupal\data_surface\Pipeline\Outbound::project()
   */
  public function outbound(DataSurfaceInterface $surface, array $values): array;

  /**
   * Writes accepted values as the JSON document they are.
   *
   * Values are arrays, and an empty PHP array encodes as a JSON list:
   * here every map is an object, an empty one included (a map whose
   * every key does not apply, a mount with nothing in it), and every
   * list a list, as the served contract writes its own `values`. It
   * writes what it is handed: a door hands it what outbound() projected,
   * never the accepted values as they are.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply, and
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface the values were accepted by.
   * @param array $values
   *   What accept() returned.
   *
   * @return \stdClass
   *   The document, ready to encode.
   */
  public function document(DataSurfaceInterface $surface, array $values): \stdClass;

  /**
   * Runs every stage: access, accept, validate, prepare, and commit.
   *
   * Stops at the first stage that refuses the values, so a caller reads
   * one result rather than orchestrating five calls.
   *
   * Access comes first and is the caller's own resolved answer rather
   * than a service the pipeline would have to hold: a host asks the
   * build step's access() for the context it is running in and hands
   * the result over, so a form, a tool, a config action and an agent all
   * pass the same answer through the same gate. A forbidden answer
   * refuses before the storage is read at all (nothing is loaded,
   * nothing is accepted, nothing is written) and comes back as one
   * violation of the whole call, with an empty path and the code
   * `access`, carrying the refusal's reason.
   * Neutral and allowed both proceed, identically: a resource or
   * structure with no opinion blocks nothing.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface describing what the values may be.
   * @param array $input
   *   Raw input keyed by data surface key; may be partial.
   * @param \Drupal\data_surface\Pipeline\DataSurfaceStorageInterface $storage
   *   The storage to read current values from and write the result to.
   * @param bool $dry_run
   *   TRUE to prepare the artifact but skip the write.
   * @param \Drupal\Core\Access\AccessResultInterface|null $access
   *   The resource or structure's answer for this context and account,
   *   already resolved by the caller, or NULL when the caller has no
   *   access answer to apply. NULL is not "allowed": it means nothing was
   *   asked, which is what every caller written before this stage existed
   *   means, and what a host that gates elsewhere means.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The accepted values, any violations, the prepared artifact when
   *   the values were valid, whether the write happened, the access
   *   answer it was given, so a caller can merge its cacheability, and
   *   the notes accept() made: each locked key whose value sent it did
   *   not take, blocking nothing.
   */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult;

}

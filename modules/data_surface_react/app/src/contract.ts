// The served contract, as the main module's ContractEmitter writes it:
// JSON Schema 2020-12 with an `x-surface` keyword on every property, each
// child defined once under `$defs` and referred to where it is used.
// The app reads it through inlineSchema(), once per contract, so nothing
// else here follows a reference.

/**
 * The contract version this app reads.
 *
 * Both documents state theirs in `x-surface.contract` at their schema's
 * top, and every answer of the API in its `Contract-Version` header. A
 * document of any other version is refused, never read as if it were
 * this one.
 */
export const CONTRACT = 3;

/** A document or an answer of a contract version this app does not read. */
export class ContractRefused extends Error {
  constructor(readonly sent: string) {
    super(`This page reads contract ${CONTRACT}; the server sent contract ${sent}.`);
    this.name = 'ContractRefused';
  }
}

/**
 * Refuses a document of a contract this app does not read.
 *
 * A document that states no `x-surface.contract` is of contract 1, which
 * said nothing of its version.
 */
export function assertContract<T extends { schema?: Schema | null }>(document: T): T {
  assertSchema(document?.schema);
  return document;
}

/** Refuses a schema, the full schema as served, of a contract this app does not read. */
export function assertSchema<T extends Schema | null | undefined>(schema: T): T {
  const stated = schema?.['x-surface']?.contract;
  if (stated !== CONTRACT) {
    throw new ContractRefused(stated === undefined || stated === null ? '1' : String(stated));
  }
  return schema;
}

export type Widget =
  | 'select'
  | 'radios'
  | 'checkboxes'
  | 'checkbox'
  | 'time'
  | 'number'
  | 'text'
  | 'textarea'
  | 'email'
  | 'fieldset'
  | 'variants'
  | 'list'
  | 'fixed';

export interface EmptyOption {
  show: boolean;
  label: string;
}

/** A named group a key is drawn in: a fieldset, or a details a person may close. */
export interface ArrangementGroup {
  id: string;
  label: string;
  as: 'fieldset' | 'details';
  collapsed?: boolean;
  weight?: number;
}

/**
 * How a key is arranged where it is drawn (`x-surface.arrangement`, with
 * the widget hints): its group, its weight among its frame's keys (or its
 * group's), and the texts beside its input. Never what it accepts.
 */
export interface Arrangement {
  group?: ArrangementGroup;
  weight?: number;
  placeholder?: string;
  prefix?: string;
  suffix?: string;
  help?: string;
}

export interface Extension {
  widget: Widget | null;
  /** With the widget hints: how the key is arranged where it is drawn. */
  arrangement?: Arrangement;
  locked: boolean;
  dependsOn: string[];
  refined: boolean;
  stale: boolean;
  emptyOption?: EmptyOption;
  multiple?: boolean;
  by?: string;
  /** On a key with variants: the def each value of the deciding key chooses, by that value. */
  variants?: Record<string, string>;
  /** On a key with variants: the variant the deciding key chose, or null. */
  chosen?: string | null;
  /** Each constraint only the server checks, and what it checks, as plain text. */
  checkedOnServer?: { constraint: string; message: string }[];
  /** A Regex's own message: how a person is told what `pattern` allows. */
  patternMessage?: string;
  /** On an object that only groups each module's fieldset: draw nothing around them. */
  group?: boolean;
  /** On a module's fieldset inside a group: draw it right after this sibling of the group instead. */
  after?: string;
  /** On a deciding key whose variants offer one value, its `const`: the label that value is offered as. */
  fixed?: string;
  /**
   * The key's static refiners watch keys that each offer a list, and what
   * they answer for every value is stated as conditionals on the frame:
   * the page can narrow it with no request.
   */
  enumerated?: boolean;
  /**
   * Static, but not stated: a watched key cannot be listed, or the
   * combinations exceed the cap. Narrowed for values by the server's narrowing.
   */
  refine?: boolean;
  /**
   * A live refiner narrows the key too: a rule only the server applies,
   * when asked, which no schema states ahead of time. The full schema
   * keeps the key wider; this document is refined by it for its values.
   */
  live?: boolean;
  /**
   * In the full schema: an access answer only the thing itself decides
   * was set aside for this account, so the key is as its static access
   * leaves it and may be locked or left out once the thing is known. The
   * schema for these answers settles it, and the page keeps what that
   * says rather than narrowing the key from the full schema.
   */
  access?: 'live';
  /**
   * In the full schema: the key is narrowed by rules declared as data,
   * each a conditional on the frame its `x-surface.rules` lists, stated
   * as written. The page evaluates each `if` and tightens the key by its
   * `then`; the schema for some values was evaluated already.
   */
  declared?: boolean;
  /** At the top of a document: the contract version it is written in. */
  contract?: number;
  /**
   * On the wire only, on an object that holds an `allOf`: the indexes of
   * its declared rules' conditionals. restored() marks each one
   * `x-surface.declared` and drops the list.
   */
  rules?: number[];
  /**
   * On the wire only, on an object not itself below an applicator: what
   * the schemas below its applicators say, by JSON pointer from it.
   * restored() sets each as the `x-surface` of the schema it points at
   * and drops the map.
   */
  within?: Record<string, Extension>;
  /**
   * On a collection: a list whose every item is a child, `items`
   * referring to the child's def. Each item is drawn as the child's
   * fieldset; the list's own `minItems` and `maxItems` are the parent's.
   */
  list?: boolean;
  /**
   * On an object: the keys of it that do not apply to these values, each
   * with the keys it depends on, so a change of one is refined. They are
   * no properties of it, and not required.
   */
  doesNotApply?: Record<string, string[]>;
}

export interface Choice {
  const: unknown;
  title?: string;
  description?: string;
}

export interface Conditional {
  /**
   * An enumerated conditional's and a variant's: a `const` per key it names.
   * A declared rule's is any `if` of its vocabulary (holdsIf()).
   */
  if: { properties: Record<string, { const: unknown }>; required?: string[] };
  /**
   * What holds of one key under the `if`: its narrowing, `false` where it
   * does not apply, and `required` where it is required only here.
   */
  then: { properties?: Record<string, Schema | false>; required?: string[] };
  /** On a declared rule's conditional, once restored(): it is stated as written. */
  'x-surface'?: { declared?: boolean };
}

export interface Schema {
  $schema?: string;
  /** On a served document: the absolute URL it is served at. */
  $id?: string;
  /** A reference to a def, `#/$defs/<id>`; resolved by inlineSchema(). */
  $ref?: string;
  /** The root's subschemas, one per child and one per shape, by id. */
  $defs?: Record<string, Schema>;
  title?: string;
  description?: string;
  type?: string | string[];
  format?: string;
  properties?: Record<string, Schema>;
  required?: string[];
  additionalProperties?: boolean;
  /** On a frame that mixes structures in, before inlineSchema() flattens it. */
  unevaluatedProperties?: boolean;
  allOf?: Conditional[];
  /**
   * On a key with variants, as served: a reference to each variant's def,
   * and null where the key may be cleared. inlineSchema() drops it: the
   * conditionals state each variant exactly.
   */
  anyOf?: Schema[];
  items?: Schema;
  oneOf?: Choice[];
  minimum?: number;
  maximum?: number;
  minLength?: number;
  maxLength?: number;
  minItems?: number;
  maxItems?: number;
  pattern?: string;
  default?: unknown;
  examples?: unknown[];
  readOnly?: boolean;
  writeOnly?: boolean;
  const?: unknown;
  'x-surface'?: Extension;
}

export type Values = Record<string, unknown>;

/**
 * The envelope: the schema for the values, and the values.
 *
 * Every path in it is dotted here (`ticket.price`): the API writes each
 * as a JSON Pointer, and api.ts reads it back. The stored values'
 * fingerprint is no member of it: it is the answer's `ETag`.
 */
export interface Contract {
  resource: string;
  operation: string | null;
  label: string | null;
  schema: Schema;
  values: Values;
  stale: string[];
  outputs?: Schema;
  /** One note per stale path, code `stale`. */
  notes?: Violation[];
  /** On a narrowing: each answer the new choices orphaned. */
  discarded?: string[];
}

/** One refusal or note: its dotted path ('' for the whole call), a stable code, and the message. */
export interface Violation {
  path: string;
  code: string;
  message: string;
}

/** A stored value kept that its key no longer offers, and the value. */
export interface StaleValue extends Violation {
  stored: unknown;
}

/**
 * The one result shape every door answers a run in (ResultDocument),
 * the accepted values as the read tool would answer them (no secret,
 * nothing hidden). A refusal arrives as problem details, which api.ts
 * reads back into this shape.
 */
export interface Validation {
  /** Nothing was refused. */
  ok: boolean;
  /** The values were written; never on a dry run. */
  committed: boolean;
  /** The write moved a stored value. */
  changed: boolean;
  /** The dotted path of each stored value the write changed, or would change. */
  changes: string[];
  values: Values;
  violations: Violation[];
  /** Each value sent for a locked key, not taken: code `locked`. */
  notes: Violation[];
  /** Each stored value kept that is no longer offered: code `stale`. */
  stale: StaleValue[];
}

/** Where a created thing now lives: an operation and its parameters. */
export interface Created {
  resource: string;
  operation: string;
  parameters: Record<string, string | number>;
}

/** What a write answers: the result shape, and, when written, the fresh contract. */
export interface Submission extends Validation {
  outputs: Values;
  contract: Contract | null;
  created: Created | null;
  /** A dry run says it was one. */
  'x-surface-dry-run'?: boolean;
}

/** The JSON type a schema is, null aside. */
export function jsonType(schema: Schema): string | undefined {
  const types = Array.isArray(schema.type) ? schema.type : schema.type === undefined ? [] : [schema.type];
  return types.find((type) => type !== 'null');
}

/** The values a select offers: every `oneOf` entry but the untitled null. */
export function choices(schema: Schema): Choice[] {
  return (schema.oneOf ?? []).filter((choice) => !(choice.const === null && choice.title === undefined));
}

/** Whether two values are the same option, as a select compares them. */
export function sameValue(a: unknown, b: unknown): boolean {
  if (a === null || a === undefined || b === null || b === undefined) {
    return false;
  }
  return String(a) === String(b);
}

/**
 * Whether a key holds a value whatever is sent: required in its frame, or
 * a key whose type and choices leave null out, which its storage always
 * holds (docs/decisions.md#a-key-its-storage-always-holds). Either way
 * null is no answer, and a choice of it needs no empty option once a
 * valid one is chosen.
 */
export function holdsValue(schema: Schema, required: boolean): boolean {
  if (required) {
    return true;
  }
  const types = Array.isArray(schema.type) ? schema.type : schema.type === undefined ? [] : [schema.type];
  const nullable = types.includes('null') || (schema.oneOf ?? []).some((choice) => choice.const === null && choice.title === undefined);
  return !nullable && !schema.writeOnly;
}

/** Whether a value holds nothing, by the module's own rule: null or ''. */
export function isEmpty(value: unknown): boolean {
  return value === null || value === undefined || value === '';
}

/** Where every subschema a contract defines is: a JSON pointer prefix. */
export const DEFS = '#/$defs/';

type Json = Record<string, unknown>;

function sameJson(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

/** The key under `$defs` a local reference points at, or null for another reference. */
export function defName(reference: string): string | null {
  if (!reference.startsWith(DEFS)) {
    return null;
  }
  const segment = decodeURIComponent(reference.slice(DEFS.length));
  return segment.includes('/') ? null : segment.replace(/~1/g, '/').replace(/~0/g, '~');
}

/**
 * Lays one schema over another: the later schema's value for every keyword
 * it states, which is right because a narrowing only narrows, except that
 * `properties` merge one by one, `required` is the union and `allOf` the
 * concatenation. A keyword already there keeps its place.
 */
function merge(base: Json, over: Json): Json {
  const merged: Json = { ...base };
  for (const [keyword, value] of Object.entries(over)) {
    const before = merged[keyword];
    if (keyword === 'properties' && isObject(before) && isObject(value)) {
      const properties: Json = { ...before };
      for (const [name, property] of Object.entries(value)) {
        const was = properties[name];
        if (property === false && isObject(was)) {
          // Not applying at this site: no property, as a flat frame says
          // it; its frame's `doesNotApply` names it.
          delete properties[name];
          continue;
        }
        properties[name] = isObject(was) && isObject(property) ? merge(was, property) : property;
      }
      merged[keyword] = properties;
    }
    else if (keyword === 'required' && Array.isArray(before) && Array.isArray(value)) {
      merged[keyword] = [...new Set([...before, ...value])];
    }
    else if (keyword === 'allOf' && Array.isArray(before) && Array.isArray(value)) {
      merged[keyword] = [...before, ...value.filter((entry) => !before.some((had) => sameJson(had, entry)))];
    }
    else if (keyword === 'x-surface' && isObject(before) && isObject(value) && before.doesNotApply !== undefined && value.doesNotApply === undefined) {
      // What a site says about its key replaces what the def said, but not
      // which keys of the child do not apply here.
      merged[keyword] = { ...value, doesNotApply: before.doesNotApply };
    }
    else if (keyword === 'x-surface' && isObject(before) && isObject(value) && before.doesNotApply === undefined && value.doesNotApply !== undefined) {
      // A child narrowed at a site laid over what the site says about its
      // key: the site's word stands, with which keys of the child do not
      // apply here.
      merged[keyword] = { doesNotApply: value.doesNotApply, ...before };
    }
    else {
      merged[keyword] = value;
    }
  }
  return merged;
}

/** A def as laid over a site: its own `title` is its id, not a label, so it is left out. */
function untitled(def: Json): Json {
  if (!('title' in def)) {
    return def;
  }
  const { title: _title, ...rest } = def;
  return rest;
}

/** The keywords whose subschemas restored() walks: a map of them, a list, or one. */
const NAMED = ['properties', '$defs', 'patternProperties'];
const LISTED = ['allOf', 'anyOf', 'oneOf'];
const SINGLE = ['if', 'then', 'else', 'not', 'items', 'additionalProperties', 'unevaluatedProperties', 'contains', 'propertyNames'];

/**
 * Puts each marker back where the emitter's pass wrote it.
 *
 * The wire keeps `x-surface` off every schema below an applicator: the
 * object that owns an `allOf` lists its declared rules' conditionals by
 * index in `x-surface.rules`, and says what is said below it in
 * `x-surface.within`, by JSON pointer from itself. This reads both back
 * into place, `x-surface.declared` on each rule's conditional and each
 * entry of `within` as the `x-surface` of the schema it points at (a
 * narrowing that said nothing else is an empty object there), and drops
 * the two lists, so the rest of the app reads the markers where they
 * stand. Applied twice, it changes nothing more. It works on a copy: what
 * was served is left as it was. PHP's twin is
 * `SchemaResolver::restored()`.
 */
export function restored(schema: Schema): Schema {
  return restoreInPlace(JSON.parse(JSON.stringify(schema)) as Json) as Schema;
}

function restoreInPlace(schema: Json): Json {
  for (const [keyword, value] of Object.entries(schema)) {
    if (NAMED.includes(keyword) && isObject(value)) {
      for (const [name, child] of Object.entries(value)) {
        if (isObject(child)) {
          value[name] = restoreInPlace(child);
        }
      }
    }
    else if (LISTED.includes(keyword) && Array.isArray(value)) {
      value.forEach((member, i) => {
        if (isObject(member)) {
          value[i] = restoreInPlace(member);
        }
      });
    }
    else if (SINGLE.includes(keyword) && isObject(value)) {
      schema[keyword] = restoreInPlace(value);
    }
  }
  const extension = schema['x-surface'];
  if (!isObject(extension) || (!('rules' in extension) && !('within' in extension))) {
    return schema;
  }
  const conditions = schema.allOf;
  for (const i of Array.isArray(extension.rules) ? extension.rules : []) {
    const condition = Number.isInteger(i) && Array.isArray(conditions) ? conditions[i as number] : undefined;
    if (isObject(condition)) {
      condition['x-surface'] = { ...(isObject(condition['x-surface']) ? condition['x-surface'] : {}), declared: true };
    }
  }
  for (const [pointer, said] of Object.entries(isObject(extension.within) ? extension.within : {})) {
    if (!pointer.startsWith('/')) {
      continue;
    }
    placed(schema, pointer.slice(1).split('/').map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~')), said);
  }
  delete extension.rules;
  delete extension.within;
  if (Object.keys(extension).length === 0) {
    delete schema['x-surface'];
  }
  return schema;
}

/**
 * Sets the `x-surface` of the schema a path from an owning schema leads to.
 *
 * A schema that said nothing but its `x-surface` was left `true` where it
 * stood, or, as a property, left out (its `properties` too, when nothing
 * else was in them): each is made an object again on the way. An empty
 * list is read as the empty object PHP may encode one as. PHP's twin is
 * `SchemaResolver::placed()`.
 */
function placed(owner: Json, path: string[], said: unknown): void {
  let node: Json | unknown[] = owner;
  path.forEach((segment, i) => {
    const last = i === path.length - 1;
    const index = Array.isArray(node) && /^\d+$/.test(segment) ? Number(segment) : null;
    const container = node as Record<string | number, unknown>;
    const key = index ?? segment;
    let inner = container[key];
    const nextIsIndex = !last && /^\d+$/.test(path[i + 1]) && Array.isArray(inner) && inner.length > 0;
    if (!isObject(inner) && !nextIsIndex) {
      inner = {};
      container[key] = inner;
    }
    if (last) {
      (inner as Json)['x-surface'] = said;
    }
    node = inner as Json | unknown[];
  });
}

/**
 * Resolves one schema's references, and says which defs it followed.
 *
 * A reference is replaced by its def where it stood; an `allOf` holding a
 * reference is a composition, each entry that is not a conditional laid
 * over in order and the conditionals kept. A def reached again on the way
 * down (`ancestors`) is a recursion and its reference is kept; one reached
 * again beside itself is already laid over.
 */
function resolveAt(schema: Json, root: Json, ancestors: string[]): [Json, string[]] {
  const followed: string[] = [];
  let resolved: Json = {};
  // A frame that mixes structures in: its own properties beside a bare
  // reference to each structure's def in its allOf.
  const frame = isObject(schema.properties);
  const mixed: Json[] = [];
  const follow = (name: string, reference: string): void => {
    const def = isObject(root.$defs) ? root.$defs[name] : undefined;
    if (ancestors.includes(name) || !isObject(def)) {
      resolved = merge(resolved, { $ref: reference });
      return;
    }
    if (followed.includes(name)) {
      return;
    }
    followed.push(name);
    resolved = merge(resolved, resolveAt(untitled(def), root, [...ancestors, name])[0]);
  };
  for (const [keyword, value] of Object.entries(schema)) {
    const name = keyword === '$ref' && typeof value === 'string' ? defName(value) : null;
    if (name !== null) {
      follow(name, value as string);
      continue;
    }
    if (keyword === 'allOf' && Array.isArray(value) && value.some((entry) => isObject(entry) && '$ref' in entry)) {
      const conditions: unknown[] = [];
      for (const entry of value) {
        if (entry === true) {
          // A narrowing that says nothing: what it said was `x-surface`, put back by restored().
          continue;
        }
        if (!isObject(entry) || 'if' in entry) {
          conditions.push(entry);
          continue;
        }
        const referred = typeof entry.$ref === 'string' ? defName(entry.$ref) : null;
        if (referred !== null && Object.keys(entry).length === 1 && frame) {
          // Its members join this frame: what the frame holds besides is
          // not inside the def, so the def is no ancestor of it.
          const def = isObject(root.$defs) ? root.$defs[referred] : undefined;
          if (isObject(def) && !ancestors.includes(referred)) {
            mixed.push(resolveAt(untitled(def), root, [...ancestors, referred])[0]);
          }
          continue;
        }
        if (referred !== null && Object.keys(entry).length === 1) {
          follow(referred, entry.$ref as string);
          continue;
        }
        resolved = merge(resolved, resolveAt(entry, root, ancestors)[0]);
      }
      if (conditions.length > 0) {
        resolved = merge(resolved, { allOf: conditions });
      }
      continue;
    }
    resolved = merge(resolved, { [keyword]: value });
  }
  for (const def of mixed.reverse()) {
    resolved = mixIn(resolved, def);
  }
  if (resolved.unevaluatedProperties === false && isObject(resolved.properties) && !('$ref' in resolved)) {
    // Every key is in the frame's own properties now, so it closes them.
    resolved = Object.fromEntries(Object.entries(resolved).map(([keyword, value]) => [keyword === 'unevaluatedProperties' ? 'additionalProperties' : keyword, value]));
  }
  return [resolved, [...ancestors, ...followed]];
}

/**
 * Lays a frame over the def of a structure it mixes in.
 *
 * Each member keeps its place in the frame's properties, the def's schema
 * for it with what the frame narrows laid over (`true` narrows nothing;
 * `false`, not applying here, leaves it out, as a flat frame does); the
 * def's `required` joins the frame's, in the frame's order, and its
 * conditionals come first, as its rules and refiners run before the
 * owner's. What the def says of itself as a whole (the ports it reads) is
 * the structure's, not the frame's. PHP's twin is
 * `SchemaResolver::mixIn()`.
 */
function mixIn(frame: Json, def: Json): Json {
  const theirs = isObject(def.properties) ? def.properties : {};
  const own = isObject(frame.properties) ? frame.properties : {};
  const properties: Json = {};
  const ruled: string[] = [];
  for (const [name, property] of Object.entries(own)) {
    const declared = theirs[name];
    if (property === false && isObject(declared)) {
      // Not applying to these values: no property, as a flat frame says it.
      ruled.push(name);
      continue;
    }
    properties[name] = !isObject(declared) ? property : property === true ? declared : isObject(property) ? merge(declared, property) : property;
  }
  for (const [name, property] of Object.entries(theirs)) {
    if (!(name in properties) && !ruled.includes(name)) {
      properties[name] = property;
    }
  }
  const mixed: Json = { ...frame, properties };
  // In the frame's order, as a flat frame lists what it requires.
  const order = Object.keys(properties);
  const required = [...new Set([...(Array.isArray(frame.required) ? frame.required : []), ...(Array.isArray(def.required) ? def.required : [])])]
    .filter((name) => !ruled.includes(name as string))
    .sort((a, b) => order.indexOf(a as string) - order.indexOf(b as string));
  if (required.length > 0) {
    mixed.required = required;
  }
  const conditions = [...(Array.isArray(def.allOf) ? def.allOf : []), ...(Array.isArray(frame.allOf) ? frame.allOf : [])];
  if (conditions.length > 0) {
    mixed.allOf = conditions;
  }
  return mixed;
}

/**
 * A property's effective schema, its own references resolved.
 *
 * Follows a local `$ref` to its def and merges the keywords beside it; an
 * `allOf` of a reference and a narrowing is applied by taking the
 * narrowing's value for every keyword it states. The schemas inside are
 * left as written; inlineSchema() resolves those too.
 */
export function resolveSchema(schema: Schema, root: Schema): Schema {
  return resolveAt(restored(schema) as Json, restored(root) as Json, [])[0] as Schema;
}

/**
 * The schemas inlineSchema() resolved from a def: each a child's frame,
 * where the parent's `allOf` ends and the child's own begins. A key
 * declared with a shape refers to a def too, and is no frame: its def is
 * laid under it, `$ref` first, and the key's own keywords over that.
 */
const parts = new WeakSet<object>();

/** Whether an inline schema is a child's frame (it was a reference to a def). */
export function isPart(schema: Schema): boolean {
  return parts.has(schema);
}

/**
 * A schema with every reference resolved, at every depth.
 *
 * What the app draws from: run once per contract, on load and on each
 * refine, so a property, a variant's conditional and a fieldset's keys are
 * each read inline. Its markers are first put back in place (restored()),
 * once, at the root. At the root, `$defs` is dropped; a key with variants
 * loses the `anyOf` that unites them, which the conditionals state one
 * by one. PHP's twin is
 * `Drupal\data_surface\Contract\SchemaResolver::inline()`.
 */
export function inlineSchema(schema: Schema, root?: Schema, ancestors: string[] = []): Schema {
  const own = restored(schema) as Json;
  return inlined(own, root === undefined ? own : (restored(root) as Json), ancestors, root === undefined);
}

/** inlineSchema(), its markers already in place. */
function inlined(schema: Json, base: Json, ancestors: string[], atRoot: boolean): Schema {
  const [resolved, followed] = resolveAt(schema, base, ancestors);
  if (atRoot) {
    delete resolved.$defs;
  }
  if (followed.length > ancestors.length && isObject(resolved.properties)) {
    // A child's frame: an attached part, a variant, a collection's
    // item. A key that refers to a shape's def is a value, never a frame.
    parts.add(resolved);
  }
  const inline = (inner: unknown): unknown => (isObject(inner) ? inlined(inner, base, followed, false) : inner);
  if (isObject(resolved.properties)) {
    resolved.properties = Object.fromEntries(Object.entries(resolved.properties).map(([name, property]) => {
      if (isObject(property) && isObject(property['x-surface']) && property['x-surface'].variants !== undefined && 'anyOf' in property) {
        // The union of its variants: the conditionals state each one.
        const { anyOf: _union, ...rest } = property;
        return [name, inline(rest)];
      }
      return [name, inline(property)];
    }));
  }
  if (isObject(resolved.items)) {
    resolved.items = inline(resolved.items);
  }
  if (Array.isArray(resolved.allOf)) {
    resolved.allOf = resolved.allOf.map((condition: unknown) => {
      if (!isObject(condition)) {
        return condition;
      }
      const branches: Json = { ...condition };
      for (const branch of ['then', 'else']) {
        if (isObject(branches[branch])) {
          branches[branch] = inline(branches[branch]);
        }
      }
      return branches;
    });
  }
  return resolved as Schema;
}

/**
 * The variant chosen by the values of the object the variants sit in.
 *
 * Read from the parent's `allOf`: the `then` whose `if` names the value
 * the deciding key holds. Null when the deciding key chose nothing.
 */
export function resolveVariants(parent: Schema, name: string, parentValues: Values): Schema | null {
  const by = parent.properties?.[name]?.['x-surface']?.by;
  if (by === undefined) {
    return null;
  }
  const value = parentValues[by];
  for (const condition of parent.allOf ?? []) {
    const decided = isRule(condition) ? undefined : condition.if?.properties?.[by];
    const branch = condition.then?.properties?.[name];
    if (decided !== undefined && sameValue(decided.const, value) && isVariant(branch)) {
      return branch;
    }
  }
  return null;
}

/** Whether a conditional's word on a key with variants is one of them, not that it does not apply. */
function isVariant(branch: Schema | false | undefined): branch is Schema {
  return isObject(branch);
}

/** The conditionals that each name a variant at a key. */
function variantsIn(conditions: Conditional[], name: string): Conditional[] {
  return conditions.filter((condition) => !isRule(condition) && isVariant(condition.then?.properties?.[name]));
}

/** Whether a conditional is a declared rule's, stated as written. */
function isRule(condition: Conditional): boolean {
  return condition['x-surface']?.declared === true;
}

/** The conditionals of one key that the coded refiners' enumeration wrote. */
function enumeratedOf(conditions: Conditional[], name: string): Conditional[] {
  return conditions.filter((condition) => !isRule(condition) && conditionalKey(condition) === name);
}

/** The conditionals of one key that its declared rules wrote, less those reaching inside it. */
function rulesOf(conditions: Conditional[], name: string): Conditional[] {
  return conditions.filter((condition) => isRule(condition) && conditionalKey(condition) === name && reachOf(condition, name) === null);
}

/**
 * Where a declared rule stated at a site rules out a key inside a part:
 * the path below the part, or null for any other conditional.
 *
 * A structure's rule that reads a port is stated on its container's frame,
 * an `if` on the container's keys and a `then` reaching into the part
 * where the structure sits: at an entry of a map of keys from the site,
 * `limits.student.per_order`, under `limits` (ContractEmitter::site()).
 */
function reachOf(condition: Conditional, name: string): string[] | null {
  if (!isRule(condition)) {
    return null;
  }
  let node: unknown = condition.then?.properties?.[name];
  const path: string[] = [];
  while (isObject(node)) {
    const entries = Object.entries(isObject(node.properties) ? node.properties : {});
    if (entries.length !== 1 || Object.keys(node).some((keyword) => keyword !== 'type' && keyword !== 'properties')) {
      return null;
    }
    path.push(entries[0][0]);
    node = entries[0][1];
  }
  return node === false && path.length > 0 ? path : null;
}

/** The declared rules stated at a site that reach inside one part. */
function reachesOf(conditions: Conditional[], name: string): Conditional[] {
  return conditions.filter((condition) => conditionalKey(condition) === name && reachOf(condition, name) !== null);
}

/**
 * A part as the rules that reach into it leave it for a frame's values.
 *
 * Each key a met rule rules out stays a property, so its frame keeps its
 * order, and is listed under its frame's `doesNotApply` by what it
 * depends on, as the server lists it; core() then leaves it out.
 */
function reached(part: Schema, reaches: Conditional[], name: string, values: Values): Schema {
  let out = part;
  for (const rule of reaches) {
    if (holdsIf(rule.if, values)) {
      out = ruledAt(out, reachOf(rule, name) as string[]);
    }
  }
  return out;
}

function ruledAt(frame: Schema, path: string[]): Schema {
  const [key, ...rest] = path;
  const property = frame.properties?.[key];
  if (property === undefined) {
    return frame;
  }
  const copy: Schema = { ...frame, properties: { ...frame.properties } };
  if (rest.length > 0) {
    (copy.properties as Record<string, Schema>)[key] = ruledAt(property, rest);
  }
  else {
    const own = { ...(frame['x-surface'] ?? {}) } as Extension;
    own.doesNotApply = { ...(own.doesNotApply ?? {}), [key]: property['x-surface']?.dependsOn ?? [] };
    copy['x-surface'] = own;
  }
  if (isPart(frame)) {
    parts.add(copy);
  }
  return copy;
}

/** The dotted paths an `if` reads, each once. */
function readBy(schema: unknown, prefix = ''): string[] {
  if (!isObject(schema)) {
    return [];
  }
  const paths: string[] = [];
  for (const [name, part] of Object.entries(isObject(schema.properties) ? schema.properties : {})) {
    paths.push(prefix + name, ...readBy(part, `${prefix}${name}.`));
  }
  for (const part of [schema.not, ...(Array.isArray(schema.allOf) ? schema.allOf : []), ...(Array.isArray(schema.anyOf) ? schema.anyOf : [])]) {
    paths.push(...readBy(part, prefix));
  }
  for (const name of Array.isArray(schema.required) ? schema.required : []) {
    paths.push(prefix + String(name));
  }
  return [...new Set(paths)];
}

/**
 * The keys a key's coded refiners watch: every key it depends on that its
 * enumerated conditionals name, or that no rule of it reads.
 */
function codedDeps(frame: Schema, name: string, deps: string[]): string[] {
  const conditions = frame.allOf ?? [];
  const named = new Set(enumeratedOf(conditions, name).flatMap((condition) => Object.keys(condition.if?.properties ?? {})));
  const read = new Set([...rulesOf(conditions, name), ...reachesOf(conditions, name)].flatMap((condition) => readBy(condition.if)));
  return deps.filter((dep) => named.has(dep) || ![...read].some((path) => path === dep || path.startsWith(`${dep}.`) || dep.startsWith(`${path}.`)));
}

/** Whether a value is a number, or a number's notation, as the server reads one. */
function numberOf(value: unknown): number | null {
  if (typeof value === 'number') {
    return value;
  }
  return typeof value === 'string' && /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/.test(value) ? Number(value) : null;
}

/**
 * Whether a declared rule's `if` holds for a frame's values.
 *
 * JSON Schema's own reading of the rules' closed vocabulary
 * (Refinement\Condition): `properties` and `required`, `const`, `enum`,
 * `type`, the four bounds, `not`, `allOf` and `anyOf`. A key holding
 * nothing, null or '', is null to it. Anything else is not read here.
 */
export function holdsIf(schema: unknown, value: unknown): boolean {
  if (typeof schema === 'boolean') {
    return schema;
  }
  if (!isObject(schema)) {
    throw new Unsettled('', 'unsettled');
  }
  const held = value === '' ? null : value;
  for (const [keyword, operand] of Object.entries(schema)) {
    switch (keyword) {
      case 'properties':
        if (isObject(held) && isObject(operand)) {
          for (const [name, part] of Object.entries(operand)) {
            if (held[name] !== undefined && !holdsIf(part, held[name])) {
              return false;
            }
          }
        }
        break;
      case 'required':
        if (isObject(held) && (operand as string[]).some((name) => held[name] === undefined)) {
          return false;
        }
        break;
      case 'const':
        if (held === null || !sameValue(operand, held)) {
          return false;
        }
        break;
      case 'enum':
        if (held === null || !(operand as unknown[]).some((option) => sameValue(option, held))) {
          return false;
        }
        break;
      case 'type': {
        const types = Array.isArray(operand) ? operand : [operand];
        const is = (type: unknown): boolean => {
          switch (type) {
            case 'null':
              return held === null;
            case 'number':
              return typeof held !== 'boolean' && numberOf(held) !== null;
            case 'integer':
              return typeof held !== 'boolean' && Number.isInteger(numberOf(held));
            case 'string':
              return typeof held === 'string';
            case 'boolean':
              return typeof held === 'boolean';
            case 'array':
              return Array.isArray(held);
            case 'object':
              return isObject(held);
            default:
              throw new Unsettled('', 'unsettled');
          }
        };
        if (!types.some(is)) {
          return false;
        }
        break;
      }
      case 'minimum':
      case 'maximum':
      case 'exclusiveMinimum':
      case 'exclusiveMaximum': {
        const number = typeof held === 'boolean' ? null : numberOf(held);
        const bound = operand as number;
        if (number !== null && !(keyword === 'minimum' ? number >= bound : keyword === 'maximum' ? number <= bound : keyword === 'exclusiveMinimum' ? number > bound : number < bound)) {
          return false;
        }
        break;
      }
      case 'not':
        if (holdsIf(operand, held)) {
          return false;
        }
        break;
      case 'allOf':
        if (!(operand as unknown[]).every((part) => holdsIf(part, held))) {
          return false;
        }
        break;
      case 'anyOf':
        if (!(operand as unknown[]).some((part) => holdsIf(part, held))) {
          return false;
        }
        break;
      default:
        throw new Unsettled('', 'unsettled');
    }
  }
  return true;
}

/**
 * Lays a declared rule's narrowing over a key, as the pipeline applies it.
 *
 * A rule tightens what the key allows by the time it runs (Rule::apply()):
 * the lower of two maxima, the higher of two minima, the values both lists
 * offer, in the key's own order; anything else it states replaces what
 * was there.
 */
function tighten(base: Schema, over: Schema): Schema {
  const tightened: Json = { ...(base as Json) };
  for (const [keyword, value] of Object.entries(over as Json)) {
    const before = tightened[keyword];
    if (typeof before === 'number' && typeof value === 'number' && ['maximum', 'maxLength', 'maxItems'].includes(keyword)) {
      tightened[keyword] = Math.min(before, value);
    }
    else if (typeof before === 'number' && typeof value === 'number' && ['minimum', 'minLength', 'minItems'].includes(keyword)) {
      tightened[keyword] = Math.max(before, value);
    }
    else if (keyword === 'oneOf' && Array.isArray(before) && Array.isArray(value)) {
      tightened[keyword] = before.filter((choice) => value.some((kept) => sameJson((kept as Json).const, (choice as Json).const)));
    }
    else {
      tightened[keyword] = value;
    }
  }
  return tightened as Schema;
}

/**
 * Applies a key's declared rules for a frame's values.
 *
 * Each conditional whose `if` holds, in order: one that rules the key out
 * ends it, one that requires it requires it, and one that narrows it
 * tightens what the key allows so far, and says why only where it did.
 */
function applyRules(rules: Conditional[], name: string, schema: Schema, values: Values, declared: Schema): { schema: Schema; ruled: boolean; required: boolean } {
  let effective = schema;
  let required = false;
  for (const rule of rules) {
    if (!holdsIf(rule.if, values)) {
      continue;
    }
    if (rulesOut(rule, name)) {
      return { schema: effective, ruled: true, required };
    }
    required = required || (rule.then.required ?? []).includes(name);
    const then = narrowingOf(rule, name);
    if (then !== null) {
      // The rule's sentence only where the rest of it did something, or
      // where it is all the rule says (Rule::apply()).
      const { description, ...rest } = overDeclared(then, declared);
      const next = tighten(effective, rest);
      const did = Object.keys(rest).length === 0 || !sameJson(next, effective);
      effective = did && description !== undefined ? { ...next, description } : next;
    }
  }
  return { schema: effective, ruled: false, required };
}

/**
 * A `then`'s narrowing of a key as laid over it: the `type` it states
 * beside a keyword only one type reads is the key's declared type, which
 * never narrows, so it is left out, and never widens what another
 * conditional narrowed already.
 */
function overDeclared(then: Schema, declared: Schema): Schema {
  if (then.type === undefined || !sameJson(then.type, declared.type)) {
    return then;
  }
  const { type: _type, ...rest } = then;
  return rest;
}

/** What a conditional narrows one key to: its schema, or null where it only rules it out or requires it. */
function narrowingOf(condition: Conditional, name: string): Schema | null {
  const branch = condition.then?.properties?.[name];
  return isObject(branch) ? (branch as Schema) : null;
}

/** Whether a conditional says one key does not apply. */
function rulesOut(condition: Conditional, name: string): boolean {
  return condition.then?.properties?.[name] === false;
}

/**
 * Every dotted path something refines against, as the values stand.
 *
 * `dependsOn` names siblings, so each is resolved against the path of
 * the object it sits in; fieldsets and chosen variants are walked.
 */
export function watchedPaths(schema: Schema, values: Values, prefix = ''): Set<string> {
  const watched = new Set<string>();
  // A key that does not apply is no property, and what it depends on is
  // still watched: a change of that may make it apply.
  for (const dependencies of Object.values(schema['x-surface']?.doesNotApply ?? {})) {
    for (const dependency of dependencies) {
      watched.add(prefix + dependency);
    }
  }
  for (const [name, property] of Object.entries(schema.properties ?? {})) {
    for (const dependency of property['x-surface']?.dependsOn ?? []) {
      watched.add(prefix + dependency);
    }
    const nested = values[name];
    if (property['x-surface']?.list && property.items !== undefined && Array.isArray(nested)) {
      // Each item of a collection is a frame of its own, under its delta.
      const items = property.items;
      nested.forEach((item, delta) => {
        watchedPaths(items, isObject(item) ? item : {}, `${prefix}${name}.${delta}.`).forEach((path) => watched.add(path));
      });
      continue;
    }
    const nestedValues = isObject(nested) ? nested : {};
    const widget = property['x-surface']?.widget;
    const inner = widget === 'variants' ? resolveVariants(schema, name, values) : widget === 'fieldset' ? property : null;
    if (inner !== null) {
      watchedPaths(inner, nestedValues, `${prefix}${name}.`).forEach((path) => watched.add(path));
    }
  }
  return watched;
}

export function isObject(value: unknown): value is Values {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Reads the value at a dotted path. */
export function getAt(values: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((current, segment) => {
    if (Array.isArray(current)) {
      return current[Number(segment)];
    }
    return isObject(current) ? current[segment] : undefined;
  }, values);
}

/** Writes a value at a dotted path, copying what it passes through. */
export function setAt<T>(values: T, path: string, value: unknown): T {
  const [head, ...rest] = path.split('.');
  const nested = rest.length === 0 ? value : setAt(getAt(values, head) ?? {}, rest.join('.'), value);
  if (Array.isArray(values)) {
    const copy = [...values];
    copy[Number(head)] = nested;
    return copy as T;
  }
  return { ...(isObject(values) ? values : {}), [head]: nested } as T;
}

/** Turns a path into an element id. */
export function fieldId(path: string): string {
  return `dsr-${path.replace(/[^A-Za-z0-9_-]/g, '-')}`;
}

// Narrowing here.
//
// The full schema states every dependency that can be listed as a
// conditional on the frame it sits in, and marks the rest: `refine` (a
// static rule it could not list, which the server's narrowing runs for values) and
// `live` (a rule only the server can apply). So a change whose dependents are all stated can be narrowed
// in the page, from the full schema, by the module's own rules; a change
// with a `refine` or `live` dependent is asked of the server. The page's
// answer must be the server's: the same discard and orphan rule
// (DataSurfaceFormBuilder::settle()), the same reading of a select
// (ContractEmitter::property()), keyword for keyword.

/** Why a change was asked of the server rather than narrowed here. */
export type Marker = 'live' | 'refine' | 'access' | 'stored' | 'unsettled' | 'pending';

/** A change the page cannot narrow by itself, and the key that says why. */
export class Unsettled extends Error {
  constructor(
    readonly key: string,
    readonly marker: Marker,
  ) {
    super(`${key} cannot be narrowed here (${marker})`);
  }
}

/** A form's state as a schema for its values: what the server answered, or what was narrowed here. */
export interface Settled {
  /** The schema for these answers, inline. */
  schema: Schema;
  /** The values it describes, as shown: a stale value is null. */
  values: Values;
  /** The paths shown on the empty option, standing for a stored value. */
  stale: string[];
}

export interface Narrowing {
  /** The full schema, inline: every key as declared, every listable dependency a conditional. */
  full: Schema;
  /** What the form shows now. */
  previous: Settled;
  /** The values the operation was loaded with: what is stored, over the defaults. */
  stored: Values;
  /** The values now, the change made. */
  values: Values;
  /** The stale paths as the page would send them, the changed path's taken out. */
  stale: string[];
  /** The path that changed. */
  changed: string;
}

export type Narrowed =
  | { local: true; keys: string[]; settled: Settled }
  | { local: false; key: string; marker: Marker };

/** What the server would be asked about, said in the panel. */
export function narrowingStatus(narrowed: Narrowed): string {
  if (narrowed.local) {
    return `Narrowed here: ${narrowed.keys.join(', ')}`;
  }
  const why: Record<Marker, string> = {
    live: 'depends on a live rule',
    refine: 'depends on a rule to refine with values',
    access: 'has access only the server decides',
    stored: 'stands for a stored value only the server holds',
    unsettled: 'could not be narrowed here',
    pending: 'changed while an earlier change was being asked',
  };
  return `Asked the server: ${narrowed.key} ${why[narrowed.marker]}`;
}

/**
 * Narrows a change from the full schema, or says why the server must.
 *
 * Every key that depends on the changed one, transitively, is found
 * through `dependsOn`; one marked `refine` or `live`, one inside a
 * part that is, or one standing for a stored value the page never saw,
 * sends the change to the server. Otherwise the change is settled here, the
 * way the server's narrowing settles it: an answer the new choices orphan is dropped and
 * falls back to what is stored, a stored value the edit orphaned is held
 * unanswered and listed stale, and every key is read from the full
 * schema's conditionals for the values that result. Anything the
 * resolver cannot settle is asked rather than guessed.
 */
export function narrow(request: Narrowing): Narrowed {
  try {
    const found = dependents(request.full, request.values, request.changed);
    if (found.names.length === 0) {
      return { local: false, key: request.changed, marker: 'unsettled' };
    }
    const keys = found.names.map((name) => found.prefix + name);
    const marked = markedIn(found.frame, found.names, found.prefix);
    if (marked !== null) {
      return { local: false, ...marked };
    }
    // A stale value the page was handed as nothing, which only the server
    // knows: a dependent or a dependency of one cannot be judged here.
    const unknown = request.stale.filter((path) => isEmpty(getAt(request.stored, path)));
    const touched = new Set(keys.flatMap((key) => [key, ...dependsOnOf(found.frame, key.slice(found.prefix.length)).map((dep) => found.prefix + dep)]));
    const hidden = unknown.find((path) => [...touched].some((key) => path === key || path.startsWith(`${key}.`)));
    if (hidden !== undefined) {
      return { local: false, key: hidden, marker: 'stored' };
    }
    return { local: true, keys, settled: resolveLocally(request, new Set(unknown)) };
  }
  catch (error) {
    if (error instanceof Unsettled) {
      return { local: false, key: error.key === '' ? request.changed : error.key, marker: error.marker };
    }
    return { local: false, key: request.changed, marker: 'unsettled' };
  }
}

function dependsOnOf(frame: Schema, name: string): string[] {
  return frame.properties?.[name]?.['x-surface']?.dependsOn ?? [];
}

function asValues(value: unknown): Values {
  return isObject(value) ? value : {};
}

/** Whether `dep`, a `dependsOn` entry, watches `key`: the key itself, inside it, or around it. */
function watches(dep: string, key: string): boolean {
  return dep === key || key.startsWith(`${dep}.`) || dep.startsWith(`${key}.`);
}

/**
 * The keys that depend on a path, transitively, in the frame they share.
 *
 * `dependsOn` names siblings, so the path is read from every frame it
 * passes through, the top's and each child's, relative to that frame; a
 * key an alter mounted is named in the frame by its dotted path. The wall
 * means only one frame can answer.
 */
export function dependents(full: Schema, values: Values, path: string): { frame: Schema; prefix: string; names: string[] } {
  const segments = path.split('.');
  const found: { frame: Schema; prefix: string; names: string[] }[] = [];
  let frame: Schema | null = full;
  let held = values;
  let prefix = '';
  let i = 0;
  while (frame !== null && i < segments.length) {
    const names = closure(frame, segments.slice(i).join('.'));
    if (names.length > 0) {
      found.push({ frame, prefix, names });
    }
    const name = segments[i];
    const property: Schema | undefined = frame.properties?.[name];
    if (property === undefined || i === segments.length - 1) {
      break;
    }
    if (property['x-surface']?.widget === 'variants') {
      frame = resolveVariants(frame, name, held);
      held = asValues(held[name]);
      prefix += `${name}.`;
      i += 1;
    }
    else if (property['x-surface']?.list && property.items !== undefined) {
      const delta = segments[i + 1];
      frame = property.items;
      held = asValues((Array.isArray(held[name]) ? (held[name] as unknown[]) : [])[Number(delta)]);
      prefix += `${name}.${delta}.`;
      i += 2;
    }
    else if (property.properties !== undefined) {
      frame = property;
      held = asValues(held[name]);
      prefix += `${name}.`;
      i += 1;
    }
    else {
      break;
    }
  }
  if (found.length > 1) {
    throw new Unsettled(path, 'unsettled');
  }
  return found[0] ?? { frame: full, prefix: '', names: [] };
}

function closure(frame: Schema, key: string): string[] {
  const properties = frame.properties ?? {};
  const reached = new Set<string>();
  const queue = [key];
  while (queue.length > 0) {
    const next = queue.shift() as string;
    for (const [name, property] of Object.entries(properties)) {
      if (!reached.has(name) && (property['x-surface']?.dependsOn ?? []).some((dep) => watches(dep, next))) {
        reached.add(name);
        queue.push(name);
      }
    }
  }
  return Object.keys(properties).filter((name) => reached.has(name));
}

/** The first dependent the server must narrow, by its marker: on the key, or anywhere inside it. */
function markedIn(frame: Schema, names: string[], prefix: string): { key: string; marker: Marker } | null {
  const marker = (schema: Schema): Marker | null =>
    schema['x-surface']?.live ? 'live' : schema['x-surface']?.refine ? 'refine' : null;
  const inside = (schema: Schema, at: string): { key: string; marker: Marker } | null => {
    for (const [name, property] of Object.entries(schema.properties ?? {})) {
      const own = marker(property);
      if (own !== null) {
        return { key: at + name, marker: own };
      }
      const deeper = inside(property, `${at}${name}.`) ?? (property.items ? inside(property.items, `${at}${name}.*.`) : null);
      if (deeper !== null) {
        return deeper;
      }
    }
    for (const condition of schema.allOf ?? []) {
      for (const [name, branch] of Object.entries(condition.then?.properties ?? {})) {
        const deeper = isObject(branch) && isPart(branch as Schema) ? inside(branch as Schema, `${at}${name}.`) : null;
        if (deeper !== null) {
          return deeper;
        }
      }
    }
    return null;
  };
  for (const name of names) {
    const property = frame.properties?.[name] ?? {};
    const own = marker(property);
    if (own !== null) {
      return { key: prefix + name, marker: own };
    }
    const branches = (frame.allOf ?? []).filter((condition) => conditionalKey(condition) === name).map((condition) => narrowingOf(condition, name)).filter((branch): branch is Schema => branch !== null);
    for (const child of [property, ...branches, ...(property.items ? [property.items] : [])]) {
      const deeper = inside(child, `${prefix}${name}.`);
      if (deeper !== null) {
        return deeper;
      }
    }
  }
  return null;
}

/**
 * Refuses a frame the page cannot read: a reference left unresolved (one
 * outside `#/$defs`, or back into a def being read), or a conditional not
 * of the emitter's one shape, an `if` of `const`s on keys of this frame
 * and a `then` narrowing one key.
 */
function assertReadable(frame: Schema, path: string): void {
  const properties = frame.properties ?? {};
  for (const [name, property] of Object.entries(properties)) {
    if (property.$ref !== undefined) {
      throw new Unsettled(path + name, 'unsettled');
    }
  }
  for (const condition of frame.allOf ?? []) {
    // One key a `then` speaks of: narrowed, ruled out, or required here.
    const narrowed = [...new Set([...Object.keys(condition?.then?.properties ?? {}), ...(condition?.then?.required ?? [])])];
    if (isObject(condition) && isRule(condition)) {
      // A rule's `if` reads keys of this frame; its `then` speaks of one
      // key as a whole, or rules out one key inside a part, not of a key
      // inside a plain map, which is the server's.
      const then = narrowed.length === 1 ? condition.then?.properties?.[narrowed[0]] : undefined;
      const reach = narrowed.length === 1 ? reachOf(condition, narrowed[0]) : null;
      if (
        narrowed.length !== 1
        || 'else' in condition
        || readBy(condition.if).some((read) => !(read.split('.')[0] in properties))
        || (reach !== null && !isPart(properties[narrowed[0]]))
        || (reach === null && isObject(then) && ('properties' in then || 'required' in then))
      ) {
        throw new Unsettled(narrowed.length === 1 ? path + narrowed[0] : '', 'unsettled');
      }
      continue;
    }
    const named = Object.entries(condition?.if?.properties ?? {});
    if (
      narrowed.length !== 1
      || named.length === 0
      || 'else' in condition
      || named.some(([key, test]) => !(key in properties) || !isObject(test) || !('const' in test))
    ) {
      throw new Unsettled(narrowed.length === 1 ? path + narrowed[0] : '', 'unsettled');
    }
  }
}

/** The key a conditional speaks of: the emitter writes one per conditional. */
function conditionalKey(condition: Conditional): string | undefined {
  return Object.keys(condition.then?.properties ?? {})[0] ?? condition.then?.required?.[0];
}

function isVariants(schema: Schema): boolean {
  return schema['x-surface']?.widget === 'variants';
}

function isCollection(schema: Schema): boolean {
  return Boolean(schema['x-surface']?.list) && schema.items !== undefined;
}

function norm(value: unknown): unknown {
  return value === undefined ? null : value;
}

/** What a frame starts from, as the build step's defaults: each key's `default`, a locked key's `const`. */
function frameDefaults(frame: Schema): Values {
  const defaults: Values = {};
  for (const [name, property] of Object.entries(frame.properties ?? {})) {
    if (property.readOnly && property.const !== undefined) {
      defaults[name] = property.const;
    }
    else if (property.default !== undefined) {
      defaults[name] = property.default;
    }
  }
  return defaults;
}

/** Whether a value can be held by a variant: every key it carries is one the variant declares. */
function fits(variant: Schema, value: unknown): boolean {
  if (Array.isArray(value) && value.length === 0) {
    return true;
  }
  return isObject(value) && Object.keys(value).every((key) => key in (variant.properties ?? {}));
}

/** The values a select offers, none for a list of nothing, or null for a key with no list. */
function offered(schema: Schema): unknown[] | null {
  if (schema.oneOf === undefined) {
    return null;
  }
  return choices(schema).map((choice) => choice.const);
}

/** OptionSet::allows(): an integer or a string the list names. */
function allows(list: unknown[], value: unknown): boolean {
  return (typeof value === 'string' || Number.isInteger(value)) && list.some((option) => String(option) === String(value));
}

/** DataSurfaceFormBuilder::sameAnswer(): a form posts every scalar as a string. */
function sameAnswer(answer: unknown, stored: unknown): boolean {
  if (isEmpty(answer) || isEmpty(stored)) {
    return isEmpty(answer) === isEmpty(stored);
  }
  if (typeof answer === 'boolean' || typeof stored === 'boolean') {
    return Boolean(answer) === Boolean(stored);
  }
  if (typeof answer !== 'object' && typeof stored !== 'object') {
    return String(answer) === String(stored);
  }
  return sameJson(answer, stored);
}

/** Whether a conditional's `if` holds for a frame's values. */
function met(condition: Conditional, values: Values): boolean {
  return Object.entries(condition.if.properties).every(([key, { const: value }]) => !isEmpty(getAt(values, key)) && sameValue(value, getAt(values, key)));
}

/** The keywords whose change the emitter counts as narrowed (ContractEmitter::signature()). */
const NARROWING = ['type', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'not', 'format'] as const;

function narrows(declared: Schema, effective: Schema): boolean {
  if (NARROWING.some((keyword) => !sameJson((declared as Json)[keyword], (effective as Json)[keyword]))) {
    return true;
  }
  if (!sameJson((offered(declared) ?? []).map(String), (offered(effective) ?? []).map(String))) {
    return true;
  }
  return Object.entries(effective.properties ?? {}).some(([name, property]) => narrows(declared.properties?.[name] ?? {}, property));
}

/** Answered for a list the page cannot read for these values. */
const UNKNOWN = Symbol('unknown');

interface Labels {
  required: string;
  optional: string;
  /** The server's words for each select it served standing for a stale value, by path. */
  kept: Map<string, string>;
}

/**
 * The empty option of a select standing for a stale value, as
 * OptionsWidget::keepLabel() words it: what the server served for that
 * path when it served one, else the same words in English.
 */
function keepLabel(labels: Labels, path: string, value: unknown): string {
  return labels.kept.get(path) ?? `- Keep ${typeof value === 'string' ? value : JSON.stringify(value)} (no longer offered) -`;
}

/** Reads the server's "keep" wording of each stale select it served, by path. */
function keptLabels(node: Schema, prefix: string, kept: Map<string, string>): Map<string, string> {
  for (const [name, property] of Object.entries(node.properties ?? {})) {
    if (!isObject(property)) {
      continue;
    }
    const extension = property['x-surface'] as Extension | undefined;
    if (extension?.stale && extension.emptyOption !== undefined && extension.widget === 'select') {
      kept.set(prefix + name, extension.emptyOption.label);
    }
    if (isObject(property.properties)) {
      keptLabels(property, `${prefix}${name}.`, kept);
    }
  }
  return kept;
}

/** The empty option's labels, as the server translated them: read from what it served. */
function labels(schema: Schema): Labels {
  const found: Partial<Labels> = {};
  const walk = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(walk);
      return;
    }
    if (!isObject(node)) {
      return;
    }
    // A frame (an object with its properties and `required`) says which
    // of its selects may be cleared: those it does not require and whose
    // choices offer null. Radios say "- None -" whatever they are.
    if (jsonType(node as Schema) === 'object' && isObject(node.properties)) {
      const required = Array.isArray(node.required) ? (node.required as string[]) : [];
      for (const [name, property] of Object.entries(node.properties)) {
        const extension = isObject(property) ? (property['x-surface'] as Extension | undefined) : undefined;
        const option = extension?.emptyOption;
        // A stale select says it keeps its value, in words of its own.
        if (option !== undefined && !extension?.stale && isObject(property) && property.oneOf !== undefined && extension?.widget === 'select') {
          found[holdsValue(property as Schema, required.includes(name)) ? 'required' : 'optional'] ??= option.label;
        }
      }
    }
    Object.values(node).forEach(walk);
  };
  walk(schema);
  return { required: found.required ?? '- Select -', optional: found.optional ?? '- None -', kept: keptLabels(schema, '', new Map()) };
}

interface Context {
  /** The keys held unanswered, by path, each with the stored value it stands for. */
  standing: Map<string, unknown>;
  /** The stale paths whose stored value only the server knows. */
  unknown: Set<string>;
  labels: Labels;
  previous: Settled;
}

/**
 * Settles one frame, then each child in it, as DataSurfaceFormBuilder::settle().
 *
 * Every key that refines against a sibling is a target. An input whose
 * dependency moved under it, or that the narrowed list no longer offers,
 * is dropped and falls back to what is stored; a key falling back to a
 * stored value its moved dependency no longer offers is held unanswered
 * and stands for it. Repeated to the fixed point. A list read for values
 * the page cannot narrow for (a `refine` or `live` key whose dependencies
 * moved) is assumed to keep the input, and the frame is refused unless
 * that key was dropped anyway.
 */
function settle(full: Schema, previous: Schema | null, input: Values, stored: Values, before: Values | null, path: string, top: boolean, context: Context): Values {
  assertReadable(full, path);
  const properties = full.properties ?? {};
  const targets = Object.keys(properties).filter((name) => (properties[name]['x-surface']?.dependsOn ?? []).length > 0);
  const overlay: Values = { ...stored, ...input };
  const discarded: string[] = [];
  const orphaned = new Map<string, unknown>();
  const uncertain = new Map<string, Marker>();

  const frameValues = (): Values => {
    const kept: Values = { ...input };
    for (const name of discarded) {
      delete kept[name];
    }
    const values: Values = { ...stored, ...kept };
    for (const name of orphaned.keys()) {
      values[name] = null;
    }
    return values;
  };
  const listAt = (name: string, values: Values): unknown[] | null | typeof UNKNOWN => {
    const declared = properties[name];
    const extension = declared['x-surface'];
    const deps = extension?.dependsOn ?? [];
    if (jsonType(declared) === 'array') {
      return null;
    }
    const was = previous?.properties?.[name];
    if (was !== undefined && before !== null && deps.every((dep) => sameJson(norm(getAt(values, dep)), norm(getAt(before, dep))))) {
      return offered(was);
    }
    if (extension?.live || extension?.refine) {
      uncertain.set(name, extension.live ? 'live' : 'refine');
      return UNKNOWN;
    }
    const rules = extension?.declared ? rulesOf(full.allOf ?? [], name) : [];
    let schema = declared;
    if (extension?.enumerated) {
      const held = (rules.length > 0 ? codedDeps(full, name, deps) : deps).map((dep) => !isEmpty(getAt(values, dep)));
      if (held.every(Boolean)) {
        const narrowed = enumeratedOf(full.allOf ?? [], name).filter((condition) => met(condition, values));
        if (narrowed.some((condition) => rulesOut(condition, name))) {
          // It does not apply: no list to fall out of.
          return null;
        }
        schema = narrowed.reduce<Schema>((reached, condition) => {
          const branch = narrowingOf(condition, name);
          return branch === null ? reached : merge(reached as Json, overDeclared(branch, declared) as Json) as Schema;
        }, declared);
      }
      else if (held.some(Boolean) || rules.length > 0) {
        uncertain.set(name, 'unsettled');
        return UNKNOWN;
      }
    }
    if (rules.length > 0) {
      // Each declared rule that holds, after what the coded ones answer.
      const applied = applyRules(rules, name, schema, values, declared);
      if (applied.ruled) {
        return null;
      }
      schema = applied.schema;
    }
    return offered(schema);
  };
  const stillStands = (name: string, values: Values): boolean => {
    const declared = properties[name];
    for (const dep of declared['x-surface']?.dependsOn ?? []) {
      if (!sameJson(norm(getAt(overlay, dep)), norm(getAt(values, dep)))) {
        return false;
      }
    }
    if (isVariants(declared)) {
      const variant = resolveVariants(full, name, values);
      return variant === null || fits(variant, input[name]);
    }
    if (declared.properties !== undefined && !isPart(declared)) {
      // A module's keys, mounted: which of them the dependency narrows
      // is the server's to say, and nothing moved under them.
      return true;
    }
    const value = input[name];
    if (isEmpty(value) || (typeof value !== 'string' && !Number.isInteger(value))) {
      return true;
    }
    const list = listAt(name, values);
    return list === UNKNOWN || list === null || allows(list, value);
  };
  const isOrphaned = (name: string, values: Values): boolean => {
    const declared = properties[name];
    const value = values[name];
    if ((typeof value !== 'string' && !Number.isInteger(value)) || isEmpty(value)) {
      return false;
    }
    const moved = (declared['x-surface']?.dependsOn ?? []).some((dep) => !sameAnswer(getAt(values, dep), getAt(stored, dep)));
    if (!moved || isVariants(declared)) {
      return false;
    }
    const list = listAt(name, values);
    if (list === UNKNOWN) {
      throw new Unsettled(path + name, uncertain.get(name) ?? 'unsettled');
    }
    return list !== null && !allows(list, value);
  };

  let again: boolean;
  let values: Values;
  do {
    values = frameValues();
    again = false;
    for (const name of targets) {
      if (!discarded.includes(name) && !stillStands(name, values)) {
        const declared = properties[name];
        if (declared.properties !== undefined && !isPart(declared) && !isVariants(declared)) {
          throw new Unsettled(path + name, declared['x-surface']?.refine ? 'refine' : 'unsettled');
        }
        discarded.push(name);
        again = true;
      }
    }
    if (again) {
      continue;
    }
    for (const name of targets) {
      if (!orphaned.has(name) && discarded.includes(name) && isOrphaned(name, values)) {
        orphaned.set(name, values[name]);
        again = true;
      }
    }
  } while (again);
  for (const name of uncertain.keys()) {
    if (!discarded.includes(name)) {
      throw new Unsettled(path + name, uncertain.get(name) as Marker);
    }
  }
  for (const [name, value] of orphaned) {
    context.standing.set(path + name, value);
  }

  // What the overlay keeps of the frame: at the top, what is stored under
  // the input that stands; inside a child, the child's own input, its
  // dropped keys left to the child's defaults.
  if (!top) {
    values = { ...input };
    for (const name of discarded) {
      delete values[name];
    }
    for (const name of orphaned.keys()) {
      values[name] = null;
    }
    // A key the page did not send because it did not apply holds what is
    // stored again once it does.
    for (const name of Object.keys(properties)) {
      if (!(name in values) && !discarded.includes(name) && name in stored && !(name in (full['x-surface']?.doesNotApply ?? {}))) {
        values[name] = stored[name];
      }
    }
  }
  for (const [name, declared] of Object.entries(properties)) {
    if (discarded.includes(name)) {
      continue;
    }
    const held = input[name];
    if (isCollection(declared) && Array.isArray(held)) {
      const kept = Array.isArray(stored[name]) ? (stored[name] as unknown[]) : [];
      values[name] = held.map((item, delta) => isObject(item)
        ? settle(declared.items as Schema, null, item, { ...frameDefaults(declared.items as Schema), ...asValues(kept[delta]) }, null, `${path}${name}.${delta}.`, false, context)
        : item);
      continue;
    }
    if (!isObject(held) || (!isPart(declared) && !isVariants(declared))) {
      continue;
    }
    const reaches = reachesOf(full.allOf ?? [], name);
    const child = isVariants(declared) ? resolveVariants(full, name, values) : reaches.length > 0 ? reached(declared, reaches, name, values) : declared;
    if (child === null) {
      continue;
    }
    const was = isVariants(declared) ? (previous === null ? null : resolveVariants(previous, name, before ?? {})) : previous?.properties?.[name] ?? null;
    const sameVariant = !isVariants(declared) || (before !== null && sameValue(getAt(values, declared['x-surface']?.by ?? ''), getAt(before, declared['x-surface']?.by ?? '')));
    let kept: unknown = stored[name];
    if (isVariants(declared) && !fits(child, kept)) {
      kept = null;
    }
    values[name] = settle(child, sameVariant ? was : null, held, { ...frameDefaults(child), ...asValues(kept) }, sameVariant && before !== null ? asValues(before[name]) : null, `${path}${name}.`, false, context);
  }
  return values;
}

/**
 * One frame's schema for its values, less what only the present moment
 * says of a key (stale, the empty option), which present() adds.
 *
 * A key whose dependencies hold what they held when the form's schema was
 * last settled keeps what it said then; any other is read from the full
 * schema: as declared, with the `then` of each conditional its values
 * meet laid over it once every key it watches holds a value, and with
 * its conditionals, held to what its dependencies still offer, while one
 * holds nothing (`enumerated`). A `refine` or `live` key is never read
 * here.
 */
function core(full: Schema, previous: Schema | null, values: Values, before: Values | null, path: string, context: Context): Schema {
  assertReadable(full, path);
  const properties: Record<string, Schema> = {};
  const conditions: Record<string, Conditional[]> = {};
  // The frame's `required`: what the full schema requires, what a met
  // conditional requires only there, and what was required when a kept
  // key was last settled.
  const required = new Set<string>(full.required ?? []);
  const ruled: Record<string, string[]> = { ...(full['x-surface']?.doesNotApply ?? {}) };
  const fullConditions = full.allOf ?? [];
  const previousConditions = previous?.allOf ?? [];
  const mine = (list: Conditional[], name: string) => list.filter((condition) => conditionalKey(condition) === name);
  for (const [name, stated] of Object.entries(full.properties ?? {})) {
    const at = path + name;
    if (name in (full['x-surface']?.doesNotApply ?? {})) {
      // Ruled out by a rule its container states (reached()).
      required.delete(name);
      continue;
    }
    // A part, as the rules this frame states inside it leave it.
    const reaches = reachesOf(fullConditions, name);
    const declared = reaches.length > 0 ? reached(stated, reaches, name, values) : stated;
    const extension = declared['x-surface'];
    const deps = extension?.dependsOn ?? [];
    const was = previous?.properties?.[name];
    const held = values[name];
    if (extension?.access === 'live') {
      // Its access is the server's to settle, which the schema for these
      // answers did: left out there, it stays out; locked there, it stays
      // as it was; with nothing settled for this frame, the server is asked.
      if (previous === null) {
        throw new Unsettled(at, 'access');
      }
      if (was === undefined) {
        required.delete(name);
        continue;
      }
      if (was.readOnly) {
        properties[name] = was;
        if (!(previous.required ?? []).includes(name)) {
          required.delete(name);
        }
        conditions[name] = mine(previousConditions, name);
        continue;
      }
    }
    if (was !== undefined && before !== null && deps.every((dep) => sameJson(norm(getAt(values, dep)), norm(getAt(before, dep))))) {
      properties[name] = was;
      if ((previous?.required ?? []).includes(name)) {
        required.add(name);
      }
      conditions[name] = mine(previousConditions, name);
      if (isPart(declared) && !isCollection(declared)) {
        properties[name] = core(declared, was, { ...frameDefaults(declared), ...asValues(held) }, asValues(before[name]), `${at}.`, context);
      }
      else if (isVariants(declared)) {
        const by = extension?.by ?? '';
        conditions[name] = variantsIn(previousConditions, name).map((condition) => {
          if (!sameValue(condition.if.properties[by]?.const, values[by])) {
            return condition;
          }
          const fullBranch = variantsIn(fullConditions, name).find((branch) => sameValue(branch.if.properties[by]?.const, values[by]));
          const variant = fullBranch === undefined ? null : narrowingOf(fullBranch, name);
          if (variant === null) {
            throw new Unsettled(at, 'unsettled');
          }
          const own = { ...frameDefaults(variant), ...(fits(variant, held) ? asValues(held) : {}) };
          return { ...condition, then: { ...condition.then, properties: { [name]: core(variant, narrowingOf(condition, name), own, asValues(before[name]), `${at}.`, context) } } };
        });
      }
      continue;
    }
    if (extension?.live) {
      throw new Unsettled(at, 'live');
    }
    if (extension?.refine) {
      throw new Unsettled(at, 'refine');
    }
    // A key's declared rules, and the keys its coded refiners watch.
    // Decision: see docs/decisions.md#a-rule-declared-as-data.
    const rules = extension?.declared ? rulesOf(fullConditions, name) : [];
    const coded = rules.length > 0 ? codedDeps(full, name, deps) : deps;
    if (extension?.enumerated) {
      // Whether it applies, and whether it is required here, once every
      // key it watches holds a value: a key ruled out is no property, and
      // has no conditional.
      const holds = coded.map((dep) => !isEmpty(getAt(values, dep)));
      if (holds.every(Boolean)) {
        const holding = enumeratedOf(fullConditions, name).filter((condition) => met(condition, values));
        if (holding.some((condition) => rulesOut(condition, name))) {
          ruled[name] = deps;
          continue;
        }
        if (holding.some((condition) => (condition.then.required ?? []).includes(name))) {
          required.add(name);
        }
      }
      else if (isVariants(declared) || isPart(declared) || rules.length > 0) {
        // Whether a part or a variant applies is stated only for every key
        // it watches, and a rule is laid over what those answer; the rest
        // is the server's to answer.
        throw new Unsettled(at, 'unsettled');
      }
    }
    if (rules.length > 0) {
      // Whether a rule rules it out, or requires it, for these values.
      const applied = applyRules(rules, name, declared, values, declared);
      if (applied.ruled) {
        ruled[name] = deps;
        continue;
      }
      if (applied.required) {
        required.add(name);
      }
    }
    if (isVariants(declared)) {
      properties[name] = extension?.enumerated || extension?.declared ? { ...declared, 'x-surface': withoutEnumerated(extension) } : declared;
      const by = extension?.by ?? '';
      conditions[name] = variantsIn(fullConditions, name).map((condition) => {
        const variant = narrowingOf(condition, name) as Schema;
        const chosen = sameValue(condition.if.properties[by]?.const, values[by]);
        const own = { ...frameDefaults(variant), ...(chosen && fits(variant, held) ? asValues(held) : {}) };
        return { ...condition, then: { ...condition.then, properties: { [name]: core(variant, null, own, null, `${at}.`, context) } } };
      });
      continue;
    }
    if (isPart(declared) && !isCollection(declared)) {
      properties[name] = core(declared, null, { ...frameDefaults(declared), ...asValues(held) }, null, `${at}.`, context);
      if (extension?.enumerated || extension?.declared) {
        properties[name] = { ...properties[name], 'x-surface': withoutEnumerated(properties[name]['x-surface']) };
      }
      conditions[name] = [];
      continue;
    }
    // A key, a list of values, a plain map or a collection: narrowed by
    // its conditionals.
    let effective = declared;
    let enumerated = Boolean(extension?.enumerated);
    conditions[name] = [];
    if (enumerated) {
      const holds = coded.map((dep) => !isEmpty(getAt(values, dep)));
      if (holds.every(Boolean)) {
        for (const condition of enumeratedOf(fullConditions, name)) {
          const then = narrowingOf(condition, name);
          if (then !== null && met(condition, values)) {
            if (then.type !== undefined && !sameJson(then.type, declared.type) && !(condition.then.required ?? []).includes(name)) {
              // Requiredness narrowed, and the frame's `required` not said.
              throw new Unsettled(at, 'unsettled');
            }
            effective = merge(effective as Json, overDeclared(then, declared) as Json) as Schema;
          }
        }
        enumerated = false;
      }
      else if (holds.some(Boolean)) {
        throw new Unsettled(at, 'unsettled');
      }
      else {
        // What the emitter enumerates over: each watched key's list as it
        // stands, so a capacity offers the conditionals of the rooms the
        // venue still offers.
        conditions[name] = enumeratedOf(fullConditions, name).filter((condition) => Object.entries(condition.if.properties).every(([dep, { const: value }]) => {
          const list = properties[dep] ?? (dep in (full.properties ?? {}) ? undefined : null);
          if (list === null || list === undefined) {
            throw new Unsettled(at, 'unsettled');
          }
          return (offered(list) ?? []).some((option) => sameValue(option, value));
        }));
      }
    }
    if (rules.length > 0) {
      // Each rule that holds, tightened over what the coded ones answer.
      effective = applyRules(rules, name, effective, values, declared).schema;
    }
    if (isCollection(declared)) {
      // The items are the child's def, described from nothing: no value
      // inside one changes the schema.
      effective = { ...effective, items: core(declared.items as Schema, null, {}, null, `${at}.*.`, context) };
    }
    const own: Extension = { ...(effective['x-surface'] as Extension), refined: narrows(declared, effective) };
    if (!enumerated) {
      delete own.enumerated;
    }
    // Evaluated for these values: no rule is stated in their schema.
    delete own.declared;
    properties[name] = { ...effective, 'x-surface': own };
  }
  const allOf = Object.keys(full.properties ?? {}).flatMap((name) => conditions[name] ?? []);
  const frame: Schema = { ...full, properties };
  delete frame.allOf;
  delete frame.required;
  const requires = Object.keys(properties).filter((name) => required.has(name));
  if (requires.length > 0) {
    frame.required = requires;
  }
  if (allOf.length > 0) {
    frame.allOf = allOf;
  }
  // Every key ruled out keeps its declaration order, as the server's does.
  // A child's frame is its site's property too, whose `x-surface` stays.
  const out = Object.keys(full.properties ?? {}).filter((name) => name in ruled);
  const own = { ...(full['x-surface'] ?? {}) } as Extension;
  delete own.doesNotApply;
  if (out.length > 0) {
    own.doesNotApply = Object.fromEntries(out.map((name) => [name, ruled[name]]));
  }
  if (Object.keys(own).length > 0) {
    frame['x-surface'] = own;
  }
  else {
    delete frame['x-surface'];
  }
  return frame;
}

/** A key's `x-surface` once what it watches holds values: no longer enumerated, its rules evaluated. */
function withoutEnumerated(extension: Extension | undefined): Extension | undefined {
  if (extension === undefined || (!extension.enumerated && !extension.declared)) {
    return extension;
  }
  const own = { ...extension };
  delete own.enumerated;
  delete own.declared;
  return own;
}

/**
 * Adds what is true of one moment to a frame's schema, and reads its values as shown.
 *
 * ContractEmitter::frame() and property(): a select's empty option by the
 * empty option rule, a stored value no longer offered shown as nothing
 * and listed stale, an orphan stale whatever its widget, the chosen
 * variant, a part narrowed when a key inside it is. A key holding nothing
 * shows its default, except inside a plain map.
 */
function present(schema: Schema, full: Schema, values: Values, path: string, map: boolean, context: Context, stale: string[]): { schema: Schema; shown: Values } {
  const properties: Record<string, Schema> = {};
  const shown: Values = {};
  const replaced = new Map<Conditional, Conditional>();
  for (const [name, property] of Object.entries(schema.properties ?? {})) {
    const declared = full.properties?.[name] ?? property;
    const at = path + name;
    const extension = (property['x-surface'] ?? {}) as Extension;
    const held = values[name];
    if (isVariants(declared)) {
      const by = extension.by ?? '';
      const branches = variantsIn(schema.allOf ?? [], name);
      const chosen = branches.find((condition) => sameValue(condition.if.properties[by]?.const, values[by])) ?? null;
      const id = chosen === null ? null : variantId(extension, chosen.if.properties[by]?.const);
      properties[name] = { ...property, 'x-surface': { ...extension, chosen: id, refined: id !== null, stale: false } };
      shown[name] = held;
      for (const branch of branches) {
        const variant = narrowingOf(branch, name) as Schema;
        const deciding = branch.if.properties[by]?.const;
        const fullBranch = variantsIn(full.allOf ?? [], name).find((condition) => sameValue(condition.if.properties[by]?.const, deciding));
        const fullVariant = (fullBranch === undefined ? null : narrowingOf(fullBranch, name)) ?? variant;
        const isChosen = branch === chosen;
        const own = { ...frameDefaults(fullVariant), ...(isChosen && fits(fullVariant, held) ? asValues(held) : {}) };
        const answered = present(variant, fullVariant, own, `${at}.`, false, isChosen ? context : { ...context, standing: new Map() }, isChosen ? stale : []);
        // A variant's own frame carries no `x-surface` of a key: what is
        // said of the key is said on the key with variants.
        replaced.set(branch, { ...branch, then: { ...branch.then, properties: { [name]: answered.schema } } });
        if (isChosen) {
          shown[name] = answered.shown;
        }
      }
      continue;
    }
    if (isCollection(declared)) {
      const items = present(property.items as Schema, declared.items as Schema, {}, `${at}.*.`, false, { ...context, standing: new Map() }, []);
      properties[name] = { ...property, items: items.schema, 'x-surface': { ...extension, stale: false } };
      const list = held ?? property.default;
      shown[name] = Array.isArray(list)
        ? list.map((item, delta) => {
          const prefix = `${at}.${delta}.`;
          const own = { ...frameDefaults(declared.items as Schema), ...asValues(item) };
          try {
            const narrowed = core(declared.items as Schema, null, own, null, prefix, context);
            return present(narrowed, declared.items as Schema, own, prefix, false, context, stale).shown;
          }
          catch {
            // An item the page cannot read: what was last settled for it.
            stale.push(...context.previous.stale.filter((dotted) => dotted.startsWith(prefix)));
            return item;
          }
        })
        : null;
      continue;
    }
    if (property.properties !== undefined) {
      const part = isPart(declared);
      const inner = part ? { ...frameDefaults(declared), ...asValues(held) } : asValues(held);
      const answered = present(property, declared, inner, `${at}.`, !part, context, stale);
      properties[name] = {
        ...answered.schema,
        'x-surface': { ...extension, refined: part ? anyRefined(answered.schema) : extension.refined || anyRefined(answered.schema), stale: false },
      };
      shown[name] = answered.shown;
      continue;
    }
    const orphan = context.standing.has(at);
    const value = property.writeOnly
      ? null
      : extension.locked && property.const !== undefined
        ? property.const
        : orphan
          ? context.standing.get(at)
          : map ? norm(held) : held ?? property.default ?? null;
    const own: Extension = { ...extension, stale: false };
    delete own.emptyOption;
    let out: unknown = jsonType(property) === 'array' && Array.isArray(value) ? [...value] : value;
    if ((extension.widget === 'select' || extension.widget === 'radios') && !extension.multiple && jsonType(property) !== 'array' && property.oneOf !== undefined) {
      const list = offered(property) ?? [];
      const configured = !isEmpty(value);
      const chosen = configured && allows(list, value);
      // OptionsWidget::singleSelect() and RadiosWidget::buildElement():
      // radios show nothing checked where a select shows its empty option.
      const holds = holdsValue(property, (schema.required ?? []).includes(name));
      own.emptyOption = extension.widget === 'radios'
        ? { show: !holds, label: context.labels.optional }
        : { show: !(chosen && holds), label: holds ? context.labels.required : context.labels.optional };
      if (configured && !chosen) {
        own.stale = true;
        stale.push(at);
        out = null;
        if (extension.widget === 'select') {
          // A save that leaves it keeps the stored value, and it says so.
          own.emptyOption = { ...own.emptyOption, label: keepLabel(context.labels, at, value) };
        }
      }
    }
    if (context.unknown.has(at) && isEmpty(held) && !own.stale) {
      // Stale when the page was handed it: the server holds what it stands for.
      own.stale = true;
      if (own.emptyOption !== undefined) {
        own.emptyOption = { ...own.emptyOption, show: true, label: extension.widget === 'select' ? context.labels.kept.get(at) ?? own.emptyOption.label : own.emptyOption.label };
      }
      stale.push(at);
      out = null;
    }
    if (orphan) {
      if (!own.stale) {
        own.stale = true;
        stale.push(at);
      }
      if (own.emptyOption !== undefined && extension.widget === 'select') {
        // Refused by the save that moved what it refines against, not
        // kept: frame()'s orphan, worded as before.
        own.emptyOption = { ...own.emptyOption, label: holdsValue(property, (schema.required ?? []).includes(name)) ? context.labels.required : context.labels.optional };
      }
      out = null;
    }
    properties[name] = { ...property, 'x-surface': own };
    shown[name] = out;
  }
  const answered: Schema = { ...schema, properties };
  if (schema.allOf !== undefined) {
    answered.allOf = schema.allOf.map((condition) => replaced.get(condition) ?? condition);
  }
  return { schema: answered, shown };
}

/**
 * The variant a deciding value chooses: the key of the key's `variants`
 * map that names it, each a value of the deciding key.
 */
export function variantId(extension: Extension | undefined, deciding: unknown): string | null {
  if (deciding === null || deciding === undefined) {
    return null;
  }
  return Object.keys(extension?.variants ?? {}).find((id) => sameValue(id, deciding)) ?? String(deciding);
}

/** ContractEmitter::anyRefined(): a property of the frame says it is narrowed now. */
function anyRefined(schema: Schema): boolean {
  return Object.values(schema.properties ?? {}).some((property) => Boolean(property['x-surface']?.refined));
}

/** ServedOperations::keepStale(): an empty answer at a stale path stands for the stored value. */
function keepStale(values: Values, stored: Values, stale: string[]): Values {
  let kept = values;
  for (const path of stale) {
    const value = getAt(kept, path);
    const was = getAt(stored, path);
    if (value !== undefined && isEmpty(value) && !isEmpty(was)) {
      kept = setAt(kept, path, was);
    }
  }
  return kept;
}

/**
 * The first key that applies now, did not before, and was not handed over.
 *
 * Read through the frame and each part in it, where the values the page
 * was loaded with hold every key that applied then.
 */
function appearingUnseen(now: Schema, before: Schema, stored: Values, prefix: string, full: Schema): string | null {
  for (const [name, property] of Object.entries(now.properties ?? {})) {
    const was = before.properties?.[name];
    if (was === undefined) {
      if (!(name in stored)) {
        return prefix + name;
      }
      continue;
    }
    const declared = full.properties?.[name];
    if (declared !== undefined && isPart(declared) && !isCollection(declared) && isObject(stored[name])) {
      const deeper = appearingUnseen(property, was, stored[name] as Values, `${prefix}${name}.`, declared);
      if (deeper !== null) {
        return deeper;
      }
    }
  }
  return null;
}

/** Settles the whole form for its values, from the full schema and what it showed last. */
function resolveLocally(request: Narrowing, unknown: Set<string>): Settled {
  const context: Context = {
    standing: new Map(),
    unknown,
    labels: labels(request.previous.schema),
    previous: request.previous,
  };
  const input = keepStale(request.values, request.stored, request.stale);
  const values = settle(request.full, request.previous.schema, input, request.stored, request.previous.values, '', true, context);
  const narrowed = core(request.full, request.previous.schema, values, request.previous.values, '', context);
  // A key that did not apply when the page was loaded was not handed
  // over, so what it holds now that it applies is the server's to say.
  const appearing = appearingUnseen(narrowed, request.previous.schema, request.stored, '', request.full);
  if (appearing !== null) {
    throw new Unsettled(appearing, 'stored');
  }
  const stale: string[] = [];
  const answered = present(narrowed, request.full, values, '', false, context, stale);
  // Where the schema for these answers is served, not the full schema's.
  const schema: Schema = { ...answered.schema };
  delete schema.$id;
  if (request.previous.schema.$id !== undefined) {
    schema.$id = request.previous.schema.$id;
  }
  return { schema, values: answered.shown, stale };
}

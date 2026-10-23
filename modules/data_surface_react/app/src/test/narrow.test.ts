import { pointerToDotted } from '../api';
import type { Contract, Extension, Marker, Schema, Settled, Values } from '../contract';
import { holdsValue, holdsIf, inlineSchema, narrow, narrowingStatus, setAt } from '../contract';
import example2 from './fixtures/refine-example2.json';
import example3 from './fixtures/refine-example3.json';
import compliance from './fixtures/refine-example3-compliance.json';
import license from './fixtures/refine-example3-license.json';
import bulletin from './fixtures/refine-bulletin.json';
import journal from './fixtures/refine-journal.json';
import sales from './fixtures/refine-sales.json';

// The page's narrowing held to the server's. Each fixture is what
// ResourceApiController answered, as an administrator after the examples
// were installed: the full schema, the envelope, and a chain of changes,
// each posted to the schema with the values and the stale paths (in
// X-Surface-Stale, as JSON Pointers) the app would send after the one
// before. For every change the page narrows by itself, its schema
// (inline), values and stale paths must be the server's, keyword for
// keyword: nothing is ignored. A change the page asks about must be
// asked, naming the dependent and its marker.

interface Recorded {
  schema: Schema;
  contract: Contract;
  steps: { changes: { path: string; value: unknown }[]; sent: { stale: string[] }; answer: Contract }[];
}

type Expected = 'local' | { key: string; marker: Marker };

/** What the page holds of an envelope as recorded: its stale pointers read as dotted paths. */
function served(contract: Contract): Settled {
  return { schema: inlineSchema(contract.schema), values: contract.values, stale: contract.stale.map(pointerToDotted) };
}

/**
 * Plays a recorded chain through the page's resolver.
 *
 * A change narrowed here continues from what the page narrowed, so a
 * chain of local changes is held to the server too; one asked continues
 * from the server's answer, as the app does.
 */
function play(recorded: Recorded, expected: Expected[], fullSchema?: Schema): void {
  const full = fullSchema ?? inlineSchema(recorded.schema);
  const stored = recorded.contract.values;
  let previous = served(recorded.contract);
  recorded.steps.forEach((step, index) => {
    let values: Values = previous.values;
    let stale = previous.stale;
    for (const change of step.changes) {
      values = setAt(values, change.path, change.value);
      stale = stale.filter((path) => path !== change.path && !path.startsWith(`${change.path}.`));
    }
    // The page sends what the recorder sent.
    expect(stale, `step ${index + 1}: the stale paths sent`).toEqual(step.sent.stale.map(pointerToDotted));
    const changed = step.changes[step.changes.length - 1].path;
    const narrowed = narrow({ full, previous, stored, values, stale, changed });
    const want = expected[index];
    if (want === 'local') {
      expect(narrowed.local, `step ${index + 1} (${changed}): ${narrowingStatus(narrowed)}`).toBe(true);
      if (!narrowed.local) {
        return;
      }
      const answer = served(step.answer);
      expect(narrowed.settled.values, `step ${index + 1}: values`).toEqual(answer.values);
      expect(narrowed.settled.stale, `step ${index + 1}: stale`).toEqual(answer.stale);
      expect(narrowed.settled.schema, `step ${index + 1}: schema`).toEqual(answer.schema);
      previous = narrowed.settled;
    }
    else {
      expect(narrowed, `step ${index + 1} (${changed})`).toEqual({ local: false, ...want });
      previous = served(step.answer);
    }
  });
}

describe('narrowing here agrees with the server', () => {
  it('on example 2: every venue and room change is narrowed here, orphans and discards included', () => {
    play(example2 as unknown as Recorded, ['local', 'local', 'local', 'local', 'local', 'local', 'local']);
  });

  it('on example 3: the venue, the room and the ticket variants are narrowed here', () => {
    play(example3 as unknown as Recorded, ['local', 'local', 'local', 'local', 'local', 'local', 'local']);
  });

  it('on example 3 with compliance: the ticket variants here, the room and the stewards by the server', () => {
    // The capacity is stated exactly now, its license rule declared; the
    // stewards, inside the mount, follow the capacity, a number, and are
    // marked `refine`, so a change the capacity depends on is still asked.
    play(compliance as unknown as Recorded, [
      'local',
      { key: 'room', marker: 'live' },
      'local',
      { key: 'third_party_settings', marker: 'refine' },
      { key: 'third_party_settings', marker: 'refine' },
      'local',
    ]);
  });
});

describe('the empty option rule, narrowed here', () => {
  // Example 2's room asks for radios here: a required key, so no "- None -"
  // radio whether a valid room is chosen or not, where its select shows
  // "- Select -" while none is.
  const recorded = example2 as unknown as Recorded;
  const radios = (schema: Schema): Schema => {
    const walk = (node: unknown): unknown => {
      if (Array.isArray(node)) {
        return node.map(walk);
      }
      if (typeof node !== 'object' || node === null) {
        return node;
      }
      const copy: Record<string, unknown> = {};
      for (const [key, value] of Object.entries(node)) {
        copy[key] = walk(value);
      }
      const properties = copy.properties as Record<string, Schema> | undefined;
      const room = properties?.room?.['x-surface'];
      if (room !== undefined) {
        properties!.room = { ...properties!.room, 'x-surface': { ...room, widget: 'radios' } };
      }
      return copy;
    };
    return walk(schema) as Schema;
  };

  it('shows no empty radio for a key that holds a value, and a select its empty option while nothing valid is chosen', () => {
    const contract = served(recorded.contract);
    const values = { ...contract.values, venue: 'library' };
    const asSelect = narrow({ full: inlineSchema(recorded.schema), previous: contract, stored: contract.values, values, stale: [], changed: 'venue' });
    const asRadios = narrow({
      full: radios(inlineSchema(recorded.schema)),
      previous: { ...contract, schema: radios(contract.schema) },
      stored: contract.values,
      values,
      stale: [],
      changed: 'venue',
    });
    expect(asSelect.local && asRadios.local).toBe(true);
    if (!asSelect.local || !asRadios.local) {
      return;
    }
    expect(asSelect.settled.schema.properties?.room['x-surface']?.emptyOption).toEqual({ show: true, label: '- Select -' });
    expect(asRadios.settled.schema.properties?.room['x-surface']?.emptyOption).toEqual({ show: false, label: '- None -' });
  });

  it('says a select keeps a stored value no longer offered under a key nobody moved (an orphan, above, says - Select -)', () => {
    const contract = served(recorded.contract);
    // Stored, under a venue nobody moved, and no longer offered: kept by a
    // save that leaves it, and the empty option says so.
    const stored = { ...contract.values, room: 'library_garden' };
    const kept = narrow({ full: inlineSchema(recorded.schema), previous: { ...contract, values: stored }, stored, values: stored, stale: [], changed: 'venue' });
    expect(kept.local, narrowingStatus(kept)).toBe(true);
    if (!kept.local) {
      return;
    }
    expect(kept.settled.stale).toContain('room');
    expect(kept.settled.schema.properties?.room['x-surface']?.emptyOption).toEqual({ show: true, label: '- Keep library_garden (no longer offered) -' });
  });

  it('reads a key that holds a value off its required list or its type', () => {
    const choice: Schema = { type: 'string', oneOf: [{ const: 'a', title: 'A' }] };
    expect(holdsValue(choice, true)).toBe(true);
    expect(holdsValue(choice, false)).toBe(true);
    expect(holdsValue({ type: ['string', 'null'], oneOf: [{ const: 'a', title: 'A' }, { const: null }] }, false)).toBe(false);
    expect(holdsValue({ type: 'string', writeOnly: true }, false)).toBe(false);
  });
});

describe('a rule declared as data', () => {
  const recorded = license as unknown as Recorded;
  const full = inlineSchema(recorded.schema);
  const rule = (full.allOf ?? []).find((condition) => condition['x-surface']?.declared === true);

  it('is stated in the compliance full schema as written, and read by its own `if`', () => {
    expect(full.properties?.capacity['x-surface']).toMatchObject({ enumerated: true, declared: true });
    expect(full.properties?.capacity['x-surface']?.refine).toBeUndefined();
    // The declared type beside the bound, which never narrows.
    expect(rule?.then).toEqual({ properties: { capacity: { type: ['integer', 'null'], description: 'Up to 100 without an event license.', maximum: 100 } } });
    const at = (value: unknown): Values => ({ third_party_settings: { data_surface_examples_compliance: { license: value, stewards: 1 } } });
    expect(holdsIf(rule?.if, at(null))).toBe(true);
    expect(holdsIf(rule?.if, at(''))).toBe(true);
    expect(holdsIf(rule?.if, at('2048'))).toBe(false);
    expect(holdsIf(rule?.if, {})).toBe(true);
  });

  it('is narrowed here, keyword for keyword as the server answers, where nothing to refine with values depends on it', () => {
    // The stewards' rule set aside: what is left of a license or room
    // change is the capacity, its room conditionals and its declared rule.
    const mount = full.properties?.third_party_settings as Schema;
    const stated = { ...full, properties: { ...full.properties, third_party_settings: { ...mount, 'x-surface': { ...mount['x-surface'], refine: undefined } } } } as Schema;
    play(recorded, ['local', 'local', 'local'], stated);
  });

  it('reads each word of the vocabulary as JSON Schema does', () => {
    const number = { properties: { n: { type: 'number', minimum: 2, exclusiveMaximum: 5 } }, required: ['n'] };
    expect([1, 2, 4, 5, '3', null, undefined].map((n) => holdsIf(number, { n }))).toEqual([false, true, true, false, true, false, false]);
    expect(holdsIf({ properties: { k: { enum: ['a', 'b'] } }, required: ['k'] }, { k: 'b' })).toBe(true);
    expect(holdsIf({ not: { properties: { k: { const: 'a' } }, required: ['k'] } }, { k: 'a' })).toBe(false);
    expect(holdsIf({ anyOf: [{ properties: { k: { const: 'a' } }, required: ['k'] }, { properties: { j: { const: true } }, required: ['j'] }] }, { j: true })).toBe(true);
    expect(holdsIf({ allOf: [{ properties: { k: { not: { type: 'null' } } }, required: ['k'] }] }, { k: '' })).toBe(false);
    expect(() => holdsIf({ pattern: '^a' }, {})).toThrow();
  });
});

describe('a key that does not apply', () => {
  const recorded = bulletin as unknown as Recorded;
  const full = inlineSchema(recorded.schema);

  it('on the bulletin: the send day is dropped monthly and back weekly, required again, narrowed here', () => {
    play(recorded, ['local', 'local', 'local', 'local']);
  });

  it('is dropped from the form and from what is sent, and required where it applies', () => {
    const weekly = served(recorded.contract);
    const monthly = narrow({ full, previous: weekly, stored: weekly.values, values: { ...weekly.values, frequency: 'monthly' }, stale: [], changed: 'frequency' });
    expect(monthly.local).toBe(true);
    if (!monthly.local) {
      return;
    }
    expect(Object.keys(monthly.settled.schema.properties ?? {})).toEqual(['frequency']);
    expect(monthly.settled.schema.required).toEqual(['frequency']);
    expect(monthly.settled.values).toEqual({ frequency: 'monthly' });
    const again = narrow({ full, previous: monthly.settled, stored: weekly.values, values: { ...monthly.settled.values, frequency: 'weekly' }, stale: [], changed: 'frequency' });
    expect(again.local && again.settled.schema.required).toEqual(['frequency', 'send_day']);
    expect(again.local && again.settled.values).toEqual({ frequency: 'weekly', send_day: 'friday' });
  });

  it('asks the server what it holds when it did not apply as the page was loaded', () => {
    // Loaded monthly: the send day stored under it was never handed over.
    const monthly = served(recorded.steps[0].answer);
    expect(narrow({ full, previous: monthly, stored: monthly.values, values: { ...monthly.values, frequency: 'weekly' }, stale: [], changed: 'frequency' })).toEqual({ local: false, key: 'send_day', marker: 'stored' });
  });
});

describe('structures mixed in', () => {
  // The test module's journal mixes in the ownership, whose port reads the
  // journal's visibility, and the timestamps, flat; its archive is
  // attached, mixes the ownership in itself, and attaches the timestamps.
  const recorded = journal as unknown as Recorded;
  const full = inlineSchema(recorded.schema);

  it('reads a composed frame flat: every member in its place, the frame closed', () => {
    const raw = recorded.schema;
    expect(raw.unevaluatedProperties).toBe(false);
    expect((raw.allOf ?? []).slice(0, 2)).toEqual([{ $ref: '#/$defs/resource_test.ownership' }, { $ref: '#/$defs/resource_test.timestamps' }]);
    expect(Object.keys(full.properties ?? {})).toEqual(['title', 'visibility', 'owner', 'shared', 'created', 'changed', 'revision', 'published', 'archive']);
    expect(full.additionalProperties).toBe(false);
    expect(full.unevaluatedProperties).toBeUndefined();
    expect(full.required).toEqual(['title', 'visibility', 'owner']);
    expect(full.properties?.owner.title).toBe('Owner');
    expect(full.properties?.owner['x-surface']?.dependsOn).toEqual(['visibility']);
    expect(full.properties?.created.description).toBe('Seconds since the epoch.');
    expect((full.allOf ?? []).every((condition) => 'if' in condition)).toBe(true);
    const archive = full.properties?.archive;
    expect(Object.keys(archive?.properties ?? {})).toEqual(['shelf', 'circle', 'owner', 'shared', 'stamps']);
    expect(archive?.properties?.stamps.additionalProperties).toBe(false);
    expect(Object.keys(archive?.properties?.stamps.properties ?? {})).toEqual(['created', 'changed']);
  });

  it('narrows a member reading a port here, and asks about one a number moves', () => {
    play(recorded, ['local', 'local', { key: 'changed', marker: 'refine' }]);
  });

  it('narrows a member inside an attached child by the child\'s own key', () => {
    const staff = served(recorded.contract);
    const values = setAt(staff.values, 'archive.circle', 'public');
    const open = narrow({ full, previous: staff, stored: staff.values, values, stale: [], changed: 'archive.circle' });
    expect(open.local).toBe(true);
    if (!open.local) {
      return;
    }
    const archive = open.settled.schema.properties?.archive;
    expect(Object.keys(archive?.properties ?? {})).toEqual(['shelf', 'circle', 'owner', 'stamps']);
    expect(archive?.properties?.owner.maxLength).toBeUndefined();
    expect(archive?.['x-surface']?.doesNotApply).toEqual({ shared: ['circle'] });
  });

  it('drops a member that does not apply, from the form and from what is sent', () => {
    const staff = served(recorded.contract);
    const open = narrow({ full, previous: staff, stored: staff.values, values: { ...staff.values, visibility: 'public' }, stale: [], changed: 'visibility' });
    expect(open.local).toBe(true);
    if (!open.local) {
      return;
    }
    expect(Object.keys(open.settled.schema.properties ?? {})).not.toContain('shared');
    expect(open.settled.values).not.toHaveProperty('shared');
    expect(open.settled.schema.properties?.owner.maxLength).toBeUndefined();
    expect(staff.schema.properties?.owner.maxLength).toBe(32);
  });
});

describe('a key whose access only the server decides', () => {
  // The full schema is the account's: a key a live access answer may
  // lock or leave out is there, marked `x-surface.access: "live"`, and
  // the schema for these answers settles it. The journal's full schema with
  // its archive and its owner so marked stands for one such account.
  const recorded = journal as unknown as Recorded;
  const declared = inlineSchema(recorded.schema);
  const live = (schema: Schema): Schema => ({ ...schema, 'x-surface': { ...(schema['x-surface'] as Extension), access: 'live' } });
  const full: Schema = {
    ...declared,
    properties: { ...declared.properties, archive: live(declared.properties?.archive as Schema), owner: live(declared.properties?.owner as Schema) },
  };
  const staff = served(recorded.contract);

  it('stays out where the server left it out', () => {
    const { archive: _archive, ...kept } = staff.schema.properties ?? {};
    const { archive: _value, ...values } = staff.values;
    const previous = { ...staff, schema: { ...staff.schema, properties: kept }, values };
    const open = narrow({ full, previous, stored: values, values: { ...values, visibility: 'public' }, stale: [], changed: 'visibility' });
    expect(open.local).toBe(true);
    if (!open.local) {
      return;
    }
    expect(Object.keys(open.settled.schema.properties ?? {})).not.toContain('archive');
    expect(open.settled.schema.required ?? []).not.toContain('archive');
    expect(open.settled.values).not.toHaveProperty('archive');
  });

  it('stays as the server locked it, whatever it depends on', () => {
    const owner = { ...(staff.schema.properties?.owner as Schema), readOnly: true, const: 'ada' };
    const previous = { ...staff, schema: { ...staff.schema, properties: { ...staff.schema.properties, owner } } };
    const open = narrow({ full, previous, stored: staff.values, values: { ...staff.values, visibility: 'public' }, stale: [], changed: 'visibility' });
    expect(open.local).toBe(true);
    if (!open.local) {
      return;
    }
    // Unmarked, the owner would lose its staff maximum here; locked by the
    // server, it is what the server said.
    expect(open.settled.schema.properties?.owner).toEqual(owner);
  });

  it('is narrowed from the full schema where the server left it open', () => {
    const open = narrow({ full, previous: staff, stored: staff.values, values: { ...staff.values, visibility: 'public' }, stale: [], changed: 'visibility' });
    expect(open.local).toBe(true);
    if (!open.local) {
      return;
    }
    expect(open.settled.schema.properties?.owner.maxLength).toBeUndefined();
  });

  it('is asked of the server in a frame nothing has settled yet', () => {
    expect(narrowingStatus({ local: false, key: 'loan', marker: 'access' })).toBe('Asked the server: loan has access only the server decides');
  });
});

describe('what is asked rather than narrowed', () => {
  const recorded = example2 as unknown as Recorded;
  const full = inlineSchema(recorded.schema);

  it('a dependent standing for a stored value the page was handed as nothing', () => {
    // The site narrowed the stored room away before the page loaded: only
    // the server knows which room it stands for.
    const stored = { ...recorded.contract.values, room: null };
    const previous = { ...served(recorded.contract), values: stored, stale: ['room'] };
    expect(narrow({ full, previous, stored, values: { ...stored, venue: 'library' }, stale: ['room'], changed: 'venue' })).toEqual({ local: false, key: 'room', marker: 'stored' });
  });

  it('anything the resolver cannot read, rather than a wrong form', () => {
    const previous = served(recorded.contract);
    const values = { ...previous.values, venue: 'library' };
    expect(narrow({ full: {}, previous, stored: previous.values, values, stale: [], changed: 'venue' })).toEqual({ local: false, key: 'venue', marker: 'unsettled' });
    // A conditional narrowing nothing, one naming a key outside the frame,
    // a reference left unresolved.
    const noThen = { ...full, allOf: [{ if: { properties: { venue: { const: 'library' } } } }] } as unknown as Schema;
    expect(narrow({ full: noThen, previous, stored: previous.values, values, stale: [], changed: 'venue' })).toEqual({ local: false, key: 'venue', marker: 'unsettled' });
    const outside = { ...full, allOf: [...(full.allOf ?? []), { if: { properties: { 'contact.email': { const: 'a@b.c' } } }, then: { properties: { room: { maxLength: 3 } } } }] } as unknown as Schema;
    expect(narrow({ full: outside, previous, stored: previous.values, values, stale: [], changed: 'venue' })).toEqual({ local: false, key: 'room', marker: 'unsettled' });
    const referred = { ...full, properties: { ...full.properties, open: { $ref: 'https://example.com/open.json' } } } as unknown as Schema;
    expect(narrow({ full: referred, previous, stored: previous.values, values, stale: [], changed: 'venue' })).toEqual({ local: false, key: 'open', marker: 'unsettled' });
  });
});

describe('the status line', () => {
  it('names what was narrowed, or the key and marker that asked', () => {
    expect(narrowingStatus({ local: true, keys: ['room', 'capacity'], settled: { schema: {}, values: {}, stale: [] } })).toBe('Narrowed here: room, capacity');
    expect(narrowingStatus({ local: false, key: 'room', marker: 'live' })).toBe('Asked the server: room depends on a live rule');
    expect(narrowingStatus({ local: false, key: 'capacity', marker: 'refine' })).toBe('Asked the server: capacity depends on a rule to refine with values');
  });
});

describe('a port on an entry of a map of keys from the site', () => {
  // Example 9: each type's limits read whether the type is on sale. The
  // full schema states each type's rules on the resource's frame, an `if`
  // on `on_sale.<type>` and a `then` reaching into `limits.<type>`.
  const recorded = sales as unknown as Recorded;
  const full = inlineSchema(recorded.schema);

  it('is stated per type, reaching into the type\'s limits', () => {
    const student = (full.allOf ?? []).filter((condition) => JSON.stringify(condition.if).includes('"student"'));
    expect(student.map((condition) => condition.then)).toEqual([
      { properties: { limits: { type: 'object', properties: { student: { type: 'object', properties: { per_order: false } } } } } },
      { properties: { limits: { type: 'object', properties: { student: { type: 'object', properties: { waiting_list: false } } } } } },
    ]);
    expect(holdsIf(student[0].if, { on_sale: { student: false } })).toBe(true);
    expect(holdsIf(student[0].if, { on_sale: { student: true } })).toBe(false);
  });

  it('is narrowed here, keyword for keyword as /refine answers: off sale, on again with the stored limits, and after an edit off and on again', () => {
    // The edit moves nothing, so there is nothing to narrow; the type
    // taken off sale and put back holds what is stored, not the edit.
    play(recorded, ['local', 'local', { key: 'limits.standard.per_order', marker: 'unsettled' }, 'local', 'local']);
  });
});

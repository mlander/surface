import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createApi, dottedToPointer, pageOf, pointerToDotted, STAGE_HEADER, startedPaths, startingValues, type Endpoints, type Settings } from '../api';
import type { Contract, Schema } from '../contract';
import { ContractRefused, getAt } from '../contract';
import { fieldOf, outputText, SurfaceForm } from '../SurfaceForm';
import example2 from './fixtures/example2.json';
import library from './fixtures/example2-library.json';
import example3 from './fixtures/example3.json';
import narrowed2 from './fixtures/refine-example2.json';
import narrowed3 from './fixtures/refine-example3.json';
import compliance from './fixtures/refine-example3-compliance.json';
import bulletin from './fixtures/refine-bulletin.json';
import spotlight from './fixtures/deferred-spotlight.json';

// One integration test over a mocked server: the contracts are what the
// module's emitter wrote for examples 2 and 3 (ServedContractTest's
// subjects), so the app is exercised against the real shape. The mocked
// server answers as the API does: the envelope at the resource's path,
// the full schema and a narrowing at its schema's, a write with the
// method the page names, the ETag in a header, every path a JSON
// Pointer, and a refusal as problem details.

/** The endpoints the page writes for a resource's default read and update. */
function endpointsOf(resource: string): Endpoints {
  return { read: `/surface-api/${resource}`, schema: `/surface-api/${resource}/schema`, write: { method: 'PATCH', url: `/surface-api/${resource}` } };
}

const settings: Settings = {
  apiBase: '/surface-api',
  resource: 'registration.step2',
  operation: 'configure',
  parameters: {},
  endpoints: endpointsOf('registration.step2'),
  tokenUrl: '/session/token',
};

/** The settings of another resource's default update. */
function settingsOf(resource: string): Settings {
  return { ...settings, resource, endpoints: endpointsOf(resource) };
}

/** The mocked server's routes of a resource, by method and path. */
function routesOf(resource: string) {
  return {
    read: `GET /surface-api/${resource}`,
    schema: `GET /surface-api/${resource}/schema`,
    narrow: `POST /surface-api/${resource}/schema`,
    write: `PATCH /surface-api/${resource}`,
  };
}

const step2 = routesOf('registration.step2');
const step3 = routesOf('registration.step3');

interface Call {
  url: string;
  init?: RequestInit;
}

/** An answer of the API: JSON, of contract 5. */
function json(body: unknown, init: { status?: number; headers?: Record<string, string> } = {}): Response {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { 'Content-Type': 'application/json', 'Contract-Version': '5', ...init.headers },
  });
}

const REASONS: Record<number, string> = { 403: 'Forbidden', 412: 'Precondition Failed', 422: 'Unprocessable Content', 500: 'Internal Server Error' };

/** A problem details answer (RFC 9457), as ProblemDetails writes it. */
function problem(status: number, members: Record<string, unknown> = {}): Response {
  return new Response(JSON.stringify({ type: 'about:blank', title: REASONS[status], status, ...members }), {
    status,
    statusText: REASONS[status],
    headers: { 'Content-Type': 'application/problem+json', 'Contract-Version': '5' },
  });
}

/** A 422 refusal: the values as the result has them, `errors` the violations. */
function refusal(errors: { path: string; code: string; message: string; params?: Record<string, unknown> }[]): Response {
  return problem(422, {
    type: 'https://git.drupalcode.org/project/surface/-/blob/1.0.x/docs/served-contract.md#refused',
    title: 'The values were refused',
    detail: errors.length === 1 ? 'One value was refused; nothing was written.' : `${errors.length} values were refused; nothing was written.`,
    errors,
    notes: [],
    stale: [],
  });
}

/** The one result of a run that wrote nothing and refused nothing: only what is sent matters. */
const nothing = { ok: true, committed: false, changed: false, changes: [], values: {}, violations: [], notes: [], stale: [], outputs: {}, contract: null, created: null };

const headersOf = (call: Call): Record<string, string> => (call.init?.headers ?? {}) as Record<string, string>;
const bodyOf = (call: Call): Record<string, unknown> => JSON.parse(String(call.init?.body));
const methodOf = (call: Call): string => call.init?.method ?? 'GET';
const pathOf = (call: Call): string => call.url.split('?')[0];

/** What was asked of the server to narrow: each a POST to a schema. */
const narrows = (calls: Call[]) => calls.filter((call) => methodOf(call) === 'POST' && pathOf(call).endsWith('/schema'));
/** The writes: each a PATCH, or a POST to anything but a schema. */
const writes = (calls: Call[]) => calls.filter((call) => methodOf(call) === 'PATCH' || (methodOf(call) === 'POST' && !pathOf(call).endsWith('/schema')));

/** The server's narrowing: the library contract, over the title as sent. */
function refined(call: Call): Contract {
  const sent = bodyOf(call);
  return { ...(library as unknown as Contract), values: { ...library.values, title: sent.title } };
}

/** What the recorder wrote: the full schema, the envelope, and the server's narrowing of each change in a chain. */
interface Recorded {
  schema: Schema;
  contract: Contract;
  steps: { changes: { path: string; value: unknown }[]; answer: Contract }[];
}

/**
 * A server serving one recorded chain: the envelope, the full schema, and
 * for a narrowing the answer to the latest recorded change the values
 * sent carry.
 */
function recordedServer(recorded: Recorded, resource: string, more: Record<string, (call: Call) => unknown> = {}) {
  const routes = routesOf(resource);
  return server({
    ...more,
    [routes.read]: () => recorded.contract,
    [routes.schema]: () => recorded.schema,
    [routes.narrow]: (call) => {
      const sent = bodyOf(call);
      const step = [...recorded.steps].reverse().find(({ changes }) => {
        const last = changes[changes.length - 1];
        return JSON.stringify(getAt(sent, last.path)) === JSON.stringify(last.value);
      });
      return step?.answer ?? recorded.contract;
    },
  });
}

/** The contract panel's line saying how the last change was narrowed. */
function narrowingLine(): string | null {
  return document.querySelector('.dsr-contract__narrowing')?.textContent ?? null;
}

/**
 * A mocked server: each route, `METHOD /path`, answers a document (200,
 * contract 5) or a Response of its own.
 */
function server(routes: Record<string, (call: Call) => unknown>) {
  const calls: Call[] = [];
  const fetcher = vi.fn(async (url: string, init?: RequestInit) => {
    const call = { url, init };
    calls.push(call);
    if (url === '/session/token') {
      return new Response('the-token');
    }
    // By method and path: a server with no route for the full schema
    // answers it 404, and the form asks the server for every change, as it
    // did before it could narrow by itself.
    const answer = routes[`${methodOf(call)} ${pathOf(call)}`];
    if (answer === undefined) {
      return new Response('Not found', { status: 404, statusText: 'Not Found' });
    }
    const answered = answer(call);
    return answered instanceof Response ? answered : json(answered);
  });
  return { calls, fetcher };
}

describe('the React form of example 2', () => {
  it('re-narrows the room when the venue changes, and shows the orphaned room stale', async () => {
    const { calls, fetcher } = server({
      [step2.narrow]: refined,
      [step2.read]: () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);

    const room = await screen.findByLabelText(/Room/);
    expect(within(room).getAllByRole('option').map((option) => option.textContent)).toEqual(['Main hall', 'East room']);
    expect(room).toHaveDisplayValue('Main hall');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '400');

    // The title is not something anything depends on: no narrowing.
    await userEvent.type(screen.getByLabelText(/Event title/), '!');
    expect(narrows(calls)).toHaveLength(0);

    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']));

    const [narrowing] = narrows(calls);
    expect(narrowing.url).toBe('/surface-api/registration.step2/schema');
    expect(headersOf(narrowing)['X-CSRF-Token']).toBe('the-token');
    expect(headersOf(narrowing)['Content-Type']).toBe('application/json');
    expect(headersOf(narrowing)['Accept']).toBe('application/json');
    // The body is the values themselves; nothing is stale yet.
    const body = bodyOf(narrowing);
    expect(body.venue).toBe('library');
    expect(body.room).toBe('riverside_main');
    expect(body.title).toBe('Spring meetup!');
    expect(headersOf(narrowing)).not.toHaveProperty('X-Surface-Stale');

    // The room is on its empty option, standing for the stored value; the
    // title the person typed is kept.
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -');
    expect(screen.getByLabelText(/Room/)).not.toBeRequired();
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup!');
    // The capacity is no longer capped by the room the room stands for:
    // with no room, it has no upper bound.
    expect(screen.getByLabelText(/Capacity/)).not.toHaveAttribute('max');
    expect(screen.getByLabelText(/Capacity/)).toHaveValue(50);
  });

  it('sends the orphaned room back by its pointer in X-Surface-Stale, empty, on the next narrowing and on submit', async () => {
    // A library room chosen stands, as the server answers it: no longer
    // stale, nothing discarded.
    const answered = (call: Call): Contract => {
      const sent = bodyOf(call);
      const contract = refined(call);
      if (typeof sent.room !== 'string' || !sent.room.startsWith('library_')) {
        return contract;
      }
      const room = contract.schema.properties!.room;
      return {
        ...contract,
        schema: { ...contract.schema, properties: { ...contract.schema.properties, room: { ...room, 'x-surface': { ...room['x-surface']!, stale: undefined } } } },
        values: { ...contract.values, room: sent.room },
        stale: [],
        notes: [],
        discarded: [],
      };
    };
    const { calls, fetcher } = server({
      [step2.narrow]: answered,
      [step2.write]: () => refusal([{ path: '/room', code: 'enum', message: 'The value you selected is not a valid choice.', params: { value: 'harbour_deck', allowed: ['riverside_main', 'riverside_east'] } }]),
      [step2.read]: () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));

    // Another venue: the room is still the stored one's stand-in.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Harbour Centre');
    await waitFor(() => expect(narrows(calls)).toHaveLength(2));
    const second = narrows(calls)[1];
    expect(headersOf(second)['X-Surface-Stale']).toBe('/room');
    expect(bodyOf(second)).toHaveProperty('room', null);
    expect(bodyOf(second).venue).toBe('harbour');

    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await screen.findByRole('status');
    const [submitted] = writes(calls);
    expect(headersOf(submitted)['X-Surface-Stale']).toBe('/room');
    expect(bodyOf(submitted)).toHaveProperty('room', null);

    // Choosing a room is an answer: it no longer stands for the stored one.
    await userEvent.selectOptions(screen.getByLabelText(/Room/), 'Garden room');
    await waitFor(() => expect(narrows(calls)).toHaveLength(3));
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('Garden room'));
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(2));
    const chosen = writes(calls)[1];
    expect(headersOf(chosen)).not.toHaveProperty('X-Surface-Stale');
    expect(bodyOf(chosen).room).toBe('library_garden');
  });

  it('a refused submit shows what was refused inline and in a summary', async () => {
    const { calls, fetcher } = server({
      [step2.narrow]: () => library,
      [step2.write]: () => refusal([{ path: '/room', code: 'enum', message: 'The value you selected is not a valid choice.', params: { value: 'harbour_deck', allowed: ['riverside_main', 'riverside_east'] } }]),
      [step2.read]: () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));

    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('One value was refused. Nothing was written.');
    expect(summary).toHaveTextContent('room: The value you selected is not a valid choice.');
    expect(screen.getByLabelText(/Room/)).toHaveAccessibleDescription('The value you selected is not a valid choice.');

    // The stale room went back as its pointer, never as its value.
    const [submit] = writes(calls);
    expect(headersOf(submit)['X-Surface-Stale']).toBe('/room');
    expect(bodyOf(submit).room).toBeNull();
  });

  it('shows the contract, collapsed', async () => {
    const { fetcher } = server({ [step2.read]: () => example2 });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    const summary = await screen.findByText('Contract');
    const panel = summary.closest('details');
    expect(panel).not.toHaveAttribute('open');
    expect(panel?.querySelector('tr[surface-key="room"]')).toHaveTextContent('narrowed');
    expect(panel?.querySelector('tr[surface-key="room"]')).toHaveTextContent('venue');
  });
});

describe('submitting example 2', () => {
  // Weak, as a compressing proxy passes it on: kept exactly as received.
  const loaded = () => json(example2, { headers: { ETag: 'W/"fp-loaded"' } });

  it('writes, says so with the notes and the stale warnings, and re-renders from the contract it answers with', async () => {
    const written = { ...(example2 as unknown as Contract), values: { ...example2.values, title: 'Spring meetup, as stored' } };
    const { calls, fetcher } = server({
      [step2.write]: () =>
        json(
          {
            ok: true,
            committed: true,
            changed: true,
            changes: ['/title'],
            values: {},
            violations: [],
            notes: [{ path: '/venue', code: 'locked', message: 'Venue is locked and kept as stored; the value sent was not taken.' }],
            stale: [{ path: '/room', code: 'stale', message: 'The stored room is no longer offered; it was kept.', stored: 'library_garden' }],
            outputs: {},
            contract: written,
            created: null,
          },
          { headers: { ETag: '"fp-written"' } },
        ),
      [step2.read]: loaded,
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.type(await screen.findByLabelText(/Event title/), '!');

    expect(screen.getByRole('button', { name: 'Submit' })).toBeEnabled();
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('Saved: the values were written.');
    expect(summary).toHaveTextContent('room: The stored room is no longer offered; it was kept.');
    expect(summary).toHaveTextContent('venue: Venue is locked and kept as stored; the value sent was not taken.');
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup, as stored');
    expect(navigate).not.toHaveBeenCalled();

    const [submitted] = writes(calls);
    expect(submitted.url).toBe('/surface-api/registration.step2');
    expect(methodOf(submitted)).toBe('PATCH');
    expect(headersOf(submitted)['Content-Type']).toBe('application/merge-patch+json');
    expect(headersOf(submitted)['X-CSRF-Token']).toBe('the-token');
    expect(headersOf(submitted)['If-Match']).toBe('W/"fp-loaded"');
    expect(headersOf(submitted)).not.toHaveProperty('X-Surface-Stale');
    expect(bodyOf(submitted).title).toBe('Spring meetup!');

    // The next submit is checked against what this one wrote: the ETag the
    // write answered with.
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(2));
    expect(headersOf(writes(calls)[1])['If-Match']).toBe('"fp-written"');
  });

  it('shows a 422 problem as refused values, inline and in a summary', async () => {
    const { fetcher } = server({
      [step2.write]: () => refusal([{ path: '/room', code: 'enum', message: 'The value you selected is not a valid choice.', params: { value: 'harbour_deck', allowed: ['riverside_main', 'riverside_east'] } }]),
      [step2.read]: loaded,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('One value was refused. Nothing was written.');
    expect(summary).toHaveTextContent('room: The value you selected is not a valid choice.');
    expect(screen.getByLabelText(/Room/)).toHaveAccessibleDescription('The value you selected is not a valid choice.');
    // A refusal, not a failure: no error above the form.
    expect(document.querySelector('.dsr-summary[role="alert"]')).toBeNull();
    // The answers stay as they were sent.
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup');
  });

  it('shows a 412 problem as the conflict, after someone else saved, the message without a path', async () => {
    const message = 'The stored values changed since this form was loaded. Reload it to see them, then make your changes again.';
    const { calls, fetcher } = server({
      [step2.write]: () =>
        problem(412, {
          type: 'https://git.drupalcode.org/project/surface/-/blob/1.0.x/docs/served-contract.md#conflict',
          title: 'The stored values changed',
          detail: message,
          errors: [{ path: '', code: 'conflict', message }],
          notes: [],
          stale: [],
        }),
      [step2.read]: loaded,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent(`One value was refused. Nothing was written.${message}`);
    expect(summary.querySelector('li code')).toBeNull();
    expect(headersOf(writes(calls)[0])['If-Match']).toBe('W/"fp-loaded"');
  });

  it('sends no If-Match when the page turns the fingerprint off', async () => {
    const { calls, fetcher } = server({
      [step2.write]: () => refusal([{ path: '/room', code: 'enum', message: 'No.' }]),
      [step2.read]: loaded,
    });
    render(<SurfaceForm settings={{ ...settings, sendFingerprint: false }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    await screen.findByRole('status');
    expect(headersOf(writes(calls)[0])).not.toHaveProperty('If-Match');
  });

  it('shows a failure that is no refusal as the form\'s error', async () => {
    const { fetcher } = server({
      [step2.write]: () => problem(500, { detail: 'The database went away.' }),
      [step2.read]: loaded,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The server answered 500 Internal Server Error: The database went away.');
    expect(screen.queryByRole('status')).toBeNull();
  });
});

describe('the field a violation is about', () => {
  it('is its path, or the property a dialect code at the object names', () => {
    expect(fieldOf({ path: 'room', code: 'enum', message: 'No.', params: { value: 'x', allowed: [] } })).toBe('room');
    expect(fieldOf({ path: 'contact', code: 'required', message: 'Email is required.', params: { property: 'email' } })).toBe('contact.email');
    expect(fieldOf({ path: '', code: 'unknownProperty', message: 'Unknown key fax.', params: { property: 'fax' } })).toBe('fax');
    expect(fieldOf({ path: 'deadline.amount', code: 'check.drupal:ReviewDeadline', message: 'Too late.', params: { amount: '45' } })).toBe('deadline.amount');
    expect(fieldOf({ path: 'deadline', code: 'maximum', message: 'Too big.', params: { limit: 3 } })).toBe('deadline');
  });
});

describe('a resource its access locks whole', () => {
  it('draws every field disabled and offers no Submit', async () => {
    // What the emitter writes for it: readOnly at the top and on every key.
    const contract = example2 as unknown as Contract;
    const properties = Object.fromEntries(
      Object.entries(contract.schema.properties ?? {}).map(([name, property]) => [
        name,
        { ...property, readOnly: true },
      ]),
    );
    const locked = { ...contract, schema: { ...contract.schema, readOnly: true, properties } } as Contract;
    const { fetcher } = server({ [step2.read]: () => locked });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);

    expect(await screen.findByLabelText(/Event title/)).toBeDisabled();
    expect(screen.getByLabelText(/Venue/)).toBeDisabled();
    expect(screen.getByText('This may be seen and not changed.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit' })).toBeNull();
  });
});

describe('submitting an operation that creates', () => {
  const add: Settings = {
    ...settings,
    resource: 'node.type',
    operation: 'create',
    pageBase: '/base/surface-react',
    endpoints: { read: '/surface-api/node.type', schema: '/surface-api/node.type/schema', write: { method: 'POST', url: '/surface-api/node.type' } },
  };

  it('moves to the page where the created thing now lives, on a 201', async () => {
    const { calls, fetcher } = server({
      'POST /surface-api/node.type': () =>
        json(
          {
            ok: true,
            committed: true,
            changed: true,
            changes: ['/type', '/name'],
            values: {},
            violations: [],
            notes: [],
            stale: [],
            outputs: {},
            contract: example2,
            created: { resource: 'node.type', operation: 'update', parameters: { type: 'recipe' } },
          },
          { status: 201, headers: { Location: 'http://localhost/surface-api/node.type/recipe' } },
        ),
      // An operation that creates reads no stored values: no ETag.
      'GET /surface-api/node.type': () => example2,
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={add} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/base/surface-react/node.type/update?type=recipe'));
    expect(screen.getByRole('status')).toHaveTextContent('Saved: the values were written.');

    const [created] = writes(calls);
    expect(methodOf(created)).toBe('POST');
    expect(created.url).toBe('/surface-api/node.type');
    expect(headersOf(created)['Content-Type']).toBe('application/json');
    expect(headersOf(created)).not.toHaveProperty('If-Match');
  });

  it('finds the pages beside the API when the page names no page base', () => {
    expect(pageOf({ ...settings, apiBase: '/sub/surface-api' }, { resource: 'node.type', operation: 'update', parameters: { type: 'a b' } })).toBe(
      '/sub/surface-react/node.type/update?type=a+b',
    );
  });
});

describe('submitting an operation that only reads', () => {
  it('says the values were accepted and nothing was written, and stays on the page', async () => {
    const check: Settings = {
      ...settings,
      operation: 'check',
      endpoints: {
        read: '/surface-api/registration.step2:check',
        schema: '/surface-api/registration.step2:check/schema',
        write: { method: 'POST', url: '/surface-api/registration.step2:check' },
      },
    };
    const { calls, fetcher } = server({
      'GET /surface-api/registration.step2:check': () => example2,
      // A read: accepted, nothing committed, no contract.
      'POST /surface-api/registration.step2:check': () => ({ ...nothing, values: example2.values }),
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={check} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('Valid: these values would be accepted. Nothing was written.');
    expect(summary).toHaveClass('dsr-summary--valid');
    expect(navigate).not.toHaveBeenCalled();
    expect(writes(calls)[0].url).toBe('/surface-api/registration.step2:check');
  });
});

describe('the browser fetch', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('fetches the contract and the full schema once, together, however often the form renders', async () => {
    const recorded = narrowed2 as unknown as Recorded;
    const { calls, fetcher } = server({
      [step2.read]: () => recorded.contract,
      [step2.schema]: () => recorded.schema,
    });
    vi.stubGlobal('fetch', fetcher);
    render(<SurfaceForm settings={settings} />);
    await userEvent.type(await screen.findByLabelText(/Event title/), ' again');
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup again');
    // A change narrowed here asks for nothing more.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Old Library');
    expect(calls.map((call) => call.url)).toEqual(['/surface-api/registration.step2', '/surface-api/registration.step2/schema']);
  });
});

describe('the React form of example 3', () => {
  const settings3 = settingsOf('registration.step3');

  it('renders the chosen ticket and the contact part', async () => {
    const { fetcher } = server({ [step3.read]: () => example3 });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByRole('group', { name: 'Ticket' })).toBeInTheDocument();
    expect(screen.getByLabelText('Note')).toHaveValue('');
    expect(within(screen.getByRole('group', { name: 'Contact' })).getByLabelText(/Email/)).toHaveValue('events@example.com');
  });

  it('draws the paid ticket from its def when the pricing changes', async () => {
    const { fetcher } = server({
      // The server's narrowing: the same contract, the paid ticket from its defaults.
      [step3.narrow]: () => ({
        ...(example3 as unknown as Contract),
        values: { ...example3.values, pricing: 'paid', ticket: { price: null, currency: 'EUR' } },
      }),
      [step3.read]: () => example3,
    });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Pricing/), 'Paid');
    // Drawn at once from the variant the pricing chooses; the narrowing
    // then brings the variant's defaults.
    await waitFor(() => expect(within(screen.getByRole('group', { name: 'Ticket' })).getByLabelText(/Currency/)).toHaveDisplayValue('EUR'));
    const ticket = screen.getByRole('group', { name: 'Ticket' });
    expect(within(ticket).getByLabelText(/Price/)).toBeRequired();
    expect(within(within(ticket).getByLabelText(/Currency/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['EUR', 'GBP', 'USD']);
    expect(within(ticket).queryByLabelText('Note')).toBeNull();
  });

  it('shows a refusal inside the contact on its email field, its pointer read as a dotted path', async () => {
    const { calls, fetcher } = server({
      [step3.write]: () => refusal([{ path: '/contact/email', code: 'format.email', message: 'This value is not a valid email address.' }]),
      [step3.read]: () => example3,
    });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    const email = within(await screen.findByRole('group', { name: 'Contact' })).getByLabelText(/Email/);
    await userEvent.clear(email);
    await userEvent.type(email, 'nobody');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('status')).toHaveTextContent('contact.email: This value is not a valid email address.');
    expect(email).toHaveAccessibleDescription('This value is not a valid email address.');
    expect(bodyOf(writes(calls)[0]).contact).toEqual({ email: 'nobody', phone: '' });
  });

  it('says in the contract table where a live rule narrows a key', async () => {
    const { fetcher } = server({ [step3.read]: () => example3 });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    const panel = (await screen.findByText('Contract')).closest('details');
    // Example 4 takes away the rooms a site has closed: a live refiner.
    // Narrowed by the venue, and checked by the live rule as well: which
    // of the two narrowed it is not said.
    expect(panel?.querySelector('tr[surface-key="room"]')).toHaveTextContent('narrowed, and checked by a live rule');
    expect(panel?.querySelector('tr[surface-key="capacity"]')).not.toHaveTextContent('live rule');
  });

  it('draws example 4\'s keys in one fieldset, titled by its alter', async () => {
    const { fetcher } = server({ [step3.read]: () => example3 });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    const compliance = await screen.findByRole('group', { name: 'Compliance' });
    expect(within(compliance).getByLabelText(/Event license/)).toHaveValue('');
    expect(within(compliance).getByLabelText(/Stewards/)).toHaveAccessibleDescription('At least 1 steward for 50 attendees.');
    expect(screen.queryByRole('group', { name: /third.party/i })).toBeNull();
    expect(screen.getByLabelText(/Capacity/)).toHaveAccessibleDescription('Up to 100 without an event license.');
  });

  it('draws the Compliance fieldset right after the capacity its license lifts', async () => {
    const { fetcher } = server({ [step3.read]: () => example3 });
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    const compliance = await screen.findByRole('group', { name: 'Compliance' });
    const legends = Array.from(document.querySelectorAll('form fieldset > legend'), (legend) => legend.textContent);
    expect(legends).toEqual(['Compliance', 'Ticket', 'Contact']);
    // Between the capacity and the pricing, and nothing else between.
    expect(compliance.previousElementSibling).toContainElement(screen.getByLabelText(/Capacity/));
    expect(compliance.nextElementSibling).toContainElement(screen.getByLabelText(/Pricing/));
    // The group it was stored in draws nothing of its own.
    expect(document.querySelector('.dsr-group')).toBeNull();
  });
});

describe('narrowing here, from the full schema', () => {
  const settings3 = settingsOf('registration.step3');

  it('narrows the room and the capacity of example 2 on a venue change, with no request', async () => {
    const { calls, fetcher } = recordedServer(narrowed2 as unknown as Recorded, 'registration.step2');
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');

    const room = screen.getByLabelText(/Room/);
    expect(within(room).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']);
    expect(screen.getByLabelText(/Capacity/)).not.toHaveAttribute('max');
    expect(narrowingLine()).toBe('Narrowed here: room, capacity.');

    await userEvent.selectOptions(screen.getByLabelText(/Room/), 'Garden room');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '30');
    expect(screen.getByLabelText(/Capacity/)).toHaveAccessibleDescription('Up to 30 for the Garden room.');
    expect(narrowingLine()).toBe('Narrowed here: capacity.');
    expect(narrows(calls)).toHaveLength(0);
  });

  it('shows the room the venue orphaned stale, on its empty option, and sends it back by its pointer', async () => {
    const { calls, fetcher } = recordedServer(narrowed2 as unknown as Recorded, 'registration.step2', {
      // Only what is sent matters here.
      [step2.write]: () => nothing,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');

    // On the empty option, standing for the stored main hall; not required
    // to a browser, since empty there means keep.
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -');
    expect(screen.getByLabelText(/Room/)).not.toBeRequired();
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(1));
    expect(headersOf(writes(calls)[0])['X-Surface-Stale']).toBe('/room');
    expect(bodyOf(writes(calls)[0]).room).toBeNull();

    // The venue moved back: the stored room is offered again, and stands.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Riverside Hall');
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('Main hall');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '400');
    expect(narrows(calls)).toHaveLength(0);
  });

  it('narrows example 3\'s ticket here on a pricing change: the variants', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Pricing/), 'Paid');
    const ticket = screen.getByRole('group', { name: 'Ticket' });
    expect(within(ticket).getByLabelText(/Price/)).toBeRequired();
    expect(within(ticket).getByLabelText(/Currency/)).toHaveDisplayValue('EUR');
    expect(within(ticket).queryByLabelText('Note')).toBeNull();
    expect(narrowingLine()).toBe('Narrowed here: ticket.');
    expect(narrows(calls)).toHaveLength(0);
  });

  it('asks the server on a venue change of example 3 with compliance: the room is narrowed by a live rule', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    expect(narrowingLine()).toBe('Asked the server: room depends on a live rule.');
    await waitFor(() => expect(narrows(calls)).toHaveLength(1));
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));
    expect(bodyOf(narrows(calls)[0]).venue).toBe('library');

    // The pricing after it still chooses the ticket variant, narrowed here.
    await userEvent.selectOptions(screen.getByLabelText(/Pricing/), 'Paid');
    expect(narrowingLine()).toBe('Narrowed here: ticket.');
    expect(narrows(calls)).toHaveLength(1);
  });

  it('asks the server on a license change of example 3 with compliance: the stewards are refined with values', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    await userEvent.type(await screen.findByLabelText(/Event license/), '2');
    expect(narrowingLine()).toBe('Asked the server: third_party_settings depends on a rule to refine with values.');
    await waitFor(() => expect(narrows(calls)).toHaveLength(1));
  });

  it('drops a key that does not apply, sends nothing for it, and requires it again where it applies', async () => {
    const bulletinRoutes = routesOf('resource_test.bulletin');
    const { calls, fetcher } = recordedServer(bulletin as unknown as Recorded, 'resource_test.bulletin', {
      [bulletinRoutes.write]: () => nothing,
    });
    render(<SurfaceForm settings={settingsOf('resource_test.bulletin')} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByLabelText(/Send day/)).toBeRequired();
    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Monthly');
    expect(screen.queryByLabelText(/Send day/)).not.toBeInTheDocument();
    expect(narrowingLine()).toBe('Narrowed here: send_day.');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(1));
    expect(bodyOf(writes(calls)[0])).toEqual({ frequency: 'monthly' });

    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Weekly');
    expect(screen.getByLabelText(/Send day/)).toHaveDisplayValue('Friday');
    expect(screen.getByLabelText(/Send day/)).toBeRequired();
    expect(narrows(calls)).toHaveLength(0);
  });

  it('narrows example 3 without compliance here, venue and room alike', async () => {
    const { calls, fetcher } = recordedServer(narrowed3 as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={settings3} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Harbour Centre');
    expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Auditorium', 'Upper deck']);
    expect(narrowingLine()).toBe('Narrowed here: room, capacity.');
    expect(narrows(calls)).toHaveLength(0);
  });
});

describe('the contract 5 wire', () => {
  const check: Settings = {
    ...settings,
    resource: 'event.program',
    operation: 'check',
    endpoints: {
      read: '/surface-api/event.program:check',
      schema: '/surface-api/event.program:check/schema',
      write: { method: 'POST', url: '/surface-api/event.program:check?day=2' },
    },
  };

  it('reads JSON Pointers as dotted paths, and dotted paths as JSON Pointers', () => {
    expect(pointerToDotted('')).toBe('');
    expect(pointerToDotted('/room')).toBe('room');
    expect(pointerToDotted('/ticket/price')).toBe('ticket.price');
    expect(pointerToDotted('/shelves/1/unit')).toBe('shelves.1.unit');
    expect(pointerToDotted('/a~1b/c~0d/~01')).toBe('a/b.c~d.~1');
    expect(dottedToPointer('')).toBe('');
    expect(dottedToPointer('ticket.price')).toBe('/ticket/price');
    expect(dottedToPointer('a/b.c~d.~1')).toBe('/a~1b/c~0d/~01');
    for (const path of ['room', 'shelves.1.unit', 'a/b.c~d.~1']) {
      expect(pointerToDotted(dottedToPointer(path))).toBe(path);
    }
  });

  it('captures the ETag as received and sends it back as If-Match, and takes the written one', async () => {
    const { calls, fetcher } = server({
      [step2.read]: () => json(example2, { headers: { ETag: 'W/"stored-1"' } }),
      [step2.write]: () => json({ ...nothing, committed: true, changed: true, contract: example2 }, { headers: { ETag: '"stored-2"' } }),
    });
    const api = createApi(settings, fetcher);
    const loaded = await api.contract();
    expect(loaded.etag).toBe('W/"stored-1"');
    expect(loaded.document).not.toHaveProperty('fingerprint');
    const written = await api.submit(loaded.document.values, [], loaded.etag);
    expect(headersOf(writes(calls)[0])['If-Match']).toBe('W/"stored-1"');
    expect(written.etag).toBe('"stored-2"');
    // No ETag, no If-Match.
    await api.submit({}, [], null);
    expect(headersOf(writes(calls)[1])).not.toHaveProperty('If-Match');
  });

  it('validates with dryRun=true added to the write URL\'s query string, by the write\'s method', async () => {
    const dry = { ...nothing, changes: ['/title', '/ticket/price'], 'x-surface-dry-run': true };
    const patch = server({ [step2.write]: () => dry });
    const validated = await createApi(settings, patch.fetcher).validate({ title: 'Meetup' }, [], '"stored-1"');
    const [call] = writes(patch.calls);
    expect(call.url).toBe('/surface-api/registration.step2?dryRun=true');
    expect(methodOf(call)).toBe('PATCH');
    expect(headersOf(call)['Content-Type']).toBe('application/merge-patch+json');
    expect(headersOf(call)['If-Match']).toBe('"stored-1"');
    expect(bodyOf(call)).toEqual({ title: 'Meetup' });
    expect(validated).toMatchObject({ ok: true, committed: false, changes: ['title', 'ticket.price'], 'x-surface-dry-run': true });

    // A custom operation's URL carries a query of its own already.
    const post = server({ 'POST /surface-api/event.program:check': () => dry });
    await createApi(check, post.fetcher).validate({}, [], null);
    const [checked] = writes(post.calls);
    expect(checked.url).toBe('/surface-api/event.program:check?day=2&dryRun=true');
    expect(methodOf(checked)).toBe('POST');
    expect(headersOf(checked)['Content-Type']).toBe('application/json');
    expect(headersOf(checked)).not.toHaveProperty('If-Match');

    // And a submit adds nothing to it.
    await createApi(check, post.fetcher).submit({}, [], null);
    expect(writes(post.calls)[1].url).toBe('/surface-api/event.program:check?day=2');
  });

  it('sends the stale paths as JSON Pointers in X-Surface-Stale, and none when there are none', async () => {
    const { calls, fetcher } = server({ [step2.narrow]: () => example2, [step2.write]: () => nothing });
    const api = createApi(settings, fetcher);
    await api.narrow({ room: null }, ['room', 'ticket.price', 'a/b~c']);
    expect(headersOf(narrows(calls)[0])['X-Surface-Stale']).toBe('/room,/ticket/price,/a~1b~0c');
    expect(bodyOf(narrows(calls)[0])).toEqual({ room: null });
    await api.narrow({}, []);
    expect(headersOf(narrows(calls)[1])).not.toHaveProperty('X-Surface-Stale');
    await api.submit({ room: null }, ['room'], null);
    expect(headersOf(writes(calls)[0])['X-Surface-Stale']).toBe('/room');
  });

  it('reads every path an answer carries as a dotted path', async () => {
    const narrowed = {
      ...(library as unknown as Contract),
      stale: ['/room', '/ticket/price'],
      notes: [{ path: '/ticket/price', code: 'stale', message: 'Kept.' }],
      discarded: ['/a~1b'],
    };
    const result = {
      ...nothing,
      committed: true,
      changes: ['/ticket/price'],
      notes: [{ path: '/venue', code: 'locked', message: 'Locked.' }],
      stale: [{ path: '/shelves/1/unit', code: 'stale', message: 'Kept.', stored: 'kg' }],
      contract: { ...(example2 as unknown as Contract), stale: ['/room'], notes: [{ path: '/room', code: 'stale', message: 'Kept.' }] },
    };
    const { fetcher } = server({ [step2.narrow]: () => narrowed, [step2.read]: () => narrowed, [step2.write]: () => result });
    const api = createApi(settings, fetcher);
    for (const contract of [await api.narrow({}, []), (await api.contract()).document]) {
      expect(contract.stale).toEqual(['room', 'ticket.price']);
      expect(contract.notes).toEqual([{ path: 'ticket.price', code: 'stale', message: 'Kept.' }]);
      expect(contract.discarded).toEqual(['a/b']);
    }
    const { document } = await api.submit({}, [], null);
    expect(document.changes).toEqual(['ticket.price']);
    expect(document.notes).toEqual([{ path: 'venue', code: 'locked', message: 'Locked.' }]);
    expect(document.stale).toEqual([{ path: 'shelves.1.unit', code: 'stale', message: 'Kept.', stored: 'kg' }]);
    expect(document.contract?.stale).toEqual(['room']);
    expect(document.contract?.notes).toEqual([{ path: 'room', code: 'stale', message: 'Kept.' }]);
  });

  it('reads a 422, a 412 and a 403 that names what was refused as a refusal, and throws for a 403 that names nothing', async () => {
    const errors = [{ path: '/contact/email', code: 'format.email', message: 'Not an email address.' }];
    const answers = [
      problem(422, { errors, notes: [{ path: '/venue', code: 'locked', message: 'Locked.' }], stale: [] }),
      problem(412, { errors: [{ path: '', code: 'conflict', message: 'Changed.' }], notes: [], stale: [] }),
      problem(403, { detail: 'No.', errors: [{ path: '', code: 'access', message: 'No.' }], notes: [], stale: [] }),
    ];
    const { fetcher } = server({ [step2.write]: () => answers.shift() });
    const api = createApi(settings, fetcher);
    await expect(api.submit({}, [], null)).resolves.toEqual({
      document: {
        ok: false,
        committed: false,
        changed: false,
        changes: [],
        values: {},
        violations: [{ path: 'contact.email', code: 'format.email', message: 'Not an email address.' }],
        notes: [{ path: 'venue', code: 'locked', message: 'Locked.' }],
        stale: [],
        outputs: {},
        contract: null,
        created: null,
      },
      etag: null,
    });
    await expect(api.submit({}, [], '"old"')).resolves.toMatchObject({ document: { ok: false, violations: [{ path: '', code: 'conflict', message: 'Changed.' }] } });
    await expect(api.validate({}, [], null)).resolves.toMatchObject({ ok: false, violations: [{ path: '', code: 'access', message: 'No.' }] });

    const forbidden = server({ [step2.write]: () => problem(403, { detail: 'The session token is missing.' }) });
    await expect(createApi(settings, forbidden.fetcher).submit({}, [], null)).rejects.toThrow('The server answered 403 Forbidden: The session token is missing.');
  });
});

describe('contract 5 only', () => {
  /** example 2's contract as a server of contract 1 wrote it: no version stated. */
  const contractOne = (): Contract => {
    const contract = JSON.parse(JSON.stringify(example2)) as Contract;
    delete contract.schema['x-surface']?.contract;
    return contract;
  };
  const refusalOf = (version: string) => `This page reads contract 5; the server sent contract ${version}.`;

  it('refuses a contract of contract 1, which states no version, as the form\'s error', async () => {
    const { fetcher } = server({ [step2.read]: () => contractOne() });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByRole('alert')).toHaveTextContent(`The form could not be loaded: ${refusalOf('1')}`);
    expect(screen.queryByLabelText(/Venue/)).toBeNull();
  });

  it('refuses a full schema of contract 1 as it would the contract', async () => {
    const recorded = narrowed2 as unknown as Recorded;
    const old = JSON.parse(JSON.stringify(recorded.schema));
    delete old['x-surface'].contract;
    const { fetcher } = server({
      [step2.read]: () => recorded.contract,
      [step2.schema]: () => old,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByRole('alert')).toHaveTextContent(`The form could not be loaded: ${refusalOf('1')}`);
  });

  it('refuses an answer whose Contract-Version header says 3, whatever its document says', async () => {
    const answer = (version: string | null) => vi.fn(async () =>
      new Response(JSON.stringify(example2), { status: 200, headers: version === null ? {} : { 'Content-Type': 'application/json', 'Contract-Version': version } }));
    await expect(createApi(settings, answer('3')).contract()).rejects.toThrow(refusalOf('3'));
    await expect(createApi(settings, answer('5')).contract()).resolves.toMatchObject({ document: { resource: 'registration.step2', operation: 'configure' } });
    // No header is no refusal by itself: the document is read.
    await expect(createApi(settings, answer(null)).contract()).resolves.toMatchObject({ document: { resource: 'registration.step2' } });
  });

  it('refuses a problem whose Contract-Version header says 3', async () => {
    const { fetcher } = server({
      [step2.write]: () => new Response(JSON.stringify({ status: 410, errors: [] }), { status: 410, headers: { 'Content-Type': 'application/problem+json', 'Contract-Version': '3' } }),
    });
    await expect(createApi(settings, fetcher).submit({}, [], null)).rejects.toThrow(new ContractRefused('3'));
  });

  it('shows a narrowing of contract 1 refused, and keeps the form as it was', async () => {
    const { fetcher } = server({
      [step2.narrow]: () => contractOne(),
      [step2.read]: () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    expect(await screen.findByRole('alert')).toHaveTextContent(refusalOf('1'));
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('Main hall');
  });

  it('refuses a write whose contract is of contract 1, and takes a write of contract 5', async () => {
    const written = (contract: Contract) => ({ ...nothing, committed: true, changed: true, changes: ['/title'], contract });
    const one = server({ [step2.write]: () => written(contractOne()) });
    await expect(createApi(settings, one.fetcher).submit({}, [], null)).rejects.toThrow(refusalOf('1'));
    const five = server({ [step2.write]: () => written(example2 as unknown as Contract) });
    await expect(createApi(settings, five.fetcher).submit({}, [], null)).resolves.toMatchObject({ document: { committed: true, changes: ['title'] } });
  });
});

describe('the lookup', () => {
  it("is the key's lookup link, concrete, expanded with its variables alone", async () => {
    const { calls, fetcher } = server({
      'GET /surface-api/node.page/7:lookup': () => ({ items: [{ id: 3, label: 'Ada' }] }),
    });
    // A page parameter named as a variable fills nothing: the server wrote
    // the link concrete, and the variables are what its `source` names.
    const api = createApi({ ...settings, parameters: { id: 7, limit: 2 } }, fetcher);
    await expect(api.lookup('/surface-api/node.page/7:lookup?key=/uid/target_id{&q,limit}', { q: 'a d' })).resolves.toEqual([{ id: 3, label: 'Ada' }]);
    await api.lookup('/surface-api/node.page/7:lookup?key=/thing/id&type={type}{&q,limit}', { type: 'node', q: 'x', limit: 5 });
    expect(calls.map((call) => call.url)).toEqual([
      '/surface-api/node.page/7:lookup?key=/uid/target_id&q=a%20d',
      '/surface-api/node.page/7:lookup?key=/thing/id&type=node&q=x&limit=5',
    ]);
  });
});

describe('a key deferred for its size', () => {
  // Example 12 on a site with more components than the site list limit:
  // the full schema states the props and slots open, `deferred`, each with
  // a `choose` link; the mocked server answers the full schema with the
  // choices named in the query, as GET .../schema?choose= does, and 404
  // for any other set.
  const resource = 'event.spotlight';
  const routes = routesOf(resource);
  const chosen = spotlight.chosen as Record<string, unknown>;
  const spotlightServer = () =>
    server({
      [routes.read]: () => spotlight.envelope,
      [routes.schema]: (call) => {
        const query = call.url.split('?')[1];
        if (query === undefined) {
          return spotlight.full;
        }
        return chosen[query] ?? problem(403);
      },
    });
  const fetches = (calls: Call[]) => calls.filter((call) => methodOf(call) === 'GET' && call.url.includes('?choose='));

  it('fetches the chosen variant with choose and narrows from it, asking no narrowing', async () => {
    const { calls, fetcher } = spotlightServer();
    render(<SurfaceForm settings={settingsOf(resource)} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByLabelText(/Heading/)).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText(/^Component/), 'Hero');
    expect(await screen.findByLabelText(/Subtitle/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Heading/)).not.toBeInTheDocument();
    // The props, then the slots the props' answer still left out, joined:
    // each a GET of the full schema, never a POST of the values.
    expect(fetches(calls).map((call) => call.url)).toEqual([
      '/surface-api/event.spotlight/schema?choose=/props=surface_examples%3Ahero',
      '/surface-api/event.spotlight/schema?choose=/props=surface_examples%3Ahero&choose=/slots=surface_examples%3Ahero',
    ]);
    expect(narrows(calls)).toHaveLength(0);
    expect(narrowingLine()).toBe('Narrowed here, from the schema fetched for the choice: props, slots.');
  });

  it('keeps each fetch for the page: a choice made again is not fetched again', async () => {
    const { calls, fetcher } = spotlightServer();
    render(<SurfaceForm settings={settingsOf(resource)} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/^Component/), 'Hero');
    await screen.findByLabelText(/Subtitle/);
    await userEvent.selectOptions(screen.getByLabelText(/^Component/), 'Card');
    expect(await screen.findByLabelText(/Heading/)).toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText(/^Component/), 'Hero');
    expect(await screen.findByLabelText(/Subtitle/)).toBeInTheDocument();
    expect(fetches(calls)).toHaveLength(4);
    expect(narrows(calls)).toHaveLength(0);
  });

  it('asks the narrowing when the fetch is refused', async () => {
    const { calls, fetcher } = server({
      [routes.read]: () => spotlight.envelope,
      [routes.schema]: (call) => (call.url.includes('?') ? problem(403) : spotlight.full),
      [routes.narrow]: () => spotlight.envelope,
    });
    render(<SurfaceForm settings={settingsOf(resource)} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/^Component/), 'Hero');
    await waitFor(() => expect(narrows(calls)).toHaveLength(1));
    expect(fetches(calls)).toHaveLength(1);
  });
});

describe('the values a page served at a module\'s route starts from', () => {
  // As the envelope holds them: a key of the resource and a key of a part
  // it attaches.
  const envelope = { title: 'Stored title', copies: 1, envelope: { color: 'white', stamps: 1 } };

  it('lays the envelope\'s values, then the route\'s defaults, then a link\'s prefill, each over the last', () => {
    expect(
      startingValues(envelope, {
        seeds: { defaults: { title: 'Dear you', copies: 4 } },
        prefill: { title: 'Pricing' },
      }),
    ).toEqual({ title: 'Pricing', copies: 4, envelope: { color: 'white', stamps: 1 } });
  });

  it('lays each by its key path, inside a part the envelope already fills', () => {
    expect(
      startingValues(envelope, {
        seeds: { defaults: { 'greeting.value': 'Start here.' } },
        prefill: { 'envelope.stamps': 3 },
      }),
    ).toEqual({ title: 'Stored title', copies: 1, envelope: { color: 'white', stamps: 3 }, greeting: { value: 'Start here.' } });
    // The envelope's values are left as they were.
    expect(envelope.envelope.stamps).toBe(1);
  });

  it('starts from the envelope alone when the page says nothing, an empty list as PHP writes an empty map included', () => {
    expect(startingValues(envelope, {})).toEqual(envelope);
    const nothing = [] as unknown as Record<string, unknown>;
    expect(startingValues(envelope, { seeds: { defaults: nothing }, prefill: nothing })).toEqual(envelope);
  });

  it('names each path started from a value the envelope does not hold there', () => {
    expect(startedPaths(envelope, { seeds: { defaults: { copies: 1, title: 'Dear you' } }, prefill: { 'envelope.stamps': 3, title: 'Pricing' } })).toEqual(['title', 'envelope.stamps']);
  });
});

describe('a page served at a module\'s route', () => {
  it('draws the form from the values it starts, asks nothing for keys nothing depends on, and writes them', async () => {
    const { calls, fetcher } = server({ [step2.read]: () => example2, [step2.write]: () => nothing });
    const started: Settings = { ...settings, seeds: { defaults: { title: 'Route title', capacity: 60 } }, prefill: { title: 'Link title' } };
    render(<SurfaceForm settings={started} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByLabelText(/Event title/)).toHaveValue('Link title');
    expect(screen.getByLabelText(/Capacity/)).toHaveValue(60);
    expect(narrows(calls)).toHaveLength(0);
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(1));
    expect(bodyOf(writes(calls)[0])).toMatchObject({ title: 'Link title', capacity: 60 });
  });

  it('narrows what depends on a value it starts here, as for a change, with no request', async () => {
    const { calls, fetcher } = recordedServer(narrowed2 as unknown as Recorded, 'registration.step2');
    render(<SurfaceForm settings={{ ...settings, prefill: { venue: 'library' } }} fetcher={fetcher} refineDelay={0} />);
    await waitFor(() => expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']));
    expect(screen.getByLabelText(/Venue/)).toHaveDisplayValue('Old Library');
    expect(screen.getByLabelText(/Capacity/)).not.toHaveAttribute('max');
    expect(narrowingLine()).toBe('Narrowed here: room, capacity.');
    expect(narrows(calls)).toHaveLength(0);
  });

  it('asks the server to narrow for a value it starts that the page cannot narrow for', async () => {
    // No full schema: every narrowing is the server's.
    const { calls, fetcher } = server({ [step2.narrow]: refined, [step2.read]: () => example2 });
    render(<SurfaceForm settings={{ ...settings, prefill: { venue: 'library' } }} fetcher={fetcher} refineDelay={0} />);
    await waitFor(() => expect(narrows(calls)).toHaveLength(1));
    expect(bodyOf(narrows(calls)[0])).toMatchObject({ venue: 'library', room: 'riverside_main' });
    await waitFor(() => expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']));
  });

  it('takes a value it starts for the stored one a stale key stood for', async () => {
    const recorded = narrowed2 as unknown as Recorded;
    const stale: Recorded = { ...recorded, contract: { ...recorded.contract, values: { ...recorded.contract.values, room: null }, stale: ['/room'] } };
    const { calls, fetcher } = recordedServer(stale, 'registration.step2', { [step2.write]: () => nothing });
    render(<SurfaceForm settings={{ ...settings, prefill: { room: 'riverside_east' } }} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByLabelText(/Room/)).toHaveDisplayValue('East room');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(writes(calls)).toHaveLength(1));
    expect(headersOf(writes(calls)[0])).not.toHaveProperty('X-Surface-Stale');
    expect(bodyOf(writes(calls)[0]).room).toBe('riverside_east');
  });
});

describe('a page under a stage', () => {
  const STAGE = '9033e1a6-0000-4000-8000-000000000001';
  const staged: Settings = { ...settings, pageBase: '/surface-react', stage: STAGE, stageLabel: 'Spring edits' };

  /** A contract of one of the stage's own operations, which take no keys. */
  function stageContract(operation: 'changes' | 'apply', kind: 'read' | 'update'): Contract {
    return {
      ...(example2 as unknown as Contract),
      resource: 'surface.stage',
      operation,
      values: {},
      stale: [],
      notes: [],
      schema: {
        ...(example2.schema as unknown as Schema),
        title: operation,
        'x-surface': { ...example2.schema['x-surface'], operation: { ...example2.schema['x-surface'].operation, id: operation, kind } },
        properties: {},
        required: [],
      } as unknown as Schema,
    };
  }

  /** The settings of a page of the stage's own operations, which names no stage. */
  function stagePage(operation: 'changes' | 'apply', method: 'POST' | 'PATCH'): Settings {
    const path = `/surface-api/surface.stage/${STAGE}:${operation}`;
    return { ...settings, pageBase: '/surface-react', resource: 'surface.stage', operation, parameters: { id: STAGE }, endpoints: { read: path, schema: `${path}/schema`, write: { method, url: path } } };
  }

  it('names the stage on every request to the API, and not on the token fetch', async () => {
    const { calls, fetcher } = server({
      [step2.read]: () => json(example2, { headers: { ETag: '"staged-1"' } }),
      [step2.schema]: () => example2.schema,
      [step2.narrow]: () => example2,
      [step2.write]: () => json({ ...nothing, committed: true, changed: true, contract: example2 }, { headers: { ETag: '"staged-2"' } }),
      'GET /surface-api/registration.step2:lookup': () => ({ items: [] }),
    });
    const api = createApi(staged, fetcher);
    const loaded = await api.contract();
    await api.schema();
    await api.chosen('/surface-api/registration.step2/schema?choose=%2Fvenue');
    await api.narrow(loaded.document.values, []);
    await api.validate(loaded.document.values, [], loaded.etag);
    await api.submit(loaded.document.values, [], loaded.etag);
    await api.lookup('/surface-api/registration.step2:lookup?key=/venue{&q,limit}', { q: 'riv' });
    const token = calls.filter((call) => call.url === '/session/token');
    const others = calls.filter((call) => call.url !== '/session/token');
    expect(token).toHaveLength(1);
    expect(token[0].init?.headers).toBeUndefined();
    expect(others.map((call) => `${methodOf(call)} ${pathOf(call)}`)).toEqual([
      'GET /surface-api/registration.step2',
      'GET /surface-api/registration.step2/schema',
      'GET /surface-api/registration.step2/schema',
      'POST /surface-api/registration.step2/schema',
      'PATCH /surface-api/registration.step2',
      'PATCH /surface-api/registration.step2',
      'GET /surface-api/registration.step2:lookup',
    ]);
    for (const call of others) {
      expect(headersOf(call)[STAGE_HEADER]).toBe(STAGE);
    }
    // The staged view's ETag is sent back, as the envelope gave it.
    expect(headersOf(writes(calls)[1])['If-Match']).toBe('"staged-1"');

    // Without a stage, no request names one.
    const plain = server({ [step2.read]: () => example2 });
    await createApi(settings, plain.fetcher).contract();
    expect(headersOf(plain.calls[0])).not.toHaveProperty(STAGE_HEADER);
  });

  it('keeps the stage in the page a staged create moves to', () => {
    const created = { resource: 'entity.content:node.page', operation: 'update', parameters: { id: -1 } };
    expect(pageOf(staged, created)).toBe(`/surface-react/entity.content%3Anode.page/update?id=-1&stage=${STAGE}`);
    expect(pageOf(settings, created)).toBe('/surface-react/entity.content%3Anode.page/update?id=-1');
  });

  it('says a write was staged, and draws the stage bar with its two pages', async () => {
    const { fetcher } = server({
      [step2.read]: () => example2,
      [step2.write]: () => json({ ...nothing, committed: true, changed: true, changes: ['/title'], contract: example2 }),
    });
    render(<SurfaceForm settings={staged} fetcher={fetcher} refineDelay={0} />);
    const bar = await screen.findByRole('note');
    expect(bar).toHaveTextContent('Stage Spring edits. Writes here go to the stage.');
    expect(within(bar).getByRole('link', { name: 'List the staged writes' })).toHaveAttribute('href', `/surface-react/surface.stage/changes?id=${STAGE}`);
    expect(within(bar).getByRole('link', { name: 'Apply the stage' })).toHaveAttribute('href', `/surface-react/surface.stage/apply?id=${STAGE}`);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Staged: the values are kept in the stage until it is applied.');
  });

  it('names the stage by its id when it has no label', async () => {
    const { stageLabel: _label, ...unlabeled } = staged;
    const { fetcher } = server({ [step2.read]: () => example2 });
    render(<SurfaceForm settings={unlabeled} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByRole('note')).toHaveTextContent(`Stage ${STAGE}. Writes here go to the stage.`);
  });

  it('says a staged create names its thing by its local id, and moves to it in the stage', async () => {
    const add: Settings = {
      ...staged,
      resource: 'entity.content:node.page',
      operation: 'create',
      endpoints: { read: '/surface-api/entity.content:node.page', schema: '/surface-api/entity.content:node.page/schema', write: { method: 'POST', url: '/surface-api/entity.content:node.page' } },
    };
    const { fetcher } = server({
      'GET /surface-api/entity.content:node.page': () => example2,
      // A staged create: 200, no Location, no ETag, its local id.
      'POST /surface-api/entity.content:node.page': () => ({
        ...nothing,
        committed: true,
        changed: true,
        outputs: { id: -1 },
        contract: example2,
        created: { resource: 'entity.content:node.page', operation: 'update', parameters: { id: -1 } },
      }),
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={add} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith(`/surface-react/entity.content%3Anode.page/update?id=-1&stage=${STAGE}`));
    expect(screen.getByRole('status')).toHaveTextContent('Staged: nothing is created until the stage is applied; the stage names it by -1.');
  });

  it('lists the staged writes on the changes page as a table', async () => {
    const changes = stagePage('changes', 'POST');
    const items = [
      { position: 1, resource: 'entity.content:node.page', operation: 'update', kind: 'update', parameters: [{ name: 'id', value: '21' }], changed: ['/title', '/body'], staged: '2026-11-08T13:12:27+00:00' },
      { position: 2, resource: 'registration.step3', operation: 'configure', kind: 'update', parameters: [], changed: ['/pricing'], staged: '2026-11-08T13:13:00+00:00' },
    ];
    const { calls, fetcher } = server({
      [`GET ${changes.endpoints.read}`]: () => stageContract('changes', 'read'),
      // A read: accepted, nothing committed, the list in its outputs.
      [`POST ${changes.endpoints.write.url}`]: () => ({ ...nothing, outputs: { items, total: 2 } }),
    });
    render(<SurfaceForm settings={changes} fetcher={fetcher} refineDelay={0} />);
    expect(screen.queryByRole('note')).toBeNull();
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Listed.');
    const table = screen.getByRole('table', { name: 'items' });
    expect(within(table).getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['position', 'resource', 'operation', 'kind', 'parameters', 'changed', 'staged']);
    const rows = within(table).getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent));
    expect(rows).toEqual([
      ['1', 'entity.content:node.page', 'update', 'update', 'id: 21', '/title, /body', '2026-11-08T13:12:27+00:00'],
      ['2', 'registration.step3', 'configure', 'update', '', '/pricing', '2026-11-08T13:13:00+00:00'],
    ]);
    expect(screen.getByText('total').nextSibling).toHaveTextContent('2');
    expect(bodyOf(writes(calls)[0])).toEqual({});
    expect(headersOf(writes(calls)[0])).not.toHaveProperty(STAGE_HEADER);
  });

  it('applies on the apply page with the If-Match its envelope was served with, and lists what it applied', async () => {
    const apply = stagePage('apply', 'PATCH');
    const applied = [{ position: 1, resource: 'entity.content:node.page', operation: 'update', kind: 'update', parameters: [{ name: 'id', value: '21' }], changed: true, id: '21', staged: '2026-11-08T13:12:27+00:00' }];
    const { calls, fetcher } = server({
      [`GET ${apply.endpoints.read}`]: () => json(stageContract('apply', 'update'), { headers: { ETag: '"listed-1"' } }),
      [`PATCH ${apply.endpoints.write.url}`]: () => json({ ...nothing, committed: true, changed: true, changes: [''], outputs: { applied }, contract: stageContract('apply', 'update') }, { headers: { ETag: '"listed-2"' } }),
    });
    render(<SurfaceForm settings={apply} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('status')).toHaveTextContent('Saved: the values were written.');
    const [write] = writes(calls);
    expect(headersOf(write)['If-Match']).toBe('"listed-1"');
    expect(bodyOf(write)).toEqual({});
    const cells = within(screen.getByRole('table', { name: 'applied' })).getAllByRole('cell').map((cell) => cell.textContent);
    expect(cells).toEqual(['1', 'entity.content:node.page', 'update', 'update', 'id: 21', 'true', '21', '2026-11-08T13:12:27+00:00']);
  });

  it('writes an output value as text', () => {
    expect(outputText([{ name: 'id', value: '-1' }, { name: 'type', value: 'page' }])).toBe('id: -1, type: page');
    expect(outputText({ a: 1, b: [true, false] })).toBe('a: 1; b: true, false');
    expect(outputText(null)).toBe('');
  });
});

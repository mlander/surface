import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createApi, dottedToPointer, lookupOf, pageOf, pointerToDotted, type Endpoints, type Settings } from '../api';
import type { Contract, Schema } from '../contract';
import { ContractRefused, getAt } from '../contract';
import { SurfaceForm } from '../SurfaceForm';
import example2 from './fixtures/example2.json';
import library from './fixtures/example2-library.json';
import example3 from './fixtures/example3.json';
import narrowed2 from './fixtures/refine-example2.json';
import narrowed3 from './fixtures/refine-example3.json';
import compliance from './fixtures/refine-example3-compliance.json';
import bulletin from './fixtures/refine-bulletin.json';

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

/** An answer of the API: JSON, of contract 3. */
function json(body: unknown, init: { status?: number; headers?: Record<string, string> } = {}): Response {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { 'Content-Type': 'application/json', 'Contract-Version': '3', ...init.headers },
  });
}

const REASONS: Record<number, string> = { 403: 'Forbidden', 412: 'Precondition Failed', 422: 'Unprocessable Content', 500: 'Internal Server Error' };

/** A problem details answer (RFC 9457), as ProblemDetails writes it. */
function problem(status: number, members: Record<string, unknown> = {}): Response {
  return new Response(JSON.stringify({ type: 'about:blank', title: REASONS[status], status, ...members }), {
    status,
    statusText: REASONS[status],
    headers: { 'Content-Type': 'application/problem+json', 'Contract-Version': '3' },
  });
}

/** A 422 refusal: the values as the result has them, `errors` the violations. */
function refusal(errors: { path: string; code: string; message: string }[]): Response {
  return problem(422, {
    type: 'https://git.drupalcode.org/project/data_surface/-/blob/1.0.x/docs/served-contract.md#refused',
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
 * contract 3) or a Response of its own.
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
        schema: { ...contract.schema, properties: { ...contract.schema.properties, room: { ...room, 'x-surface': { ...room['x-surface']!, stale: false } } } },
        values: { ...contract.values, room: sent.room },
        stale: [],
        notes: [],
        discarded: [],
      };
    };
    const { calls, fetcher } = server({
      [step2.narrow]: answered,
      [step2.write]: () => refusal([{ path: '/room', code: 'no_such_choice', message: 'The value you selected is not a valid choice.' }]),
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
      [step2.write]: () => refusal([{ path: '/room', code: 'no_such_choice', message: 'The value you selected is not a valid choice.' }]),
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
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('narrowed');
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('venue');
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
      [step2.write]: () => refusal([{ path: '/room', code: 'no_such_choice', message: 'The value you selected is not a valid choice.' }]),
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
          type: 'https://git.drupalcode.org/project/data_surface/-/blob/1.0.x/docs/served-contract.md#conflict',
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
      [step2.write]: () => refusal([{ path: '/room', code: 'no_such_choice', message: 'No.' }]),
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

describe('a resource its access locks whole', () => {
  it('draws every field disabled and offers no Submit', async () => {
    // What the emitter writes for it: readOnly at the top and on every key.
    const contract = example2 as unknown as Contract;
    const properties = Object.fromEntries(
      Object.entries(contract.schema.properties ?? {}).map(([name, property]) => [
        name,
        { ...property, readOnly: true, 'x-surface': { ...property['x-surface'], locked: true } },
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
      [step3.write]: () => refusal([{ path: '/contact/email', code: 'invalid_format', message: 'This value is not a valid email address.' }]),
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
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('narrowed, and checked by a live rule');
    expect(panel?.querySelector('tr[data-surface-key="capacity"]')).not.toHaveTextContent('live rule');
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

describe('the contract 3 wire', () => {
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
    const errors = [{ path: '/contact/email', code: 'invalid_format', message: 'Not an email address.' }];
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
        violations: [{ path: 'contact.email', code: 'invalid_format', message: 'Not an email address.' }],
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

describe('contract 3 only', () => {
  /** example 2's contract as a server of contract 1 wrote it: no version stated. */
  const contractOne = (): Contract => {
    const contract = JSON.parse(JSON.stringify(example2)) as Contract;
    delete contract.schema['x-surface']?.contract;
    return contract;
  };
  const refusalOf = (version: string) => `This page reads contract 3; the server sent contract ${version}.`;

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

  it('refuses an answer whose Contract-Version header says 2, whatever its document says', async () => {
    const answer = (version: string | null) => vi.fn(async () =>
      new Response(JSON.stringify(example2), { status: 200, headers: version === null ? {} : { 'Content-Type': 'application/json', 'Contract-Version': version } }));
    await expect(createApi(settings, answer('2')).contract()).rejects.toThrow(refusalOf('2'));
    await expect(createApi(settings, answer('3')).contract()).resolves.toMatchObject({ document: { resource: 'registration.step2', operation: 'configure' } });
    // No header is no refusal by itself: the document is read.
    await expect(createApi(settings, answer(null)).contract()).resolves.toMatchObject({ document: { resource: 'registration.step2' } });
  });

  it('refuses a problem whose Contract-Version header says 2', async () => {
    const { fetcher } = server({
      [step2.write]: () => new Response(JSON.stringify({ status: 410, errors: [] }), { status: 410, headers: { 'Content-Type': 'application/problem+json', 'Contract-Version': '2' } }),
    });
    await expect(createApi(settings, fetcher).submit({}, [], null)).rejects.toThrow(new ContractRefused('2'));
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

  it('refuses a write whose contract is of contract 1, and takes a write of contract 3', async () => {
    const written = (contract: Contract) => ({ ...nothing, committed: true, changed: true, changes: ['/title'], contract });
    const one = server({ [step2.write]: () => written(contractOne()) });
    await expect(createApi(settings, one.fetcher).submit({}, [], null)).rejects.toThrow(refusalOf('1'));
    const three = server({ [step2.write]: () => written(example2 as unknown as Contract) });
    await expect(createApi(settings, three.fetcher).submit({}, [], null)).resolves.toMatchObject({ document: { committed: true, changes: ['title'] } });
  });
});

describe('the lookup', () => {
  it("is the page's, or the read path's custom read, an operation named in it taken off", () => {
    expect(lookupOf({ read: '/surface-api/event.sponsorship', schema: '', write: { method: 'PATCH', url: '' } })).toBe('/surface-api/event.sponsorship:lookup');
    expect(lookupOf({ read: '/surface-api/node.type/recipe?x=1', schema: '', write: { method: 'PATCH', url: '' } })).toBe('/surface-api/node.type/recipe:lookup');
    expect(lookupOf({ read: '/surface-api/event.venue:create', schema: '', write: { method: 'POST', url: '' }, lookup: null })).toBeNull();
  });
});

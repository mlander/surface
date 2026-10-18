import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { pageOf, type Settings } from '../api';
import type { Contract, Schema } from '../contract';
import { getAt } from '../contract';
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
// subjects), so the app is exercised against the real shape.

const settings: Settings = {
  apiBase: '/surface-api',
  surface: 'registration.step2',
  situation: 'configure',
  parameters: {},
  tokenUrl: '/session/token',
};

interface Call {
  url: string;
  init?: RequestInit;
}

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

/** The server's refine: the library contract, over the title as sent. */
function refined({ init }: Call): Contract {
  const sent = JSON.parse(String(init?.body));
  return { ...(library as unknown as Contract), values: { ...library.values, title: sent.values.title } };
}

/** What the recorder wrote: the full schema, the GET contract, and /refine's answers to a chain of changes. */
interface Recorded {
  schema: { schema: Schema };
  contract: Contract;
  steps: { changes: { path: string; value: unknown }[]; answer: Contract }[];
}

/**
 * A server serving one recorded chain: the contract, the full schema, and
 * for a refine the answer to the latest recorded change the values sent
 * carry.
 */
function recordedServer(recorded: Recorded, surface: string, more: Record<string, (call: Call) => unknown> = {}) {
  const base = `/surface-api/${surface}/configure`;
  return server({
    ...more,
    [base]: () => recorded.contract,
    [`${base}/schema`]: () => recorded.schema,
    [`${base}/refine`]: ({ init }) => {
      const sent = JSON.parse(String(init?.body));
      const step = [...recorded.steps].reverse().find(({ changes }) => {
        const last = changes[changes.length - 1];
        return JSON.stringify(getAt(sent.values, last.path)) === JSON.stringify(last.value);
      });
      return step?.answer ?? recorded.contract;
    },
  });
}

/** The contract panel's line saying how the last change was narrowed. */
function narrowingLine(): string | null {
  return document.querySelector('.dsr-contract__narrowing')?.textContent ?? null;
}

function server(routes: Record<string, (call: Call) => unknown>) {
  const calls: Call[] = [];
  const fetcher = vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    if (url === '/session/token') {
      return new Response('the-token');
    }
    // By path: a server with no route for the full schema answers it 404,
    // and the form asks /refine for every change, as it did before it
    // could narrow by itself.
    const route = Object.keys(routes).find((path) => url.split('?')[0] === path);
    if (route === undefined) {
      return new Response('Not found', { status: 404, statusText: 'Not Found' });
    }
    return json(routes[route]({ url, init }));
  });
  return { calls, fetcher };
}

describe('the React form of example 2', () => {
  it('re-narrows the room when the venue changes, and shows the orphaned room stale', async () => {
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/refine': refined,
      '/surface-api/registration.step2/configure': () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);

    const room = await screen.findByLabelText(/Room/);
    expect(within(room).getAllByRole('option').map((option) => option.textContent)).toEqual(['Main hall', 'East room']);
    expect(room).toHaveDisplayValue('Main hall');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '400');

    // The title is not something anything depends on: no refine.
    await userEvent.type(screen.getByLabelText(/Event title/), '!');
    expect(calls.some((call) => call.url.endsWith('/refine'))).toBe(false);

    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']));

    const refine = calls.find((call) => call.url.endsWith('/refine'));
    expect(refine?.init?.method).toBe('POST');
    expect((refine?.init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('the-token');
    const body = JSON.parse(String(refine?.init?.body));
    expect(body.values.venue).toBe('library');
    expect(body.values.room).toBe('riverside_main');
    expect(body.values.title).toBe('Spring meetup!');
    expect(body.stale).toEqual([]);

    // The room is on its empty option, standing for the stored value; the
    // title the person typed is kept.
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -');
    expect(screen.getByLabelText(/Room/)).not.toBeRequired();
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup!');
    // The capacity is no longer capped by the room the room stands for.
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '1000');
    expect(screen.getByLabelText(/Capacity/)).toHaveValue(50);
  });

  it('sends the orphaned room back by its path, empty, on the next refine and on submit', async () => {
    // A library room chosen stands, as the server answers it: no longer
    // stale, nothing discarded.
    const answered = (call: Call): Contract => {
      const sent = JSON.parse(String(call.init?.body));
      const contract = refined(call);
      if (typeof sent.values.room !== 'string' || !sent.values.room.startsWith('library_')) {
        return contract;
      }
      const room = contract.schema.properties!.room;
      return {
        ...contract,
        schema: { ...contract.schema, properties: { ...contract.schema.properties, room: { ...room, 'x-surface': { ...room['x-surface']!, stale: false } } } },
        values: { ...contract.values, room: sent.values.room },
        stale: [],
        discarded: [],
      };
    };
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/refine': answered,
      '/surface-api/registration.step2/configure/submit': () => ({
        committed: false,
        valid: false,
        violations: [{ path: 'room', message: 'The value you selected is not a valid choice.' }],
        stale: [],
        outputs: {},
        contract: null,
        created: null,
      }),
      '/surface-api/registration.step2/configure': () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));

    // Another venue: the room is still the stored one's stand-in.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Harbour Centre');
    await waitFor(() => expect(calls.filter((call) => call.url.endsWith('/refine'))).toHaveLength(2));
    const second = JSON.parse(String(calls.filter((call) => call.url.endsWith('/refine'))[1].init?.body));
    expect(second.stale).toEqual(['room']);
    expect(second.values).toHaveProperty('room', null);
    expect(second.values.venue).toBe('harbour');

    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await screen.findByRole('status');
    const submitted = JSON.parse(String(calls.find((call) => call.url.endsWith('/submit'))?.init?.body));
    expect(submitted.stale).toEqual(['room']);
    expect(submitted.values).toHaveProperty('room', null);

    // Choosing a room is an answer: it no longer stands for the stored one.
    await userEvent.selectOptions(screen.getByLabelText(/Room/), 'Garden room');
    await waitFor(() => expect(calls.filter((call) => call.url.endsWith('/refine'))).toHaveLength(3));
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('Garden room'));
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(calls.filter((call) => call.url.endsWith('/submit'))).toHaveLength(2));
    const chosen = JSON.parse(String(calls.filter((call) => call.url.endsWith('/submit'))[1].init?.body));
    expect(chosen.stale).toEqual([]);
    expect(chosen.values.room).toBe('library_garden');
  });

  it('a refused submit shows what was refused inline and in a summary', async () => {
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/refine': () => library,
      '/surface-api/registration.step2/configure/submit': () => ({
        committed: false,
        valid: false,
        violations: [{ path: 'room', message: 'The value you selected is not a valid choice.' }],
        stale: [],
        outputs: {},
        contract: null,
        created: null,
      }),
      '/surface-api/registration.step2/configure': () => example2,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));

    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('One value was refused. Nothing was written.');
    expect(summary).toHaveTextContent('room: The value you selected is not a valid choice.');
    expect(screen.getByLabelText(/Room/)).toHaveAccessibleDescription('The value you selected is not a valid choice.');

    // The stale room went back as its path, never as its value.
    const submit = calls.find((call) => call.url.endsWith('/submit'));
    const body = JSON.parse(String(submit?.init?.body));
    expect(body.stale).toEqual(['room']);
    expect(body.values.room).toBeNull();
  });

  it('shows the contract, collapsed', async () => {
    const { fetcher } = server({ '/surface-api/registration.step2/configure': () => example2 });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    const summary = await screen.findByText('Contract');
    const panel = summary.closest('details');
    expect(panel).not.toHaveAttribute('open');
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('narrowed');
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('venue');
  });
});

describe('submitting example 2', () => {
  const loaded = { ...(example2 as unknown as Contract), fingerprint: 'fp-loaded' };
  const refused = (violations: { path: string; message: string }[]) => ({
    committed: false,
    valid: false,
    violations,
    stale: [],
    outputs: {},
    contract: null,
    created: null,
  });

  it('writes, says so with the stale warnings, and re-renders from the contract it answers with', async () => {
    const written = { ...loaded, values: { ...loaded.values, title: 'Spring meetup, as stored' }, fingerprint: 'fp-written' };
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/submit': () => ({
        committed: true,
        valid: true,
        violations: [],
        stale: [{ path: 'room', message: 'The stored room is no longer offered; it was kept.' }],
        outputs: {},
        contract: written,
        created: null,
      }),
      '/surface-api/registration.step2/configure': () => loaded,
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.type(await screen.findByLabelText(/Event title/), '!');

    expect(screen.getByRole('button', { name: 'Submit' })).toBeEnabled();
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('Saved: the values were written.');
    expect(summary).toHaveTextContent('room: The stored room is no longer offered; it was kept.');
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup, as stored');
    expect(navigate).not.toHaveBeenCalled();

    const submitted = calls.filter((call) => call.url.endsWith('/submit'));
    expect(submitted[0].init?.method).toBe('POST');
    expect((submitted[0].init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('the-token');
    const body = JSON.parse(String(submitted[0].init?.body));
    expect(body.values.title).toBe('Spring meetup!');
    expect(body.stale).toEqual([]);
    expect(body.fingerprint).toBe('fp-loaded');

    // The next submit is checked against what this one wrote.
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(calls.filter((call) => call.url.endsWith('/submit'))).toHaveLength(2));
    expect(JSON.parse(String(calls.filter((call) => call.url.endsWith('/submit'))[1].init?.body)).fingerprint).toBe('fp-written');
  });

  it('shows a refusal inline and in a summary, as Validate does', async () => {
    const { fetcher } = server({
      '/surface-api/registration.step2/configure/submit': () =>
        refused([{ path: 'room', message: 'The value you selected is not a valid choice.' }]),
      '/surface-api/registration.step2/configure': () => loaded,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent('One value was refused. Nothing was written.');
    expect(summary).toHaveTextContent('room: The value you selected is not a valid choice.');
    expect(screen.getByLabelText(/Room/)).toHaveAccessibleDescription('The value you selected is not a valid choice.');
    // The answers stay as they were sent.
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup');
  });

  it('refuses a submit after someone else saved, the message without a path', async () => {
    const message = 'The stored values changed since this form was loaded. Reload it to see them, then make your changes again.';
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/submit': () => refused([{ path: '', message }]),
      '/surface-api/registration.step2/configure': () => loaded,
    });
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    const summary = await screen.findByRole('status');
    expect(summary).toHaveTextContent(`One value was refused. Nothing was written.${message}`);
    expect(summary.querySelector('li code')).toBeNull();
    expect(JSON.parse(String(calls.find((call) => call.url.endsWith('/submit'))?.init?.body)).fingerprint).toBe('fp-loaded');
  });

  it('sends no fingerprint when the page turns it off', async () => {
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure/submit': () => refused([{ path: 'room', message: 'No.' }]),
      '/surface-api/registration.step2/configure': () => loaded,
    });
    render(<SurfaceForm settings={{ ...settings, sendFingerprint: false }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    await screen.findByRole('status');
    expect(JSON.parse(String(calls.find((call) => call.url.endsWith('/submit'))?.init?.body))).not.toHaveProperty('fingerprint');
  });
});

describe('submitting an operation that creates', () => {
  it('moves to the page where the created thing now lives', async () => {
    const add: Settings = { ...settings, surface: 'node.type', situation: 'add', pageBase: '/base/surface-react' };
    const { fetcher } = server({
      '/surface-api/node.type/add/submit': () => ({
        committed: true,
        valid: true,
        violations: [],
        stale: [],
        outputs: {},
        contract: { ...(example2 as unknown as Contract), fingerprint: 'fp-after' },
        created: { surface: 'node.type', situation: 'edit', parameters: { type: 'recipe' } },
      }),
      '/surface-api/node.type/add': () => ({ ...(example2 as unknown as Contract), fingerprint: 'fp-before' }),
    });
    const navigate = vi.fn();
    render(<SurfaceForm settings={add} fetcher={fetcher} refineDelay={0} navigate={navigate} />);
    await userEvent.click(await screen.findByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/base/surface-react/node.type/edit?type=recipe'));
    expect(screen.getByRole('status')).toHaveTextContent('Saved: the values were written.');
  });

  it('finds the pages beside the API when the page names no page base', () => {
    expect(pageOf({ ...settings, apiBase: '/sub/surface-api' }, { surface: 'node.type', situation: 'edit', parameters: { type: 'a b' } })).toBe(
      '/sub/surface-react/node.type/edit?type=a+b',
    );
  });
});

describe('the browser fetch', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('fetches the contract and the full schema once, together, however often the form renders', async () => {
    const recorded = narrowed2 as unknown as Recorded;
    const { calls, fetcher } = server({
      '/surface-api/registration.step2/configure': () => recorded.contract,
      '/surface-api/registration.step2/configure/schema': () => recorded.schema,
    });
    vi.stubGlobal('fetch', fetcher);
    render(<SurfaceForm settings={settings} />);
    await userEvent.type(await screen.findByLabelText(/Event title/), ' again');
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup again');
    // A change narrowed here asks for nothing more.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Old Library');
    expect(calls.map((call) => call.url)).toEqual(['/surface-api/registration.step2/configure', '/surface-api/registration.step2/configure/schema']);
  });
});

describe('the React form of example 3', () => {
  it('renders the chosen ticket and the contact part', async () => {
    const { fetcher } = server({ '/surface-api/registration.step3/configure': () => example3 as unknown as Contract });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByRole('group', { name: 'Ticket' })).toBeInTheDocument();
    expect(screen.getByLabelText('Note')).toHaveValue('');
    expect(within(screen.getByRole('group', { name: 'Contact' })).getByLabelText(/Email/)).toHaveValue('events@example.com');
  });

  it('draws the paid ticket from its def when the pricing changes', async () => {
    const { fetcher } = server({
      // The server's refine: the same contract, the paid ticket from its defaults.
      '/surface-api/registration.step3/configure/refine': () => ({
        ...(example3 as unknown as Contract),
        values: { ...example3.values, pricing: 'paid', ticket: { price: null, currency: 'EUR' } },
      }),
      '/surface-api/registration.step3/configure': () => example3 as unknown as Contract,
    });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Pricing/), 'paid');
    // Drawn at once from the variant the pricing chooses; the refine then
    // brings the variant's defaults.
    await waitFor(() => expect(within(screen.getByRole('group', { name: 'Ticket' })).getByLabelText(/Currency/)).toHaveDisplayValue('EUR'));
    const ticket = screen.getByRole('group', { name: 'Ticket' });
    expect(within(ticket).getByLabelText(/Price/)).toBeRequired();
    expect(within(within(ticket).getByLabelText(/Currency/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['EUR', 'GBP', 'USD']);
    expect(within(ticket).queryByLabelText('Note')).toBeNull();
  });

  it('shows a refusal inside the contact on its email field', async () => {
    const { calls, fetcher } = server({
      '/surface-api/registration.step3/configure/submit': () => ({
        committed: false,
        valid: false,
        violations: [{ path: 'contact.email', message: 'This value is not a valid email address.' }],
        stale: [],
        outputs: {},
        contract: null,
        created: null,
      }),
      '/surface-api/registration.step3/configure': () => example3 as unknown as Contract,
    });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    const email = within(await screen.findByRole('group', { name: 'Contact' })).getByLabelText(/Email/);
    await userEvent.clear(email);
    await userEvent.type(email, 'nobody');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('status')).toHaveTextContent('contact.email: This value is not a valid email address.');
    expect(email).toHaveAccessibleDescription('This value is not a valid email address.');
    expect(JSON.parse(String(calls.find((call) => call.url.endsWith('/submit'))?.init?.body)).values.contact).toEqual({ email: 'nobody', phone: '' });
  });

  it('says in the contract table where a runtime rule narrows a key', async () => {
    const { fetcher } = server({ '/surface-api/registration.step3/configure': () => example3 as unknown as Contract });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    const panel = (await screen.findByText('Contract')).closest('details');
    // Example 4 takes away the rooms a site has closed: a runtime refiner.
    expect(panel?.querySelector('tr[data-surface-key="room"]')).toHaveTextContent('narrowed at runtime');
    expect(panel?.querySelector('tr[data-surface-key="capacity"]')).not.toHaveTextContent('runtime');
  });

  it('draws example 4\'s keys in one fieldset, titled by its alter', async () => {
    const { fetcher } = server({ '/surface-api/registration.step3/configure': () => example3 as unknown as Contract });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    const compliance = await screen.findByRole('group', { name: 'Compliance' });
    expect(within(compliance).getByLabelText(/Event licence/)).toHaveValue('');
    expect(within(compliance).getByLabelText(/Stewards/)).toHaveAccessibleDescription('At least 1 steward for 50 attendees.');
    expect(screen.queryByRole('group', { name: /third.party/i })).toBeNull();
    expect(screen.getByLabelText(/Capacity/)).toHaveAccessibleDescription('Up to 100 without an event licence. With one, up to 400.');
  });

  it('draws the Compliance fieldset right after the capacity its licence lifts', async () => {
    const { fetcher } = server({ '/surface-api/registration.step3/configure': () => example3 as unknown as Contract });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
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
  const refines = (calls: Call[]) => calls.filter((call) => call.url.endsWith('/refine'));

  it('narrows the room and the capacity of example 2 on a venue change, with no request', async () => {
    const { calls, fetcher } = recordedServer(narrowed2 as unknown as Recorded, 'registration.step2');
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');

    const room = screen.getByLabelText(/Room/);
    expect(within(room).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Reading room', 'Garden room']);
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '1000');
    expect(narrowingLine()).toBe('Narrowed here: room, capacity.');

    await userEvent.selectOptions(screen.getByLabelText(/Room/), 'Garden room');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '30');
    expect(screen.getByLabelText(/Capacity/)).toHaveAccessibleDescription('Up to 30 for the Garden room.');
    expect(narrowingLine()).toBe('Narrowed here: capacity.');
    expect(refines(calls)).toHaveLength(0);
  });

  it('shows the room the venue orphaned stale, on its empty option, and sends it back by its path', async () => {
    const { calls, fetcher } = recordedServer(narrowed2 as unknown as Recorded, 'registration.step2', {
      // Only what is sent matters here.
      '/surface-api/registration.step2/configure/submit': () => ({ committed: false, valid: false, violations: [], stale: [], outputs: {}, contract: null, created: null }),
    });
    const submits = () => calls.filter((call) => call.url.endsWith('/submit'));
    render(<SurfaceForm settings={settings} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');

    // On the empty option, standing for the stored main hall; not required
    // to a browser, since empty there means keep.
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -');
    expect(screen.getByLabelText(/Room/)).not.toBeRequired();
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(submits()).toHaveLength(1));
    const body = JSON.parse(String(submits()[0].init?.body));
    expect(body.stale).toEqual(['room']);
    expect(body.values.room).toBeNull();

    // The venue moved back: the stored room is offered again, and stands.
    await userEvent.selectOptions(screen.getByLabelText(/Venue/), 'Riverside Hall');
    expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('Main hall');
    expect(screen.getByLabelText(/Capacity/)).toHaveAttribute('max', '400');
    expect(refines(calls)).toHaveLength(0);
  });

  it('narrows example 3\'s ticket here on a pricing change: the slot', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Pricing/), 'paid');
    const ticket = screen.getByRole('group', { name: 'Ticket' });
    expect(within(ticket).getByLabelText(/Price/)).toBeRequired();
    expect(within(ticket).getByLabelText(/Currency/)).toHaveDisplayValue('EUR');
    expect(within(ticket).queryByLabelText('Note')).toBeNull();
    expect(narrowingLine()).toBe('Narrowed here: ticket.');
    expect(refines(calls)).toHaveLength(0);
  });

  it('asks the server on a venue change of example 3 with compliance: the room is narrowed at runtime', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Old Library');
    expect(narrowingLine()).toBe('Asked the server: room depends on a runtime rule.');
    await waitFor(() => expect(refines(calls)).toHaveLength(1));
    await waitFor(() => expect(screen.getByLabelText(/Room/)).toHaveDisplayValue('- Select -'));
    expect(JSON.parse(String(refines(calls)[0].init?.body)).values.venue).toBe('library');

    // The pricing after it is still the slot's, narrowed here.
    await userEvent.selectOptions(screen.getByLabelText(/Pricing/), 'paid');
    expect(narrowingLine()).toBe('Narrowed here: ticket.');
    expect(refines(calls)).toHaveLength(1);
  });

  it('asks the server on a licence change of example 3 with compliance: the capacity is dynamic', async () => {
    const { calls, fetcher } = recordedServer(compliance as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.type(await screen.findByLabelText(/Event licence/), '2');
    expect(narrowingLine()).toBe('Asked the server: capacity depends on a dynamic rule.');
    await waitFor(() => expect(refines(calls)).toHaveLength(1));
  });

  it('drops a key that does not apply, sends nothing for it, and requires it again where it applies', async () => {
    const { calls, fetcher } = recordedServer(bulletin as unknown as Recorded, 'resource_test.bulletin', {
      '/surface-api/resource_test.bulletin/configure/submit': () => ({ committed: false, valid: false, violations: [], stale: [], outputs: {}, contract: null, created: null }),
    });
    const submits = () => calls.filter((call) => call.url.endsWith('/submit'));
    render(<SurfaceForm settings={{ ...settings, surface: 'resource_test.bulletin' }} fetcher={fetcher} refineDelay={0} />);
    expect(await screen.findByLabelText(/Send day/)).toBeRequired();
    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Monthly');
    expect(screen.queryByLabelText(/Send day/)).not.toBeInTheDocument();
    expect(narrowingLine()).toBe('Narrowed here: send_day.');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(submits()).toHaveLength(1));
    expect(JSON.parse(String(submits()[0].init?.body)).values).toEqual({ frequency: 'monthly' });

    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Weekly');
    expect(screen.getByLabelText(/Send day/)).toHaveDisplayValue('Friday');
    expect(screen.getByLabelText(/Send day/)).toBeRequired();
    expect(refines(calls)).toHaveLength(0);
  });

  it('narrows example 3 without compliance here, venue and room alike', async () => {
    const { calls, fetcher } = recordedServer(narrowed3 as unknown as Recorded, 'registration.step3');
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    await userEvent.selectOptions(await screen.findByLabelText(/Venue/), 'Harbour Centre');
    expect(within(screen.getByLabelText(/Room/)).getAllByRole('option').map((option) => option.textContent)).toEqual(['- Select -', 'Auditorium', 'Upper deck']);
    expect(narrowingLine()).toBe('Narrowed here: room, capacity.');
    expect(refines(calls)).toHaveLength(0);
  });
});

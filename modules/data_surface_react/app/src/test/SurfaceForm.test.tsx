import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { pageOf, type Settings } from '../api';
import type { Contract } from '../contract';
import { SurfaceForm } from '../SurfaceForm';
import example2 from './fixtures/example2.json';
import library from './fixtures/example2-library.json';
import example3 from './fixtures/example3.json';

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

function server(routes: Record<string, (call: Call) => unknown>) {
  const calls: Call[] = [];
  const fetcher = vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    if (url === '/session/token') {
      return new Response('the-token');
    }
    const route = Object.keys(routes).find((path) => url.startsWith(path));
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

describe('submitting a situation that creates', () => {
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

  it('fetches the contract once, however often the form renders', async () => {
    const { calls, fetcher } = server({ '/surface-api/registration.step2/configure': () => example2 });
    vi.stubGlobal('fetch', fetcher);
    render(<SurfaceForm settings={settings} />);
    await userEvent.type(await screen.findByLabelText(/Event title/), ' again');
    expect(screen.getByLabelText(/Event title/)).toHaveValue('Spring meetup again');
    expect(calls.map((call) => call.url)).toEqual(['/surface-api/registration.step2/configure']);
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

  it('draws example 4\'s keys in one fieldset, titled by its alter', async () => {
    const { fetcher } = server({ '/surface-api/registration.step3/configure': () => example3 as unknown as Contract });
    render(<SurfaceForm settings={{ ...settings, surface: 'registration.step3' }} fetcher={fetcher} refineDelay={0} />);
    const compliance = await screen.findByRole('group', { name: 'Compliance' });
    expect(within(compliance).getByLabelText(/Event licence/)).toHaveValue('');
    expect(within(compliance).getByLabelText(/Stewards/)).toHaveAccessibleDescription('At least 1 steward for 50 attendees.');
    expect(screen.queryByRole('group', { name: /third.party/i })).toBeNull();
    expect(screen.getByLabelText(/Capacity/)).toHaveAccessibleDescription('Up to 100 without an event licence.');
  });
});

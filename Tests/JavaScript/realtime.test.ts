import assert from "node:assert/strict";
import test from "node:test";
import { RealtimeClient } from "../../Frontend/widget/realtime";
import type { ChatMessage, SessionData } from "../../Frontend/widget/types";
class FakeEventSource {
  static instances: FakeEventSource[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;
  constructor(readonly url: string) {
    FakeEventSource.instances.push(this);
  }
  close(): void {
    this.closed = true;
  }
}
const session: SessionData = {
  session: "a".repeat(32),
  session_token: "session-token",
  realtime: {
    token: "scoped-token",
    url: "https://example.test/chat/realtime",
    event_url: "https://example.test/chat/api/realtime/events",
    expires_at: "2030-01-01T00:00:00Z",
  },
  widget: {
    name: "Macro",
    greeting: "Olá",
    offline_message: "Offline",
    accent_color: "#4e5ba6",
    require_name: true,
    require_email: false,
  },
  messages: [],
};
const reply: ChatMessage = {
  id: 12,
  client_id: "agent_12",
  direction: "agent",
  body: "Resposta",
  status: "sent",
  timestamp: "2026-10-04T00:00:00Z",
};
const tick = () => new Promise((resolve) => setTimeout(resolve, 10));
test("SSE + authenticated serial HTTP: 1000 UI updates produce one receipt, typing is throttled", async () => {
  Object.assign(globalThis, {
    EventSource: FakeEventSource,
    window: globalThis,
  });
  const calls: Record<string, unknown>[] = [];
  let active = 0;
  let peak = 0;
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (url, init) => {
    assert.equal(url, session.realtime.event_url);
    assert.equal(
      (init?.headers as Record<string, string>).authorization,
      "Bearer scoped-token",
    );
    peak = Math.max(peak, ++active);
    await tick();
    --active;
    calls.push(JSON.parse(String(init?.body)));
    return new Response('{"ok":true}', {
      status: 200,
      headers: { "content-type": "application/json" },
    });
  };
  try {
    const statuses: string[] = [];
    const events: Record<string, unknown>[] = [];
    const client = new RealtimeClient(session, {
      status: (v) => statuses.push(v),
      event: (e) => events.push(e),
    });
    client.connect();
    const stream = FakeEventSource.instances.at(-1)!;
    assert.match(stream.url, /token=scoped-token$/);
    stream.onopen?.();
    for (let i = 0; i < 1000; i++) {
      client.receipt("read", reply);
      client.typing(true, "Raphael");
    }
    client.typing(false, "Raphael");
    client.receipt("delivered", reply);
    client.receipt("read", { ...reply, id: 11 });
    await new Promise((resolve) => setTimeout(resolve, 80));
    assert.equal(calls.filter((e) => e.type === "message.read").length, 1);
    assert.equal(calls.filter((e) => e.type === "typing.started").length, 1);
    assert.equal(calls.filter((e) => e.type === "typing.stopped").length, 1);
    assert.equal(calls.filter((e) => e.type === "message.delivered").length, 0);
    assert.equal(peak, 1);
    stream.onmessage?.({
      data: JSON.stringify({ type: "typing.started", role: "agent" }),
    });
    assert.equal(events[0].type, "typing.started");
    assert.deepEqual(statuses, ["connecting", "online"]);
    client.close();
    assert.equal(stream.closed, true);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
test("command acknowledgement confirms a message even when its SSE delivery is missed", async () => {
  Object.assign(globalThis, {
    EventSource: FakeEventSource,
    window: globalThis,
  });
  const originalFetch = globalThis.fetch;
  const events: Record<string, unknown>[] = [];
  let payload: Record<string, unknown> = {};
  globalThis.fetch = async (_url, init) => {
    payload = JSON.parse(String(init?.body));
    return new Response(
      JSON.stringify({ ok: true, message: { ...reply, direction: "visitor" } }),
    );
  };
  try {
    const client = new RealtimeClient(session, {
      event: (e) => events.push(e),
      status: () => {},
    });
    client.connect();
    FakeEventSource.instances.at(-1)!.onopen?.();
    assert.equal(
      client.sendMessage("Relatório", "msg_1234567890123456", {
        page_url: "https://site.example/market/nfl",
        page_title: "Bears vs Packers",
        locale: "en",
      }),
      true,
    );
    await tick();
    assert.equal(events[0].type, "message.created");
    assert.equal(payload.page_url, "https://site.example/market/nfl");
    assert.equal(payload.page_title, "Bears vs Packers");
    assert.equal(payload.locale, "en");
    client.close();
  } finally {
    globalThis.fetch = originalFetch;
  }
});

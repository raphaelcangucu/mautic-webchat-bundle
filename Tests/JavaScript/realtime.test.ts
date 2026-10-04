import assert from "node:assert/strict";
import test from "node:test";
import { RealtimeClient } from "../../Frontend/widget/realtime";
import type { SessionData } from "../../Frontend/widget/types";

class FakeWebSocket {
  static readonly OPEN = 1;
  static instances: FakeWebSocket[] = [];
  readyState = FakeWebSocket.OPEN;
  onopen: (() => void) | null = null;
  onmessage: ((event: { data: string }) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  sent: string[] = [];
  closed = false;

  constructor(readonly url: string) {
    FakeWebSocket.instances.push(this);
  }

  send(value: string): void {
    this.sent.push(value);
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
    url: "wss://example.test/chat/realtime",
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

test("connects with a scoped token and emits the realtime protocol", () => {
  FakeWebSocket.instances = [];
  Object.assign(globalThis, { WebSocket: FakeWebSocket, window: globalThis });
  const statuses: string[] = [];
  const events: Record<string, unknown>[] = [];
  const client = new RealtimeClient(session, {
    status: (value) => statuses.push(value),
    event: (event) => events.push(event),
  });

  client.connect();
  const socket = FakeWebSocket.instances[0];
  assert.match(socket.url, /token=scoped-token$/);
  socket.onopen?.();
  assert.deepEqual(statuses, ["connecting", "online"]);

  assert.equal(client.sendMessage("Relatório", "msg_1234567890123456"), true);
  assert.deepEqual(JSON.parse(socket.sent[0]), {
    type: "message.send",
    body: "Relatório",
    client_id: "msg_1234567890123456",
    request_id: "msg_1234567890123456",
  });
  client.typing(true, "Raphael");
  assert.deepEqual(JSON.parse(socket.sent[1]), {
    type: "typing.started",
    name: "Raphael",
  });
  client.receipt("read", {
    id: 12,
    client_id: "agent_12",
    direction: "agent",
    body: "Resposta",
    status: "sent",
    timestamp: "2026-10-04T00:00:00Z",
  });
  assert.deepEqual(JSON.parse(socket.sent[2]), {
    type: "message.read",
    message_id: 12,
    request_id: "read-12",
  });

  socket.onmessage?.({ data: JSON.stringify({ type: "typing.started" }) });
  assert.equal(events[0].type, "typing.started");
  client.close();
  assert.equal(socket.closed, true);
  assert.equal(socket.onclose, null);
});

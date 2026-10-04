import assert from "node:assert/strict";
import crypto from "node:crypto";
import http from "node:http";
import test, { after } from "node:test";
import { WebSocket } from "ws";

process.env.MAUTIC_WEBCHAT_REALTIME_SECRET = "test-secret-that-is-at-least-thirty-two-characters";
process.env.WEBCHAT_PORT = "0";
process.env.NODE_ENV = "test";

const ingested = [];
const ingestServer = http.createServer((request, response) => {
  let raw = "";
  request.on("data", (chunk) => { raw += chunk; });
  request.on("end", () => {
    ingested.push({ authorization: request.headers.authorization, body: JSON.parse(raw) });
    response.writeHead(200, { "content-type": "application/json" });
    response.end(JSON.stringify({ ok: true, message: { id: 77 } }));
  });
});
await new Promise((resolve) => ingestServer.listen(0, "127.0.0.1", resolve));
process.env.MAUTIC_WEBCHAT_INGEST_URL = `http://127.0.0.1:${ingestServer.address().port}/ingest`;
const { verifyToken, server, sockets } = await import("./server.mjs");
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));

after(async () => {
  for (const client of sockets.clients || []) client.close();
  await Promise.all([
    new Promise((resolve) => server.close(resolve)),
    new Promise((resolve) => ingestServer.close(resolve)),
  ]);
});

const encode = (value) => Buffer.from(value).toString("base64url");
const token = (sid, role) => {
  const payload = encode(JSON.stringify({ sid, role, exp: Math.floor(Date.now() / 1000) + 300 }));
  const signature = crypto.createHmac("sha256", process.env.MAUTIC_WEBCHAT_REALTIME_SECRET).update(payload).digest("base64url");
  return `${payload}.${signature}`;
};
const next = (socket, type) => new Promise((resolve, reject) => {
  const timeout = setTimeout(() => reject(new Error(`timeout waiting for ${type}`)), 2000);
  const listener = (raw) => {
    const event = JSON.parse(raw.toString());
    if (event.type !== type) return;
    clearTimeout(timeout);
    socket.off("message", listener);
    resolve(event);
  };
  socket.on("message", listener);
});

test("accepts a valid scoped token", () => {
  const payload = encode(JSON.stringify({ sid: "a".repeat(32), role: "visitor", exp: 2_000_000_000 }));
  const signature = crypto.createHmac("sha256", process.env.MAUTIC_WEBCHAT_REALTIME_SECRET).update(payload).digest("base64url");
  assert.equal(verifyToken(`${payload}.${signature}`, process.env.MAUTIC_WEBCHAT_REALTIME_SECRET, 1_900_000_000).sid, "a".repeat(32));
});
test("rejects a modified token", () => assert.throws(() => verifyToken(`${encode(JSON.stringify({ sid: "b".repeat(32), role: "visitor", exp: 2_000_000_000 }))}.bad`, process.env.MAUTIC_WEBCHAT_REALTIME_SECRET, 1_900_000_000)));

test("relays typing, persists visitor messages and publishes server events", async () => {
  const sid = "c".repeat(32);
  const address = server.address();
  const visitor = new WebSocket(`ws://127.0.0.1:${address.port}/chat/realtime?token=${encodeURIComponent(token(sid, "visitor"))}`);
  await next(visitor, "connection.ready");
  const agent = new WebSocket(`ws://127.0.0.1:${address.port}/chat/realtime?token=${encodeURIComponent(token(sid, "agent"))}`);
  await next(agent, "connection.ready");

  const typing = next(agent, "typing.started");
  visitor.send(JSON.stringify({ type: "typing.started", name: "Visitante" }));
  assert.equal((await typing).role, "visitor");

  const acknowledged = next(visitor, "event.ack");
  visitor.send(JSON.stringify({ type: "message.send", body: "Olá", client_id: "msg_1234567890123456" }));
  assert.equal((await acknowledged).result.message.id, 77);
  assert.equal(ingested.at(-1).body.session, sid);
  assert.equal(ingested.at(-1).body.role, "visitor");
  assert.equal(ingested.at(-1).authorization, `Bearer ${process.env.MAUTIC_WEBCHAT_REALTIME_SECRET}`);

  const published = next(visitor, "message.created");
  const response = await fetch(`http://127.0.0.1:${address.port}/publish`, {
    method: "POST",
    headers: { authorization: `Bearer ${process.env.MAUTIC_WEBCHAT_REALTIME_SECRET}`, "content-type": "application/json" },
    body: JSON.stringify({ session: sid, event: { type: "message.created", message: { id: 78 } } }),
  });
  assert.equal(response.status, 202);
  assert.equal((await published).message.id, 78);
  visitor.close();
  agent.close();
});

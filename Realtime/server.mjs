import crypto from "node:crypto";
import http from "node:http";
import { WebSocketServer, WebSocket } from "ws";

const host = process.env.WEBCHAT_HOST || "127.0.0.1";
const port = Number(process.env.WEBCHAT_PORT || 8790);
const secret = process.env.MAUTIC_WEBCHAT_REALTIME_SECRET || "";
const ingestUrl = process.env.MAUTIC_WEBCHAT_INGEST_URL || "";
if (secret.length < 32) throw new Error("MAUTIC_WEBCHAT_REALTIME_SECRET is required");
if (!/^https?:\/\//.test(ingestUrl)) throw new Error("MAUTIC_WEBCHAT_INGEST_URL is required");

const rooms = new Map();
const json = (response, status, body) => {
  response.writeHead(status, { "content-type": "application/json", "cache-control": "no-store" });
  response.end(JSON.stringify(body));
};
const bearer = (request) => String(request.headers.authorization || "").replace(/^Bearer\s+/i, "");
const body = (request) => new Promise((resolve, reject) => {
  let raw = "";
  request.on("data", (chunk) => {
    raw += chunk;
    if (raw.length > 128_000) request.destroy();
  });
  request.on("end", () => { try { resolve(JSON.parse(raw || "{}")); } catch (error) { reject(error); } });
  request.on("error", reject);
});
const decode = (value) => Buffer.from(value.replace(/-/g, "+").replace(/_/g, "/"), "base64").toString("utf8");

export function verifyToken(token, key = secret, now = Math.floor(Date.now() / 1000)) {
  const [payload, signature] = String(token || "").split(".");
  if (!payload || !signature) throw new Error("invalid token");
  const expected = crypto.createHmac("sha256", key).update(payload).digest("base64url");
  if (expected.length !== signature.length || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))) throw new Error("invalid token");
  const claims = JSON.parse(decode(payload));
  if (!/^[a-f0-9]{32}$/.test(claims.sid || "") || !["visitor", "agent"].includes(claims.role) || Number(claims.exp) < now) throw new Error("expired token");
  return claims;
}

function room(session) {
  if (!rooms.has(session)) rooms.set(session, new Set());
  return rooms.get(session);
}
function send(socket, event) {
  if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify(event));
}
function broadcast(session, event, except = null) {
  for (const socket of rooms.get(session) || []) if (socket !== except) send(socket, event);
}
async function ingest(socket, event) {
  const response = await fetch(ingestUrl, {
    method: "POST",
    headers: { authorization: `Bearer ${secret}`, "content-type": "application/json" },
    body: JSON.stringify({ ...event, session: socket.claims.sid, role: socket.claims.role }),
    signal: AbortSignal.timeout(30_000),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.error || `HTTP ${response.status}`);
  return result;
}

const server = http.createServer(async (request, response) => {
  if (request.method === "GET" && request.url === "/health") return json(response, 200, { ok: true, connections: [...rooms.values()].reduce((sum, clients) => sum + clients.size, 0), rooms: rooms.size, uptime: Math.round(process.uptime()) });
  if (request.method === "POST" && request.url === "/publish") {
    if (bearer(request) !== secret) return json(response, 401, { error: "unauthorized" });
    try {
      const input = await body(request);
      if (!/^[a-f0-9]{32}$/.test(input.session || "") || !input.event || typeof input.event.type !== "string") return json(response, 422, { error: "invalid event" });
      broadcast(input.session, input.event);
      return json(response, 202, { ok: true });
    } catch { return json(response, 400, { error: "invalid json" }); }
  }
  return json(response, 404, { error: "not found" });
});

const sockets = new WebSocketServer({ noServer: true, clientTracking: false, maxPayload: 64 * 1024 });
server.on("upgrade", (request, socket, head) => {
  try {
    const url = new URL(request.url || "/", "http://localhost");
    const claims = verifyToken(url.searchParams.get("token"));
    sockets.handleUpgrade(request, socket, head, (client) => {
      client.claims = claims;
      sockets.emit("connection", client, request);
    });
  } catch { socket.write("HTTP/1.1 401 Unauthorized\r\n\r\n"); socket.destroy(); }
});
sockets.on("connection", (socket) => {
  const { sid, role } = socket.claims;
  room(sid).add(socket);
  send(socket, { type: "connection.ready", role, at: new Date().toISOString() });
  broadcast(sid, { type: "presence.changed", role, online: true, at: new Date().toISOString() }, socket);
  socket.on("message", async (raw) => {
    let event;
    try { event = JSON.parse(raw.toString()); } catch { return send(socket, { type: "error", code: "invalid_json" }); }
    if (event.type === "ping") return send(socket, { type: "pong", at: new Date().toISOString() });
    if (["typing.started", "typing.stopped"].includes(event.type)) {
      return broadcast(sid, { type: event.type, role, name: String(event.name || "").slice(0, 80), at: new Date().toISOString() }, socket);
    }
    if (event.type === "message.send" && role !== "visitor") return send(socket, { type: "error", code: "forbidden" });
    if (!["message.send", "message.delivered", "message.read"].includes(event.type)) return send(socket, { type: "error", code: "unsupported_event" });
    try {
      const result = await ingest(socket, event);
      send(socket, { type: "event.ack", request_id: event.request_id || event.client_id || null, result });
    } catch (error) {
      send(socket, { type: "event.failed", request_id: event.request_id || event.client_id || null, error: error.message });
    }
  });
  let cleaned = false;
  const clean = () => {
    if (cleaned) return;
    cleaned = true;
    const clients = rooms.get(sid);
    clients?.delete(socket);
    const roleStillOnline = [...(clients || [])].some((client) => client.claims.role === role && client.readyState === WebSocket.OPEN);
    if (!clients?.size) rooms.delete(sid);
    if (!roleStillOnline) broadcast(sid, { type: "presence.changed", role, online: false, at: new Date().toISOString() });
  };
  socket.once("close", clean);
  socket.once("error", clean);
});

if (process.env.NODE_ENV !== "test") {
  server.listen(port, host, () => process.stdout.write(`Mautic Web Chat realtime listening on ${host}:${port}\n`));
}

export { server, sockets };

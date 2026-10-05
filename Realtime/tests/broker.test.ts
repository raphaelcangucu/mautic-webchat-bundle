import assert from "node:assert/strict";
import test from "node:test";
import { spawn } from "node:child_process";
import { createServer } from "node:net";
import { createHmac } from "node:crypto";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
const secret = "test-secret-".repeat(8);
function token(
  sid: string,
  role: string,
  exp = Math.floor(Date.now() / 1000) + 3600,
): string {
  const payload = Buffer.from(JSON.stringify({ sid, role, exp })).toString(
    "base64url",
  );
  return `${payload}.${createHmac("sha256", secret).update(payload).digest("base64url")}`;
}
test("PHP SSE authenticates rooms, carries typing and receipts, replays and keeps memory bounded", async (t) => {
  const socket = createServer();
  await new Promise<void>((r) => socket.listen(0, "127.0.0.1", r));
  const port = (socket.address() as { port: number }).port;
  await new Promise<void>((r) => socket.close(() => r()));
  const runtime = await mkdtemp(`${tmpdir()}/webchat-sse-test-`);
  const proc = spawn("php", [resolve("Realtime/server.php"), "start"], {
    env: {
      ...process.env,
      MAUTIC_WEBCHAT_REALTIME_SECRET: secret,
      WEBCHAT_PORT: String(port),
      WEBCHAT_RUNTIME_DIR: runtime,
    },
    stdio: ["ignore", "pipe", "pipe"],
  });
  let output = "";
  proc.stdout.on("data", (v) => (output += v));
  proc.stderr.on("data", (v) => (output += v));
  const controllers: AbortController[] = [];
  t.after(async () => {
    controllers.forEach((c) => c.abort());
    proc.kill("SIGINT");
    await new Promise((r) => proc.once("exit", r));
    await rm(runtime, { recursive: true, force: true });
  });
  const base = `http://127.0.0.1:${port}`;
  let healthy = false;
  for (let i = 0; i < 50; i++) {
    try {
      healthy = (await fetch(`${base}/health`)).ok;
      if (healthy) break;
    } catch {}
    await new Promise((r) => setTimeout(r, 50));
  }
  assert.equal(healthy, true, output);
  assert.equal((await fetch(`${base}/chat/realtime?token=bad`)).status, 401);
  assert.equal(
    (
      await fetch(
        `${base}/chat/realtime?token=${token("a".repeat(32), "visitor", 1)}`,
      )
    ).status,
    401,
  );
  assert.equal(
    (await fetch(`${base}/publish`, { method: "POST", body: "{}" })).status,
    401,
  );
  async function stream(sid: string, role: string, lastId?: string) {
    const controller = new AbortController();
    controllers.push(controller);
    const res = await fetch(`${base}/chat/realtime?token=${token(sid, role)}`, {
      signal: controller.signal,
      headers: lastId ? { "Last-Event-ID": lastId } : {},
    });
    assert.equal(res.status, 200);
    assert.equal(res.headers.get("content-type"), "text/event-stream");
    const events: { id?: string; event: Record<string, unknown> }[] = [];
    void (async () => {
      let buffer = "";
      try {
        for await (const chunk of res.body!) {
          buffer += new TextDecoder().decode(chunk);
          let at;
          while ((at = buffer.indexOf("\n\n")) >= 0) {
            const block = buffer.slice(0, at);
            buffer = buffer.slice(at + 2);
            const data = block.split("\n").find((v) => v.startsWith("data: "));
            if (data)
              events.push({
                id: block
                  .split("\n")
                  .find((v) => v.startsWith("id: "))
                  ?.slice(4),
                event: JSON.parse(data.slice(6)),
              });
          }
        }
      } catch {}
    })();
    return { events, controller };
  }
  async function waitFor(fn: () => boolean) {
    for (let i = 0; i < 100 && !fn(); i++)
      await new Promise((r) => setTimeout(r, 10));
    assert.equal(fn(), true);
  }
  async function publish(sid: string, event: Record<string, unknown>) {
    assert.equal(
      (
        await fetch(`${base}/publish`, {
          method: "POST",
          headers: {
            authorization: `Bearer ${secret}`,
            "content-type": "application/json",
          },
          body: JSON.stringify({ session: sid, event }),
        })
      ).status,
      202,
    );
  }
  const sid = "a".repeat(32);
  const visitor = await stream(sid, "visitor");
  const agent = await stream(sid, "agent");
  const other = await stream("b".repeat(32), "visitor");
  await publish(sid, {
    type: "typing.started",
    role: "visitor",
    name: "Teste",
  });
  await waitFor(() =>
    agent.events.some((e) => e.event.type === "typing.started"),
  );
  assert.equal(
    other.events.some((e) => e.event.type === "typing.started"),
    false,
  );
  await publish(sid, {
    type: "message.created",
    message: { id: 1, body: "Olá" },
  });
  await waitFor(() =>
    visitor.events.some((e) => e.event.type === "message.created"),
  );
  const id = visitor.events.find(
    (e) => e.event.type === "message.created",
  )!.id!;
  assert.ok(id);
  visitor.controller.abort();
  await publish(sid, { type: "message.read", role: "agent", message_id: 1 });
  const reconnected = await stream(sid, "visitor", id);
  await waitFor(() =>
    reconnected.events.some((e) => e.event.type === "message.read"),
  );
  for (let i = 0; i < 300; i++)
    await publish(sid, {
      type: "message.created",
      message: { id: i + 2, body: "x".repeat(5000) },
    });
  const health = await (await fetch(`${base}/health`)).json();
  assert.ok(health.replay_bytes <= 1048576);
  assert.equal(health.transport, "sse");
  assert.equal(health.runtime, "php");
  const gap = await stream(sid, "visitor", id);
  await waitFor(() => gap.events.some((e) => e.event.type === "sync.required"));
});

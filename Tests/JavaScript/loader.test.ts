import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { JSDOM } from "jsdom";

const source = readFileSync(
  new URL("../../Controller/PublicController.php", import.meta.url),
  "utf8",
);
const script = source
  .match(/<<<'JS'\n([\s\S]*?)\nJS;/)![1]
  .replaceAll("FRAME_URL", JSON.stringify("https://chat.example/widget/key"));
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
function fixture(healthVersion = 0) {
  const dom = new JSDOM(
    '<html lang="en" class="dark"><head><title>Macro Markets</title></head><body style="font-family: ui-sans-serif, system-ui"></body></html>',
    {
      url: "https://site.example/?utm_source=meta&secret=private",
      runScripts: "outside-only",
      pretendToBeVisual: true,
    },
  );
  const { window } = dom;
  Object.defineProperty(window.navigator, "onLine", { value: false });
  const timers = new Map<number, { callback: () => void; delay: number }>();
  let nextTimer = 0;
  window.setTimeout = ((callback: () => void, delay: number) => {
    timers.set(++nextTimer, { callback, delay });
    return nextTimer;
  }) as any;
  window.clearTimeout = ((id: number) => {
    timers.delete(id);
  }) as any;
  window.eval(script);
  const frame = window.document.querySelector("iframe")!;
  const commands: any[] = [];
  frame.contentWindow!.postMessage = (message: any) =>
    commands.push(JSON.parse(JSON.stringify(message)));
  window.dispatchEvent(
    new window.MessageEvent("message", {
      source: frame.contentWindow,
      origin: "https://chat.example",
      data: { type: "webchat.ready", healthVersion },
    }),
  );
  const fire = (delay: number) => {
    const entry = [...timers].find(([, value]) => value.delay === delay);
    assert.ok(entry, `missing timer ${delay}`);
    timers.delete(entry[0]);
    entry[1].callback();
  };
  return {
    dom,
    window,
    frame,
    commands,
    timers,
    fire,
    api: (window as any).MauticWebChat,
  };
}
test("loader detects site font, appearance and locale; watches SPA routes and title without new frames", async () => {
  const { dom, window, commands, api } = fixture();
  try {
    assert.equal(commands[0].context.locale, "en");
    assert.equal(commands[0].context.appearance, "dark");
    assert.equal(commands[0].context.fontFamily, "ui-sans-serif, system-ui");
    assert.equal(commands[0].pageUrl, "https://site.example/");
    assert.deepEqual(commands[0].utm, { utm_source: "meta" });
    api.identify({ subject: "account:42", identityToken: "signed" });
    window.document.documentElement.lang = "es";
    window.document.documentElement.classList.remove("dark");
    window.history.pushState({}, "", "/market/nfl?token=private#price");
    window.document.title = "Bears vs Packers";
    await tick();
    const update = commands.filter((c) => c.action === "configure").at(-1);
    assert.equal(update.pageUrl, "https://site.example/market/nfl");
    assert.equal(update.pageTitle, "Bears vs Packers");
    assert.equal(update.context.locale, "es");
    assert.equal(update.context.appearance, "light");
    assert.equal(commands.filter((c) => c.action === "reset").length, 0);
    assert.equal(window.document.querySelectorAll("iframe").length, 1);
    const count = commands.length;
    window.history.replaceState({}, "", window.location.href);
    assert.equal(commands.length, count);
    window.dispatchEvent(
      new window.MessageEvent("message", {
        origin: "https://evil.example",
        data: { type: "webchat.ready", healthVersion: 1 },
      }),
    );
    assert.equal(commands.length, count);
  } finally {
    api.destroy();
    dom.window.close();
  }
});
test("explicit site configuration is preserved and destroy stops observers and restores history", async () => {
  const { dom, window, commands, api } = fixture();
  try {
    api.configure({
      locale: "pt-BR",
      accountPending: true,
      appearance: "light",
    });
    window.document.documentElement.lang = "en";
    await tick();
    const update = commands.filter((c) => c.action === "configure").at(-1);
    assert.equal(update.context.locale, "pt-BR");
    assert.equal(update.context.accountPending, true);
    const wrappedHistory = window.history.pushState;
    api.destroy();
    assert.notEqual(window.history.pushState, wrappedHistory);
    const count = commands.length;
    window.document.documentElement.lang = "es";
    window.history.pushState({}, "", "/next");
    await tick();
    assert.equal(commands.length, count);
    assert.equal(window.document.querySelectorAll("iframe").length, 0);
  } finally {
    dom.window.close();
  }
});

test("an unresponsive frame resumes with its account, draft and open state; retries are bounded", () => {
  const f = fixture(1);
  const { window, api, commands, frame, fire } = f;
  const receive = (data: any, origin = "https://chat.example") => {
    frame.contentWindow!.postMessage = (message: any) =>
      commands.push(JSON.parse(JSON.stringify(message)));
    window.dispatchEvent(
      new window.MessageEvent("message", {
        source: frame.contentWindow,
        origin,
        data,
      }),
    );
  };
  const errors: any[] = [];
  window.addEventListener("mautic-webchat:error", ((event: CustomEvent) =>
    errors.push(event.detail)) as any);
  try {
    api.identify({ subject: "account:42", identityToken: "signed" });
    receive({ type: "webchat.resize", open: true });
    receive({
      type: "webchat.draft",
      subject: "account:42",
      value: "My unsent message",
    });
    fire(15000);
    const ping = commands.at(-1);
    assert.equal(ping.type, "webchat.ping");
    receive({ type: "webchat.pong", id: ping.id }, "https://evil.example");
    assert.ok(
      [...f.timers.values()].some((t) => t.delay === 5000),
      "foreign pong cannot keep the frame alive",
    );
    fire(5000);
    assert.equal(errors[0].attempt, 1);
    assert.equal(api.isReady(), false);
    receive({ type: "webchat.ready", healthVersion: 1 });
    const bootstrap = commands
      .filter((c) => c.type === "webchat.bootstrap")
      .at(-1);
    assert.equal(bootstrap.user.subject, "account:42");
    assert.equal(bootstrap.message, "My unsent message");
    assert.equal(commands.at(-1).action, "open");
    assert.equal(window.document.querySelectorAll("iframe").length, 1);
    fire(15000);
    fire(5000);
    fire(15000);
    assert.deepEqual(
      errors.map((e) => e.attempt),
      [1, 2, undefined],
    );
    assert.equal(frame.hidden, true);
    assert.match(
      window.document.querySelector("body > button")!.textContent!,
      /Reconnect support/,
    );
    assert.equal(
      f.timers.size,
      0,
      "persistent failure must stop automatic reloads",
    );
    (
      window.document.querySelector("body > button") as HTMLButtonElement
    ).click();
    assert.equal(frame.hidden, false);
    api.destroy();
    assert.equal(f.timers.size, 0);
  } finally {
    window.close();
  }
});

test("healthy and background frames are not reloaded; account changes discard the old draft", () => {
  const f = fixture(1);
  const { window, api, commands, frame, fire } = f;
  const receive = (data: any) =>
    window.dispatchEvent(
      new window.MessageEvent("message", {
        source: frame.contentWindow,
        origin: "https://chat.example",
        data,
      }),
    );
  try {
    fire(15000);
    receive({ type: "webchat.pong", id: commands.at(-1).id });
    assert.ok([...f.timers.values()].some((t) => t.delay === 15000));
    assert.ok(![...f.timers.values()].some((t) => t.delay === 5000));
    Object.defineProperty(window.document, "hidden", {
      configurable: true,
      value: true,
    });
    window.document.dispatchEvent(new window.Event("visibilitychange"));
    assert.equal(f.timers.size, 0);
    api.identify({ subject: "account:42", identityToken: "signed" });
    receive({
      type: "webchat.draft",
      subject: "account:42",
      value: "Private draft",
    });
    api.identify({ subject: "account:43", identityToken: "other" });
    receive({
      type: "webchat.draft",
      subject: "account:42",
      value: "Late private draft",
    });
    receive({ type: "webchat.ready", healthVersion: 1 });
    assert.equal(
      commands.filter((c) => c.type === "webchat.bootstrap").at(-1).message,
      "",
    );
    api.destroy();
  } finally {
    window.close();
  }
});

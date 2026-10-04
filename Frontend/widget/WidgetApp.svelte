<script lang="ts">
  import { afterUpdate, onDestroy, onMount } from "svelte";
  import { RealtimeClient } from "./realtime";
  import type {
    Bootstrap,
    ChatMessage,
    SessionData,
    WidgetConfig,
  } from "./types";

  export let root: HTMLElement;
  const publicKey = root.dataset.publicKey || "";
  const initialConfig = JSON.parse(root.dataset.config || "{}") as WidgetConfig;
  let config = initialConfig;
  let bootstrap: Bootstrap | null = null;
  let parentOrigin = "*";
  let open = false;
  let started = false;
  let name = localStorage.getItem(`mw-name:${publicKey}`) || "";
  let email = localStorage.getItem(`mw-email:${publicKey}`) || "";
  let body = "";
  let error = "";
  let loading = false;
  let session: SessionData | null = null;
  let messages: ChatMessage[] = [];
  let realtime: RealtimeClient | null = null;
  let connection: "connecting" | "online" | "offline" = "connecting";
  let agentTyping = false;
  let agentTypingName = "Atendimento";
  let agentOnline = false;
  let unread = 0;
  let list: HTMLDivElement;
  let typingTimer = 0;

  const stored = () => ({
    resume_session: localStorage.getItem(`mw-session:${publicKey}`) || "",
    resume_token: localStorage.getItem(`mw-token:${publicKey}`) || "",
  });
  const uid = (prefix = "msg") =>
    `${prefix}_${crypto.randomUUID().replaceAll("-", "")}`;
  const visitorId = () => {
    const key = `mw-visitor:${publicKey}`;
    let value = localStorage.getItem(key);
    if (!value) {
      value = crypto.randomUUID().replaceAll("-", "");
      localStorage.setItem(key, value);
    }
    return value;
  };
  const tellParent = (message: Record<string, unknown>) =>
    window.parent.postMessage(message, parentOrigin);

  function setOpen(value: boolean): void {
    open = value;
    unread = open ? 0 : unread;
    tellParent({ type: "webchat.resize", open });
    tellParent({ type: "webchat.state", open });
    if (open) markRead();
  }

  function toggle(): void {
    setOpen(!open);
  }

  function identify(user: { name?: string; email?: string } = {}): void {
    if (user.name) name = String(user.name);
    if (user.email) email = String(user.email);
  }

  function reset(): void {
    realtime?.close();
    realtime = null;
    session = null;
    messages = [];
    started = false;
    name = "";
    email = "";
    body = "";
    error = "";
    ["session", "token", "name", "email", "visitor"].forEach((key) =>
      localStorage.removeItem(`mw-${key}:${publicKey}`),
    );
    setOpen(false);
  }

  async function start(): Promise<void> {
    if (!bootstrap || loading) return;
    error = "";
    if (config.require_name && !name.trim()) {
      error = "Informe seu nome para continuar.";
      return;
    }
    if (config.require_email && !/^\S+@\S+\.\S+$/.test(email)) {
      error = "Informe um e-mail válido.";
      return;
    }
    loading = true;
    try {
      const response = await fetch(
        `/chat/api/${encodeURIComponent(publicKey)}/sessions`,
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({
            ...stored(),
            visitor_id: visitorId(),
            name: name.trim(),
            email: email.trim(),
            site_origin: bootstrap.siteOrigin,
            page_url: bootstrap.pageUrl,
            referrer: bootstrap.referrer,
            utm: bootstrap.utm,
          }),
        },
      );
      const data = await response.json();
      if (!response.ok)
        throw new Error(data.error || "Não foi possível iniciar o chat.");
      session = data as SessionData;
      config = session.widget;
      messages = session.messages;
      started = true;
      localStorage.setItem(`mw-session:${publicKey}`, session.session);
      localStorage.setItem(`mw-token:${publicKey}`, session.session_token);
      localStorage.setItem(`mw-name:${publicKey}`, name);
      localStorage.setItem(`mw-email:${publicKey}`, email);
      connect();
      markRead();
    } catch (problem) {
      error = problem instanceof Error ? problem.message : String(problem);
    } finally {
      loading = false;
    }
  }

  function connect(): void {
    if (!session) return;
    realtime?.close();
    realtime = new RealtimeClient(session, {
      status(value) {
        connection = value;
      },
      event(event) {
        if (event.type === "message.created" && event.message) {
          const message = event.message as ChatMessage;
          const existing = messages.findIndex(
            (item) => item.client_id === message.client_id,
          );
          messages =
            existing >= 0
              ? messages.map((item, index) =>
                  index === existing ? message : item,
                )
              : [...messages, message];
          if (message.direction !== "visitor") {
            realtime?.receipt("delivered", message);
            if (open && !document.hidden) realtime?.receipt("read", message);
            else unread += 1;
          }
        } else if (
          (event.type === "message.delivered" ||
            event.type === "message.read") &&
          event.message_id
        ) {
          const status = event.type === "message.read" ? "read" : "delivered";
          messages = messages.map((item) =>
            item.id <= Number(event.message_id) && item.direction === "visitor"
              ? { ...item, status }
              : item,
          );
        } else if (event.type === "typing.started" && event.role === "agent") {
          agentTypingName = String(event.name || "Atendimento");
          agentTyping = true;
        } else if (event.type === "typing.stopped" && event.role === "agent")
          agentTyping = false;
        else if (event.type === "presence.changed" && event.role === "agent")
          agentOnline = Boolean(event.online);
        else if (event.type === "event.failed" && event.request_id)
          messages = messages.map((item) =>
            item.client_id === event.request_id
              ? { ...item, status: "failed" }
              : item,
          );
      },
    });
    realtime.connect();
  }

  async function send(): Promise<void> {
    const text = body.trim();
    if (!text || !session) return;
    const clientId = uid();
    const optimistic: ChatMessage = {
      id: -Date.now(),
      client_id: clientId,
      direction: "visitor",
      body: text,
      status: "pending",
      author: name,
      timestamp: new Date().toISOString(),
    };
    messages = [...messages, optimistic];
    body = "";
    stopTyping();
    if (realtime?.sendMessage(text, clientId)) return;
    try {
      const response = await fetch(
        `/chat/api/sessions/${session.session}/messages`,
        {
          method: "POST",
          headers: {
            "content-type": "application/json",
            authorization: `Bearer ${session.session_token}`,
          },
          body: JSON.stringify({ body: text, client_id: clientId }),
        },
      );
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Falha ao enviar.");
      messages = messages.map((item) =>
        item.client_id === clientId ? data.message : item,
      );
    } catch {
      messages = messages.map((item) =>
        item.client_id === clientId ? { ...item, status: "failed" } : item,
      );
    }
  }

  function typed(): void {
    realtime?.typing(true, name);
    clearTimeout(typingTimer);
    typingTimer = window.setTimeout(stopTyping, 1300);
  }
  function stopTyping(): void {
    clearTimeout(typingTimer);
    realtime?.typing(false, name);
  }
  function markRead(): void {
    if (!open || document.hidden) return;
    const latest = [...messages]
      .reverse()
      .find((message) => message.direction !== "visitor" && message.id > 0);
    if (latest) realtime?.receipt("read", latest);
  }
  const statusText = (message: ChatMessage): string =>
    ({
      pending: "Enviando…",
      sent: "Enviada",
      delivered: "Entregue",
      read: "Lida",
      failed: "Falha no envio",
    })[message.status] || message.status;
  const statusIcon = (message: ChatMessage): string =>
    ({ pending: "◷", sent: "✓", delivered: "✓✓", read: "✓✓", failed: "!" })[
      message.status
    ] || "";
  const time = (value: string) =>
    new Intl.DateTimeFormat("pt-BR", {
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(value));

  onMount(() => {
    document.documentElement.style.setProperty(
      "--wc-accent",
      config.accent_color || "#4e5ba6",
    );
    const onMessage = (event: MessageEvent) => {
      if (event.source !== window.parent) return;
      if (event.data?.type === "webchat.bootstrap") {
        parentOrigin = event.origin;
        bootstrap = {
          siteOrigin: event.origin,
          pageUrl: String(event.data.pageUrl || ""),
          referrer: String(event.data.referrer || ""),
          utm: event.data.utm || {},
          user: event.data.user || {},
          message: String(event.data.message || ""),
        };
        identify(bootstrap.user);
        if (bootstrap.message) body = bootstrap.message;
        if (stored().resume_session && stored().resume_token) void start();
        return;
      }
      if (
        event.origin !== parentOrigin ||
        event.data?.type !== "webchat.command"
      )
        return;
      if (event.data.action === "open") {
        if (event.data.message) body = String(event.data.message);
        setOpen(true);
      } else if (event.data.action === "close") setOpen(false);
      else if (event.data.action === "toggle") toggle();
      else if (event.data.action === "identify") identify(event.data.user);
      else if (event.data.action === "reset") reset();
    };
    const visible = () => markRead();
    window.addEventListener("message", onMessage);
    window.addEventListener("focus", visible);
    document.addEventListener("visibilitychange", visible);
    tellParent({ type: "webchat.ready" });
    return () => {
      window.removeEventListener("message", onMessage);
      window.removeEventListener("focus", visible);
      document.removeEventListener("visibilitychange", visible);
    };
  });
  afterUpdate(() => {
    if (list) list.scrollTop = list.scrollHeight;
    markRead();
  });
  onDestroy(() => {
    realtime?.close();
    clearTimeout(typingTimer);
  });
</script>

{#if !open}
  <button class="launcher" on:click={toggle} aria-label="Abrir atendimento">
    <svg viewBox="0 0 24 24" aria-hidden="true"
      ><path
        d="M20 11.5a7.5 7.5 0 0 1-8 7.48 8.6 8.6 0 0 1-3.7-.82L4 20l1.37-3.66A7.5 7.5 0 1 1 20 11.5Z"
      /></svg
    >
    {#if unread}<span class="unread">{unread}</span>{/if}
  </button>
{:else}
  <section class="panel" aria-label="Atendimento online">
    <header>
      <div class="brand">
        <span class="brand-mark">M</span>
        <div>
          <strong>{config.name}</strong><small
            ><i class:online={connection === "online"}></i>{connection ===
            "online"
              ? agentOnline
                ? "Equipe online"
                : "Conectado"
              : connection === "connecting"
                ? "Conectando…"
                : "Reconectando…"}</small
          >
        </div>
      </div>
      <button class="close" on:click={toggle} aria-label="Minimizar chat"
        >−</button
      >
    </header>
    {#if !started}
      <div class="welcome">
        <div class="welcome-icon">M</div>
        <h1>{config.greeting}</h1>
        <p>Converse com nossa equipe sem sair desta página.</p>
        <form on:submit|preventDefault={start}>
          <label
            >Nome {config.require_name ? "" : "(opcional)"}<input
              bind:value={name}
              autocomplete="name"
              maxlength="120"
              required={config.require_name}
              placeholder="Como podemos chamar você?"
            /></label
          >
          <label
            >E-mail {config.require_email ? "" : "(opcional)"}<input
              bind:value={email}
              type="email"
              autocomplete="email"
              maxlength="190"
              required={config.require_email}
              placeholder="voce@exemplo.com"
            /></label
          >
          {#if error}<div class="error" role="alert">{error}</div>{/if}
          <button class="primary" type="submit" disabled={loading}
            >{loading ? "Iniciando…" : "Começar conversa"}</button
          >
        </form>
        <small class="privacy"
          >Seus dados serão usados somente para este atendimento.</small
        >
      </div>
    {:else}
      <div class="messages" bind:this={list} aria-live="polite">
        <div class="day">Hoje</div>
        {#if !messages.length}<div class="greeting">
            <span class="agent-avatar">M</span>
            <div>
              <strong>Equipe Macro</strong>
              <p>{config.greeting}</p>
            </div>
          </div>{/if}
        {#each messages as message (message.client_id)}
          <article class:mine={message.direction === "visitor"} class="message">
            {#if message.direction !== "visitor"}<small class="author"
                >{message.author || "Equipe Macro"}</small
              >{/if}
            <div class="bubble">{message.body}</div>
            <small
              class:read={message.status === "read"}
              class:failed={message.status === "failed"}
              >{time(message.timestamp)}{message.direction === "visitor"
                ? ` · ${statusIcon(message)} ${statusText(message)}`
                : ""}</small
            >
          </article>
        {/each}
        {#if agentTyping}<div class="typing">
            <span></span><span></span><span></span><em
              >{agentTypingName} está digitando…</em
            >
          </div>{/if}
      </div>
      <form class="composer" on:submit|preventDefault={send}>
        {#if connection === "offline"}<div class="offline">
            {config.offline_message}
          </div>{/if}
        <textarea
          bind:value={body}
          on:input={typed}
          on:blur={stopTyping}
          rows="1"
          maxlength="4000"
          placeholder="Escreva uma mensagem"
          aria-label="Mensagem"
          on:keydown={(event) => {
            if (event.key === "Enter" && !event.shiftKey) {
              event.preventDefault();
              void send();
            }
          }}
        ></textarea>
        <button
          type="submit"
          disabled={!body.trim()}
          aria-label="Enviar mensagem"
          ><svg viewBox="0 0 24 24"
            ><path
              d="m3 3 18 9-18 9 3.5-9L3 3Zm3.7 8h7.8L6 6.75 6.7 11Zm0 2L6 17.25 14.5 13H6.7Z"
            /></svg
          ></button
        >
      </form>
      <footer>Atendimento protegido pela Macro Markets</footer>
    {/if}
  </section>
{/if}

<style>
  :global(*) {
    box-sizing: border-box;
  }
  :global(html),
  :global(body),
  :global(#mautic-webchat-widget) {
    margin: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: transparent;
    font-family:
      Inter,
      -apple-system,
      BlinkMacSystemFont,
      "Segoe UI",
      sans-serif;
    color: #202534;
    -webkit-text-size-adjust: 100%;
  }
  .launcher {
    position: absolute;
    right: 4px;
    bottom: 4px;
    width: 64px;
    height: 64px;
    border: 0;
    border-radius: 22px;
    background: var(--wc-accent);
    color: white;
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 13px 34px
      color-mix(in srgb, var(--wc-accent) 35%, transparent);
    transition: transform 0.18s ease;
  }
  .launcher:hover {
    transform: translateY(-2px);
  }
  .launcher:focus-visible,
  .close:focus-visible,
  .primary:focus-visible,
  .composer button:focus-visible,
  input:focus-visible,
  textarea:focus-visible {
    outline: 3px solid color-mix(in srgb, var(--wc-accent) 32%, white);
    outline-offset: 2px;
  }
  .launcher svg {
    width: 29px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
  }
  .unread {
    position: absolute;
    right: -2px;
    top: -4px;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border: 2px solid white;
    border-radius: 11px;
    background: #d63d53;
    font: 700 12px/18px system-ui;
  }
  .panel {
    width: 100%;
    height: 100%;
    min-height: 0;
    display: grid;
    grid-template-rows: auto 1fr auto auto;
    border: 1px solid #dfe3ec;
    border-radius: 22px;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 24px 70px rgba(25, 34, 62, 0.2);
  }
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 16px;
    background: linear-gradient(
      135deg,
      var(--wc-accent),
      color-mix(in srgb, var(--wc-accent) 76%, #262f72)
    );
    color: white;
  }
  .brand {
    display: flex;
    gap: 11px;
    align-items: center;
  }
  .brand-mark,
  .welcome-icon,
  .agent-avatar {
    display: grid;
    place-items: center;
    background: white;
    color: var(--wc-accent);
    font-weight: 800;
  }
  .brand-mark {
    width: 38px;
    height: 38px;
    border-radius: 13px;
  }
  .brand strong {
    display: block;
    font-size: 14px;
  }
  .brand small {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 3px;
    color: rgba(255, 255, 255, 0.8);
  }
  .brand i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #f0b44d;
  }
  .brand i.online {
    background: #6ce3aa;
  }
  .close {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.13);
    color: white;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
  }
  .welcome {
    padding: 25px 24px 18px;
    overflow: auto;
  }
  .welcome-icon {
    width: 50px;
    height: 50px;
    border-radius: 16px;
    background: color-mix(in srgb, var(--wc-accent) 12%, white);
    font-size: 20px;
  }
  .welcome h1 {
    font-size: 21px;
    line-height: 1.3;
    margin: 17px 0 5px;
  }
  .welcome > p {
    margin: 0 0 22px;
    color: #747b8d;
    font-size: 14px;
  }
  .welcome form {
    display: grid;
    gap: 14px;
  }
  .welcome label {
    font-size: 12px;
    font-weight: 700;
    color: #50586a;
  }
  .welcome input {
    width: 100%;
    height: 43px;
    margin-top: 6px;
    padding: 0 12px;
    border: 1px solid #dce1eb;
    border-radius: 11px;
    font: 16px/1.35 inherit;
  }
  .welcome input:focus {
    border-color: var(--wc-accent);
  }
  .primary {
    height: 44px;
    border: 0;
    border-radius: 11px;
    background: var(--wc-accent);
    color: white;
    font-weight: 700;
    cursor: pointer;
  }
  .primary:disabled {
    opacity: 0.55;
  }
  .privacy {
    display: block;
    margin-top: 16px;
    color: #8a91a0;
    text-align: center;
  }
  .error {
    padding: 10px 12px;
    border-radius: 10px;
    background: #fff0f1;
    color: #a42e40;
    font-size: 13px;
  }
  .messages {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    padding: 18px 16px;
    background: #f7f8fb;
    scrollbar-width: thin;
  }
  .day {
    margin: 0 auto 18px;
    width: max-content;
    padding: 4px 9px;
    border-radius: 8px;
    background: #e9ecf3;
    color: #778093;
    font-size: 11px;
  }
  .greeting {
    display: flex;
    gap: 9px;
    margin-bottom: 18px;
  }
  .agent-avatar {
    width: 29px;
    height: 29px;
    border-radius: 10px;
    background: var(--wc-accent);
    color: white;
    font-size: 11px;
    flex: none;
  }
  .greeting strong {
    font-size: 12px;
  }
  .greeting p {
    margin: 4px 0 0;
    padding: 10px 12px;
    border-radius: 5px 14px 14px 14px;
    background: white;
    box-shadow: 0 2px 8px rgba(34, 42, 66, 0.06);
    font-size: 14px;
  }
  .message {
    max-width: 84%;
    margin: 0 0 12px;
  }
  .message.mine {
    margin-left: auto;
    text-align: right;
  }
  .author {
    display: block;
    margin: 0 0 4px 6px;
    color: #687083;
    font-size: 11px;
  }
  .bubble {
    display: inline-block;
    padding: 10px 12px;
    border-radius: 5px 15px 15px 15px;
    background: white;
    box-shadow: 0 2px 8px rgba(34, 42, 66, 0.07);
    font-size: 14px;
    line-height: 1.45;
    text-align: left;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  .mine .bubble {
    border-radius: 15px 5px 15px 15px;
    background: var(--wc-accent);
    color: white;
  }
  .message > small:not(.author) {
    display: block;
    margin-top: 4px;
    color: #8b92a0;
    font-size: 10px;
  }
  .message > small.read {
    color: var(--wc-accent);
  }
  .message > small.failed {
    color: #b43145;
  }
  .typing {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #81899a;
    font-size: 11px;
  }
  .typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #9aa1af;
    animation: pulse 1s infinite;
  }
  .typing span:nth-child(2) {
    animation-delay: 0.15s;
  }
  .typing span:nth-child(3) {
    animation-delay: 0.3s;
  }
  .typing em {
    margin-left: 5px;
    font-style: normal;
  }
  .composer {
    display: grid;
    grid-template-columns: 1fr 40px;
    align-items: end;
    min-width: 0;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid #e7e9ef;
    background: white;
  }
  .composer textarea {
    display: block;
    width: 100%;
    min-width: 0;
    resize: none;
    min-height: 40px;
    max-height: 92px;
    padding: 10px 12px;
    border: 1px solid #dfe3eb;
    border-radius: 12px;
    font: 16px/1.35 inherit;
    touch-action: manipulation;
    -webkit-text-size-adjust: 100%;
  }
  .composer button {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 12px;
    background: var(--wc-accent);
    color: white;
    display: grid;
    place-items: center;
    cursor: pointer;
  }
  .composer button:disabled {
    opacity: 0.45;
  }
  .composer svg {
    width: 21px;
    fill: currentColor;
  }
  .offline {
    grid-column: 1/-1;
    padding: 8px 10px;
    border-radius: 9px;
    background: #fff4dc;
    color: #7b5b20;
    font-size: 11px;
  }
  footer {
    padding: 6px;
    text-align: center;
    color: #a0a5b1;
    font-size: 10px;
    background: white;
  }
  @keyframes pulse {
    0%,
    60%,
    100% {
      transform: translateY(0);
      opacity: 0.45;
    }
    30% {
      transform: translateY(-3px);
      opacity: 1;
    }
  }
  @media (max-width: 520px) {
    .panel {
      border-radius: 16px;
    }
    .welcome {
      padding: 22px 20px;
    }
    .composer {
      padding: 10px;
    }
  }
  @media (max-height: 560px) {
    header {
      padding: 10px 12px;
    }
    .brand-mark {
      width: 32px;
      height: 32px;
      border-radius: 10px;
    }
    .messages {
      padding: 10px 12px;
    }
    .day {
      margin-bottom: 12px;
    }
    .composer {
      padding: 8px;
    }
    footer {
      display: none;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .launcher,
    .typing span {
      transition: none;
      animation: none;
    }
  }
</style>

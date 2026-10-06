<script lang="ts">
  import { afterUpdate, onDestroy, onMount, tick } from "svelte";
  import { RealtimeClient } from "./realtime";
  import {
    resolveTheme,
    cssVariables,
    safeLogo,
    type SiteContext,
  } from "./presentation";
  import { copy, normalizeLocale, apiError } from "./i18n";
  import { canUpgradeVisitor, identityScopeChanged } from "./identityScope";
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
  let context: SiteContext = {};
  let identity: NonNullable<Bootstrap["user"]> = {};
  let generation = 0;

  let identityPending = false;
  let accountActive = false;
  let accountError = false;
  $: locale = normalizeLocale(context.locale || "pt");
  $: c = copy[locale];
  $: theme = resolveTheme(config.presentation, context, config.accent_color);
  $: styles = cssVariables(theme);
  $: brandName =
    config.presentation?.brandName ||
    context.brandName ||
    config.name.replace(/^chat\s+/i, "").trim() ||
    c.support;
  $: logo =
    theme.options.logo === "initials"
      ? ""
      : safeLogo(
          theme.options.logo === "url"
            ? config.presentation?.logoUrl
            : config.presentation?.logoUrl || context.logoUrl,
        );
  $: greeting =
    config.presentation?.translations?.[locale]?.greeting ||
    (locale === "pt" ? config.greeting : c.greeting);
  $: offlineMessage =
    config.presentation?.translations?.[locale]?.offline ||
    (locale === "pt" ? config.offline_message : c.offline);
  $: fields = {
    name:
      config.presentation?.fields?.name ||
      (config.require_name ? "required" : "optional"),
    email:
      config.presentation?.fields?.email ||
      (config.require_email ? "required" : "optional"),
    phone:
      config.presentation?.fields?.phone ||
      (config.require_phone ? "required" : "optional"),
  };
  $: if (root) {
    root.setAttribute("style", styles);
    document.documentElement.lang = locale;
  }
  $: if (theme)
    tellParent({
      type: "webchat.presentation",
      width: theme.options.width,
      position: theme.options.position,
      locale,
    });
  $: brandInitial = brandName.slice(0, 1).toUpperCase();
  let bootstrap: Bootstrap | null = null;
  let parentOrigin = "*";
  let open = false;
  let started = false;
  let name = localStorage.getItem(`mw-name:${publicKey}`) || "";
  let email = localStorage.getItem(`mw-email:${publicKey}`) || "";
  let phone = localStorage.getItem(`mw-phone:${publicKey}`) || "";
  let body = "";
  let error = "";
  let loading = false;
  let session: SessionData | null = null;
  let messages: ChatMessage[] = [];
  let realtime: RealtimeClient | null = null;
  let connection: "connecting" | "online" | "offline" = "connecting";
  let agentTyping = false;
  let agentTypingTimer = 0;
  let agentTypingName = "Atendimento";
  let agentOnline = false;
  let unread = 0;
  let list: HTMLDivElement;
  let typingTimer = 0;
  let recovering = false;
  let audioContext: AudioContext | null = null;

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

  function setUnread(value: number): void {
    unread = Math.max(0, value);
    tellParent({ type: "webchat.unread", count: unread });
  }

  function unlockSound(): boolean {
    try {
      const AudioConstructor =
        window.AudioContext ||
        (
          window as typeof window & {
            webkitAudioContext?: typeof AudioContext;
          }
        ).webkitAudioContext;
      if (!AudioConstructor) return false;
      audioContext ||= new AudioConstructor();
      if (audioContext.state === "suspended") void audioContext.resume();
      return audioContext.state === "running";
    } catch {
      return false;
    }
  }

  function playNotification(): void {
    const played = unlockSound();
    if (played && audioContext) {
      const now = audioContext.currentTime;
      [0, 0.12].forEach((delay, index) => {
        const oscillator = audioContext!.createOscillator();
        const gain = audioContext!.createGain();
        oscillator.type = "sine";
        oscillator.frequency.value = index ? 740 : 620;
        gain.gain.setValueAtTime(0.0001, now + delay);
        gain.gain.exponentialRampToValueAtTime(0.055, now + delay + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.13);
        oscillator.connect(gain);
        gain.connect(audioContext!.destination);
        oscillator.start(now + delay);
        oscillator.stop(now + delay + 0.14);
      });
    }
    tellParent({ type: "webchat.notification", unread, played });
  }

  function unreadFrom(data: SessionData): number {
    const lastRead = Number(data.visitor_last_read_message_id || 0);
    return data.messages.filter(
      (message) => message.direction !== "visitor" && message.id > lastRead,
    ).length;
  }

  function setOpen(value: boolean): void {
    unlockSound();
    open = value;
    if (open) setUnread(0);
    tellParent({ type: "webchat.resize", open });
    tellParent({ type: "webchat.state", open });
    if (open) {
      markRead();
      if (
        !started &&
        !identityPending &&
        (identity.identityToken || stored().resume_session)
      )
        void start();
    }
  }

  function toggle(): void {
    setOpen(!open);
  }

  function identify(user: NonNullable<Bootstrap["user"]> = {}): void {
    const subject = user.subject || "";
    if (
      identityScopeChanged(
        identity.subject,
        localStorage.getItem(`mw-subject:${publicKey}`),
        subject,
      )
    )
      reset(
        false,
        !!user.identityToken &&
          canUpgradeVisitor(
            identity.subject,
            localStorage.getItem(`mw-subject:${publicKey}`),
            subject,
          ),
      );
    identity = { ...user };
    identityPending = false;
    localStorage.setItem(`mw-subject:${publicKey}`, subject);
    name = String(user.name || "");
    email = String(user.email || "");
    phone = String(user.phone || "");
    if (bootstrap) bootstrap.user = identity;
    if (open && !started && identity.identityToken) void start();
    else if (started) void recoverHistory();
  }
  function configure(
    next: SiteContext & {
      accountPending?: boolean;
      accountActive?: boolean;
      accountError?: boolean;
    } = {},
  ): void {
    if (
      next.accountActive === false &&
      (identity.subject || localStorage.getItem(`mw-subject:${publicKey}`))
    ) {
      reset(false);
      localStorage.setItem(`mw-subject:${publicKey}`, "");
    }
    context = { ...context, ...next };
    if (next.accountActive !== undefined) accountActive = next.accountActive;
    if (next.accountError !== undefined) accountError = next.accountError;
    if (next.accountPending !== undefined)
      identityPending = next.accountPending;
    if (bootstrap) bootstrap.context = context;
    if (started) void tick().then(recoverHistory);
  }
  function reset(close = true, preserveVisitorResume = false): void {
    generation++;
    loading = false;
    recovering = false;
    identity = {};
    identityPending = false;
    agentTyping = false;
    agentOnline = false;
    connection = "connecting";
    clearTimeout(typingTimer);
    clearTimeout(agentTypingTimer);
    setUnread(0);
    realtime?.close();
    realtime = null;
    session = null;
    messages = [];
    started = false;
    name = "";
    email = "";
    phone = "";
    body = "";
    error = "";
    [
      "session",
      "token",
      "name",
      "email",
      "phone",
      "visitor",
      "subject",
    ].forEach((key) => {
      if (
        preserveVisitorResume &&
        ["session", "token", "visitor"].includes(key)
      )
        return;
      localStorage.removeItem(`mw-${key}:${publicKey}`);
    });
    if (close) setOpen(false);
  }

  async function start(): Promise<void> {
    if (
      !bootstrap ||
      loading ||
      identityPending ||
      (accountActive && !identity.identityToken)
    )
      return;
    const epoch = generation;
    error = "";
    const saved = stored();
    const resuming = !!saved.resume_session && !!saved.resume_token;
    if (
      !identity.identityToken &&
      !resuming &&
      fields.name === "required" &&
      !name.trim()
    ) {
      error = c.nameError;
      return;
    }
    if (
      !identity.identityToken &&
      !resuming &&
      fields.email === "required" &&
      !/^\S+@\S+\.\S+$/.test(email)
    ) {
      error = c.emailError;
      return;
    }
    if (
      !identity.identityToken &&
      !resuming &&
      fields.phone === "required" &&
      !phone.trim()
    ) {
      error = c.phoneError;
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
            identity_token: identity.identityToken,
            locale,
            name:
              fields.name === "hidden" && !identity.identityToken
                ? ""
                : name.trim(),
            email:
              fields.email === "hidden" && !identity.identityToken
                ? ""
                : email.trim(),
            phone:
              fields.phone === "hidden" && !identity.identityToken
                ? ""
                : phone.trim(),
            site_origin: bootstrap.siteOrigin,
            page_url: bootstrap.pageUrl,
            page_title: bootstrap.pageTitle,
            referrer: bootstrap.referrer,
            utm: bootstrap.utm,
          }),
        },
      );
      const data = await response.json();
      if (epoch !== generation) return;
      if (!response.ok) throw new Error(apiError(data.code, locale));
      session = data as SessionData;
      config = session.widget;
      messages = session.messages;
      setUnread(open && !document.hidden ? 0 : unreadFrom(session));
      started = true;
      localStorage.setItem(`mw-session:${publicKey}`, session.session);
      localStorage.setItem(`mw-token:${publicKey}`, session.session_token);
      if (!identity.subject) {
        localStorage.setItem(`mw-name:${publicKey}`, name);
        localStorage.setItem(`mw-email:${publicKey}`, email);
        localStorage.setItem(`mw-phone:${publicKey}`, phone);
      }
      connect();
      markRead();
    } catch (problem) {
      if (epoch === generation)
        error = problem instanceof Error ? problem.message : c.startError;
    } finally {
      if (epoch === generation) loading = false;
    }
  }

  function connect(): void {
    if (!session) return;
    realtime?.close();
    realtime = new RealtimeClient(session, {
      status(value) {
        connection = value;
        if (value !== "online") agentTyping = false;
        if (value === "online") void recoverHistory();
      },
      event(event) {
        if (event.type === "auth.expired") {
          void recoverHistory().then(() => realtime?.connect());
        } else if (event.type === "sync.required") {
          void recoverHistory();
        } else if (event.type === "message.created" && event.message) {
          const message = event.message as ChatMessage;
          const existing = messages.findIndex(
            (item) => item.client_id === message.client_id,
          );
          const isNew = existing < 0;
          messages =
            existing >= 0
              ? messages.map((item, index) =>
                  index === existing
                    ? {
                        ...message,
                        status:
                          item.status === "read"
                            ? "read"
                            : item.status === "delivered" &&
                                message.status === "sent"
                              ? "delivered"
                              : message.status,
                      }
                    : item,
                )
              : [...messages, message];
          if (message.direction !== "visitor") {
            if (open && !document.hidden) realtime?.receipt("read", message);
            else {
              realtime?.receipt("delivered", message);
              if (isNew) setUnread(unread + 1);
            }
            if (isNew) playNotification();
          }
        } else if (
          (event.type === "message.delivered" ||
            event.type === "message.read") &&
          event.message_id &&
          event.role === "agent"
        ) {
          const status = event.type === "message.read" ? "read" : "delivered";
          messages = messages.map((item) =>
            item.id <= Number(event.message_id) &&
            item.direction === "visitor" &&
            item.status !== "read"
              ? { ...item, status }
              : item,
          );
        } else if (event.type === "typing.started" && event.role === "agent") {
          agentTypingName = String(event.name || "Atendimento");
          agentTyping = true;
          clearTimeout(agentTypingTimer);
          agentTypingTimer = window.setTimeout(
            () => (agentTyping = false),
            Math.min(120, Math.max(2, Number(event.expires_in) || 6)) * 1000,
          );
        } else if (event.type === "typing.stopped" && event.role === "agent")
          agentTyping = false;
        else if (event.type === "presence.changed" && event.role === "agent")
          agentOnline = Boolean(event.online);
        else if (event.type === "event.failed" && event.request_id)
          messages = messages.map((item) =>
            item.client_id === event.request_id && item.id < 0
              ? { ...item, status: "failed" }
              : item,
          );
      },
    });
    realtime.connect();
  }

  async function recoverHistory(): Promise<void> {
    if (!bootstrap || !session || recovering) return;
    recovering = true;
    const epoch = generation;
    const previousIncomingId = messages.reduce(
      (latest, message) =>
        message.direction === "visitor" ? latest : Math.max(latest, message.id),
      0,
    );
    try {
      const response = await fetch(
        `/chat/api/${encodeURIComponent(publicKey)}/sessions`,
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({
            ...stored(),
            visitor_id: visitorId(),
            identity_token: identity.identityToken,
            locale,
            name:
              fields.name === "hidden" && !identity.identityToken
                ? ""
                : name.trim(),
            email:
              fields.email === "hidden" && !identity.identityToken
                ? ""
                : email.trim(),
            phone:
              fields.phone === "hidden" && !identity.identityToken
                ? ""
                : phone.trim(),
            site_origin: bootstrap.siteOrigin,
            page_url: bootstrap.pageUrl,
            page_title: bootstrap.pageTitle,
            referrer: bootstrap.referrer,
            utm: bootstrap.utm,
          }),
        },
      );
      if (!response.ok) return;
      const refreshed = (await response.json()) as SessionData;
      if (epoch !== generation) return;
      const pending = messages.filter(
        (message) =>
          message.id < 0 &&
          !refreshed.messages.some(
            (candidate) => candidate.client_id === message.client_id,
          ),
      );
      const latestIncomingId = refreshed.messages.reduce(
        (latest, message) =>
          message.direction === "visitor"
            ? latest
            : Math.max(latest, message.id),
        0,
      );
      session = refreshed;
      localStorage.setItem(`mw-session:${publicKey}`, refreshed.session);
      localStorage.setItem(`mw-token:${publicKey}`, refreshed.session_token);
      config = refreshed.widget;
      messages = [...refreshed.messages, ...pending];
      realtime?.updateSession(refreshed);
      setUnread(open && !document.hidden ? 0 : unreadFrom(refreshed));
      if (latestIncomingId > previousIncomingId) {
        playNotification();
      }
      markRead();
    } catch {
      // The SSE reconnect will request durable history again on reconnect.
    } finally {
      if (epoch === generation) recovering = false;
    }
  }

  async function send(): Promise<void> {
    unlockSound();
    const epoch = generation;
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
    const page = {
      page_url: bootstrap?.pageUrl,
      page_title: bootstrap?.pageTitle,
      locale,
    };
    if (realtime?.sendMessage(text, clientId, page)) return;
    try {
      const response = await fetch(
        `/chat/api/sessions/${session.session}/messages`,
        {
          method: "POST",
          headers: {
            "content-type": "application/json",
            authorization: `Bearer ${session.session_token}`,
          },
          body: JSON.stringify({ body: text, client_id: clientId, ...page }),
        },
      );
      const data = await response.json();
      if (epoch !== generation) return;
      if (!response.ok)
        throw new Error(apiError(data.code, locale, "sendError"));
      messages = messages.map((item) =>
        item.client_id === clientId ? data.message : item,
      );
    } catch {
      if (epoch !== generation) return;
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
    copy[locale][message.status] || message.status;
  const statusIcon = (message: ChatMessage): string =>
    ({ pending: "◷", sent: "✓", delivered: "✓✓", read: "✓✓", failed: "!" })[
      message.status
    ] || "";
  const time = (value: string) =>
    new Intl.DateTimeFormat(locale, {
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(value));

  onMount(() => {
    const onMessage = (event: MessageEvent) => {
      if (event.source !== window.parent) return;
      if (event.data?.type === "webchat.bootstrap") {
        parentOrigin = event.origin;
        bootstrap = {
          siteOrigin: event.origin,
          pageUrl: String(event.data.pageUrl || ""),
          pageTitle: String(event.data.pageTitle || ""),
          referrer: String(event.data.referrer || ""),
          utm: event.data.utm || {},
          user: event.data.user || {},
          message: String(event.data.message || ""),
          context: event.data.context || {},
        };
        configure(bootstrap.context);
        if (bootstrap.user?.identityToken || bootstrap.user?.subject)
          identify(bootstrap.user);
        if (bootstrap.message) body = bootstrap.message;

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
      else if (event.data.action === "configure") {
        if (bootstrap) {
          bootstrap.pageUrl = String(event.data.pageUrl || bootstrap.pageUrl);
          bootstrap.pageTitle = String(
            event.data.pageTitle ?? bootstrap.pageTitle ?? "",
          );
          bootstrap.utm = event.data.utm || bootstrap.utm;
        }
        configure(event.data.context);
      } else if (event.data.action === "reset") reset();
    };
    const visible = () => {
      if (!document.hidden && started) void recoverHistory();
      markRead();
    };
    const changedAccount = (event: StorageEvent) => {
      if (
        event.key === `mw-subject:${publicKey}` &&
        (event.newValue || "") !== (identity.subject || "")
      )
        reset();
    };
    window.addEventListener("storage", changedAccount);
    window.addEventListener("message", onMessage);
    window.addEventListener("focus", visible);
    document.addEventListener("visibilitychange", visible);
    tellParent({ type: "webchat.ready" });
    return () => {
      window.removeEventListener("storage", changedAccount);
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
    clearTimeout(agentTypingTimer);
    if (audioContext) void audioContext.close();
  });
</script>

{#if !open}
  <button
    class="launcher"
    on:click={toggle}
    aria-label={unread ? `${c.open}, ${unread} ${c.unread}` : c.open}
  >
    <svg viewBox="0 0 24 24" aria-hidden="true"
      ><path
        d="M20 11.5a7.5 7.5 0 0 1-8 7.48 8.6 8.6 0 0 1-3.7-.82L4 20l1.37-3.66A7.5 7.5 0 1 1 20 11.5Z"
      /></svg
    >
    {#if unread}<span class="unread">{unread > 99 ? "99+" : unread}</span>{/if}
  </button>
{:else}
  <section
    class="panel"
    class:filled={theme.options.header === "filled"}
    class:minimal={theme.options.header === "minimal"}
    class:compact={theme.options.density === "compact"}
    class:with-context={Boolean(bootstrap?.pageTitle)}
    aria-label={c.support}
  >
    <header>
      <div class="brand">
        <span class="brand-mark"
          >{#if logo}<img
              src={logo}
              alt=""
              on:error={() => (logo = "")}
            />{:else}{brandInitial}{/if}</span
        >
        <div>
          <strong>{brandName}</strong><small
            ><i class:online={connection === "online"}></i>{!started
              ? c.ready
              : connection === "online"
                ? agentOnline
                  ? c.online
                  : c.connected
                : connection === "connecting"
                  ? c.connecting
                  : c.reconnecting}</small
          >
        </div>
      </div>
      <button class="close" on:click={toggle} aria-label={c.minimize}>−</button>
    </header>
    {#if bootstrap?.pageTitle}
      <div class="page-context" title={bootstrap.pageUrl}>
        <span>{c.currentPage}</span>
        {bootstrap.pageTitle}
      </div>
    {/if}
    {#if !started}
      <div class="welcome">
        <div class="welcome-icon">
          {#if logo}<img src={logo} alt="" />{:else}{brandInitial}{/if}
        </div>
        <h1>{greeting}</h1>
        <p>{c.sub}</p>
        {#if identity.identityToken || identityPending || accountActive}
          <p class="account">
            {accountError
              ? c.identityError
              : loading || identityPending
                ? c.starting
                : c.account}
          </p>
          {#if accountError}<button
              class="primary"
              on:click={() => tellParent({ type: "webchat.identity.refresh" })}
              >{c.start}</button
            >{/if}
          {#if error}<div class="error" role="alert">{error}</div>
            <button class="primary" on:click={start}>{c.start}</button>{/if}
        {:else}
          <form
            class:two-columns={theme.options.formLayout === "grid"}
            on:submit|preventDefault={start}
          >
            {#if fields.name !== "hidden"}<label
                >{c.name}
                {fields.name === "required" ? "" : `(${c.optional})`}<input
                  bind:value={name}
                  type="text"
                  autocomplete="name"
                  maxlength="120"
                  required={fields.name === "required"}
                  placeholder={c.namePlaceholder}
                /></label
              >{/if}
            {#if fields.email !== "hidden"}<label
                >{c.email}
                {fields.email === "required" ? "" : `(${c.optional})`}<input
                  bind:value={email}
                  type="email"
                  autocomplete="email"
                  maxlength="190"
                  required={fields.email === "required"}
                  placeholder={c.emailPlaceholder}
                /></label
              >{/if}
            {#if fields.phone !== "hidden"}<label
                >{c.phone}
                {fields.phone === "required" ? "" : `(${c.optional})`}<input
                  bind:value={phone}
                  type="tel"
                  autocomplete="tel"
                  maxlength="50"
                  required={fields.phone === "required"}
                  placeholder={c.phonePlaceholder}
                /></label
              >{/if}
            {#if error}<div class="error" role="alert">{error}</div>{/if}
            <button class="primary" type="submit" disabled={loading}
              >{loading ? c.starting : c.start}</button
            >
          </form>{/if}
        <small class="privacy">{c.privacy}</small>
      </div>
    {:else}
      <div class="messages" bind:this={list} aria-live="polite">
        <div class="day">{c.today}</div>
        {#if !messages.length}<div class="greeting">
            <span class="agent-avatar">{brandInitial}</span>
            <div>
              <strong>{c.team} {brandName}</strong>
              <p>{greeting}</p>
            </div>
          </div>{/if}
        {#each messages as message (message.client_id)}
          <article class:mine={message.direction === "visitor"} class="message">
            {#if message.direction !== "visitor"}<small class="author"
                >{message.author || `${c.team} ${brandName}`}</small
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
              >{agentTypingName} {c.typing}</em
            >
          </div>{/if}
      </div>
      <form class="composer" on:submit|preventDefault={send}>
        {#if connection === "offline"}<div class="offline">
            {offlineMessage}
          </div>{/if}
        <textarea
          bind:value={body}
          on:input={typed}
          on:blur={stopTyping}
          rows="1"
          maxlength="4000"
          placeholder={c.composer}
          aria-label={c.message}
          on:focus={unlockSound}
          on:keydown={(event) => {
            if (event.key === "Enter" && !event.shiftKey) {
              event.preventDefault();
              void send();
            }
          }}
        ></textarea>
        <button type="submit" disabled={!body.trim()} aria-label={c.send}
          ><svg viewBox="0 0 24 24"
            ><path
              d="m3 3 18 9-18 9 3.5-9L3 3Zm3.7 8h7.8L6 6.75 6.7 11Zm0 2L6 17.25 14.5 13H6.7Z"
            /></svg
          ></button
        >
      </form>
      {#if theme.options.showFooter}<footer>{c.footer} {brandName}</footer>{/if}
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
    font-family: var(--wc-font, system-ui);
    font-size: var(--wc-font-size, 14px);
    color: var(--wc-text);
    -webkit-text-size-adjust: 100%;
  }
  .launcher {
    position: absolute;
    right: 20px;
    bottom: 20px;
    width: 64px;
    height: 64px;
    border: 0;
    border-radius: var(--wc-radius);
    background: var(--wc-accent);
    color: var(--wc-button-text);
    display: grid;
    place-items: center;
    cursor: pointer;
    box-shadow: 0 9px 22px color-mix(in srgb, var(--wc-accent) 30%, transparent);
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
    border-radius: var(--wc-control-radius);
    background: #d63d53;
    font: 700 12px/18px system-ui;
  }
  .panel {
    width: 100%;
    height: 100%;
    min-height: 0;
    display: grid;
    grid-template-rows: auto 1fr auto auto;
    border: 1px solid var(--wc-divider);
    border-radius: var(--wc-radius);
    background: var(--wc-surface);
    overflow: hidden;
    box-shadow: var(--wc-shadow);
  }
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 16px;
    background: var(--wc-surface);
    color: var(--wc-button-text);
  }
  .panel.with-context {
    grid-template-rows: auto auto minmax(0, 1fr) auto auto;
  }
  .page-context {
    padding: 8px 16px;
    border-bottom: 1px solid var(--wc-divider);
    color: var(--wc-muted);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .page-context span {
    color: var(--wc-text);
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
    background: var(--wc-surface);
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
    background: var(--wc-muted);
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
    color: var(--wc-button-text);
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
  }
  .welcome {
    padding: var(--wc-spacing);
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
    font-size: var(--wc-heading-size, 18px);
    line-height: 1.3;
    margin: 17px 0 5px;
  }
  .welcome > p {
    margin: 0 0 22px;
    color: var(--wc-muted);
    font-size: 14px;
  }
  .welcome form {
    display: grid;
    gap: 14px;
  }
  .welcome label {
    font-size: 12px;
    font-weight: 700;
    color: var(--wc-muted);
  }
  .welcome input {
    width: 100%;
    height: var(--wc-control-height);
    margin-top: 6px;
    padding: 0 12px;
    border: 1px solid var(--wc-divider);
    border-radius: var(--wc-control-radius);
    font-family: inherit;
    font-size: 16px;
    line-height: 1.35;
  }
  .welcome input:focus {
    border-color: var(--wc-accent);
  }
  .primary {
    font: inherit;
    height: var(--wc-control-height);
    border: 0;
    border-radius: var(--wc-control-radius);
    background: var(--wc-accent);
    color: var(--wc-button-text);
    font-weight: 700;
    cursor: pointer;
  }
  .primary:disabled {
    opacity: 0.55;
  }
  .privacy {
    display: block;
    margin-top: 16px;
    color: var(--wc-muted);
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
    background: var(--wc-background);
    scrollbar-width: thin;
  }
  .day {
    margin: 0 auto 18px;
    width: max-content;
    padding: 4px 9px;
    border-radius: 8px;
    background: var(--wc-agent-bubble);
    color: var(--wc-muted);
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
    color: var(--wc-button-text);
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
    background: var(--wc-surface);
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
    color: var(--wc-muted);
    font-size: 11px;
  }
  .bubble {
    display: inline-block;
    padding: 10px 12px;
    border-radius: 5px 15px 15px 15px;
    background: var(--wc-surface);
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
    color: var(--wc-button-text);
  }
  .message > small:not(.author) {
    display: block;
    margin-top: 4px;
    color: var(--wc-muted);
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
    color: var(--wc-muted);
    font-size: 11px;
  }
  .typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--wc-muted);
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
    border-top: 1px solid var(--wc-divider);
    background: var(--wc-surface);
  }
  .composer textarea {
    display: block;
    width: 100%;
    min-width: 0;
    resize: none;
    min-height: 40px;
    max-height: 92px;
    padding: 10px 12px;
    border: 1px solid var(--wc-divider);
    border-radius: var(--wc-control-radius);
    font-family: inherit;
    font-size: 16px;
    line-height: 1.35;
    touch-action: manipulation;
    -webkit-text-size-adjust: 100%;
  }
  .composer button {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: var(--wc-control-radius);
    background: var(--wc-accent);
    color: var(--wc-button-text);
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
    color: var(--wc-muted);
    font-size: 10px;
    background: var(--wc-surface);
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

  header {
    color: var(--wc-title);
    border-bottom: 1px solid var(--wc-divider);
  }
  .brand small {
    color: var(--wc-muted);
  }
  .filled header {
    background: var(--wc-accent);
    color: var(--wc-button-text);
  }
  .filled .brand small {
    color: inherit;
  }
  .minimal header {
    border-top: 3px solid var(--wc-accent);
  }
  .close {
    background: var(--wc-agent-bubble);
    color: var(--wc-title);
    width: 44px;
    height: 44px;
  }
  .brand-mark {
    background: var(--wc-agent-bubble);
    border-radius: var(--wc-control-radius);
  }
  .welcome-icon {
    background: var(--wc-agent-bubble);
    border-radius: var(--wc-control-radius);
  }
  .welcome-icon img {
    width: 32px;
    height: 32px;
    object-fit: contain;
  }
  .brand-mark img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }
  .welcome input,
  .composer textarea {
    background: var(--wc-background);
    color: var(--wc-text);
    border-radius: var(--wc-control-radius);
  }
  .welcome h1,
  .greeting strong {
    color: var(--wc-title);
  }
  .welcome form {
    container-type: inline-size;
  }
  .welcome form.two-columns {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .welcome .primary,
  .welcome .error {
    grid-column: 1/-1;
  }
  .welcome label {
    min-width: 0;
  }
  .bubble,
  .greeting p {
    background: var(--wc-agent-bubble);
    color: var(--wc-text);
  }
  .mine .bubble {
    color: var(--wc-button-text);
    background: var(--wc-accent);
  }
  .composer {
    grid-template-columns: 1fr 44px;
  }
  @media (pointer: fine) {
    .welcome input,
    .composer textarea {
      font-size: var(--wc-font-size, 14px);
    }
  }
  .composer button {
    width: 44px;
    height: 44px;
  }
  .panel {
    container-type: inline-size;
  }
  @container (max-width:410px) {
    .welcome form.two-columns {
      grid-template-columns: minmax(0, 1fr);
    }
  }
  @media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
      animation: none !important;
      transition: none !important;
    }
  }
</style>

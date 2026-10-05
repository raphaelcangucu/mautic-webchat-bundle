<script lang="ts">
  import { onMount } from "svelte";
  export let root: HTMLElement;
  interface Widget {
    id: number;
    name: string;
    public_key: string;
    published: boolean;
    allowed_domains: string[];
    greeting: string;
    offline_message: string;
    accent_color: string;
    require_name: boolean;
    require_email: boolean;
    ai_agent_key?: string | null;
    asset_id: number;
    asset_name: string;
    embed: string;
    demo_url: string;
    sessions?: number;
  }
  interface Asset {
    id: number;
    name: string;
    type: string;
  }
  interface Agent {
    key: string;
    name: string;
    enabled: boolean;
  }
  const dataUrl = root.dataset.dataUrl || "";
  const saveUrl = root.dataset.saveUrl || "";
  const healthUrl = root.dataset.healthUrl || "";
  let items: Widget[] = [],
    assets: Asset[] = [],
    agents: Agent[] = [],
    selected: Widget | null = null,
    csrf = "";
  let domains = "",
    loading = true,
    saving = false,
    error = "",
    notice = "",
    health: {
      configured?: boolean;
      gateway?: { ok?: boolean; connections?: number };
    } = {};
  const blank = (): Widget => ({
    id: 0,
    name: "Chat Macro Markets",
    public_key: "",
    published: true,
    allowed_domains: ["macro.markets"],
    greeting: "Olá! Como podemos ajudar?",
    offline_message: "Deixe sua mensagem e responderemos assim que possível.",
    accent_color: "#4e5ba6",
    require_name: true,
    require_email: false,
    ai_agent_key: "",
    asset_id: assets[0]?.id || 0,
    asset_name: assets[0]?.name || "",
    embed: "",
    demo_url: "",
    sessions: 0,
  });
  $: previewInitial =
    (selected?.name || "Atendimento")
      .replace(/^chat\s+/i, "")
      .trim()
      .slice(0, 1)
      .toUpperCase() || "A";
  function choose(widget: Widget): void {
    selected = structuredClone(widget);
    domains = widget.allowed_domains.join("\n");
    notice = "";
    error = "";
  }
  async function load(): Promise<void> {
    loading = true;
    try {
      const response = await fetch(dataUrl);
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Falha ao carregar.");
      items = data.items;
      assets = data.assets;
      agents = data.agents;
      csrf = data.csrf;
      if (items[0]) choose(items[0]);
    } catch (problem) {
      error = problem instanceof Error ? problem.message : String(problem);
    } finally {
      loading = false;
    }
  }
  async function checkHealth(): Promise<void> {
    try {
      health = await (await fetch(healthUrl)).json();
    } catch {
      health = { configured: false, gateway: { ok: false } };
    }
  }
  async function save(): Promise<void> {
    if (!selected || saving) return;
    saving = true;
    error = "";
    notice = "";
    try {
      const payload = {
        ...selected,
        allowed_domains: domains
          .split(/[\n,]+/)
          .map((value) => value.trim())
          .filter(Boolean),
      };
      const response = await fetch(
        selected.id ? `${saveUrl}/${selected.id}` : saveUrl,
        {
          method: selected.id ? "PUT" : "POST",
          headers: { "content-type": "application/json", "X-CSRF-Token": csrf },
          body: JSON.stringify(payload),
        },
      );
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || "Falha ao salvar.");
      const saved = data.item as Widget;
      const index = items.findIndex((item) => item.id === saved.id);
      items =
        index < 0
          ? [saved, ...items]
          : items.map((item) => (item.id === saved.id ? saved : item));
      choose(saved);
      notice = "Widget salvo.";
    } catch (problem) {
      error = problem instanceof Error ? problem.message : String(problem);
    } finally {
      saving = false;
    }
  }
  async function disable(): Promise<void> {
    if (
      !selected?.id ||
      !confirm(
        "Desativar este widget? As conversas existentes serão preservadas.",
      )
    )
      return;
    const response = await fetch(`${saveUrl}/${selected.id}`, {
      method: "DELETE",
      headers: { "X-CSRF-Token": csrf },
    });
    if (response.ok) {
      selected.published = false;
      items = items.map((item) =>
        item.id === selected?.id ? { ...item, published: false } : item,
      );
      notice = "Widget desativado.";
    }
  }
  async function copy(): Promise<void> {
    if (!selected?.embed) return;
    await navigator.clipboard.writeText(selected.embed);
    notice = "Código copiado.";
  }
  onMount(() => {
    void load();
    void checkHealth();
  });
</script>

<div class="wc-heading">
  <div>
    <span class="wc-icon">◌</span>
    <div>
      <h2>Web Chat</h2>
      <p>Conecte seu site ao Inbox em tempo real.</p>
    </div>
  </div>
  <div class:ok={health.configured && health.gateway?.ok} class="health">
    <i></i>{health.configured && health.gateway?.ok
      ? `Tempo real ativo${health.gateway.connections ? ` · ${health.gateway.connections} conexões` : ""}`
      : health.configured
        ? "Gateway desconectado"
        : "Tempo real não configurado"}
  </div>
</div>
{#if error}<div class="alert alert-danger">{error}</div>{/if}{#if notice}<div
    class="alert alert-success"
  >
    {notice}
  </div>{/if}
{#if loading}<div class="wc-loading">Carregando widgets…</div>{:else}
  <div class="wc-layout">
    <aside class="wc-list">
      <div class="wc-list-head">
        <strong>Widgets</strong><button
          class="btn btn-primary btn-sm"
          on:click={() => choose(blank())}>Novo</button
        >
      </div>
      {#each items as widget}<button
          class:active={selected?.id === widget.id}
          on:click={() => choose(widget)}
          class="wc-list-item"
          ><span class="list-mark" style={`--accent:${widget.accent_color}`}
            >◌</span
          ><span
            ><strong>{widget.name}</strong><small
              >{widget.allowed_domains[0] || "Sem domínio"}</small
            ></span
          ><em class:live={widget.published}
            >{widget.published ? "Ativo" : "Inativo"}</em
          ><b>{widget.sessions || 0}</b></button
        >{/each}
      {#if !items.length}<div class="wc-empty">
          Crie seu primeiro widget e copie o código para o site.
        </div>{/if}
    </aside>
    {#if selected}<main class="wc-editor">
        <section class="form-pane">
          <div class="section-title">
            <div>
              <h3>{selected.id ? selected.name : "Novo widget"}</h3>
              <p>Identidade, entrada e roteamento do atendimento.</p>
            </div>
            <label class="switch"
              ><input type="checkbox" bind:checked={selected.published} /><span
              ></span>{selected.published ? "Ativo" : "Inativo"}</label
            >
          </div>
          <div class="grid two">
            <label
              >Nome<input
                class="form-control"
                bind:value={selected.name}
                maxlength="120"
              /></label
            ><label
              >Conta do Inbox<select
                class="form-control"
                bind:value={selected.asset_id}
                >{#each assets as asset}<option value={asset.id}
                    >{asset.name}</option
                  >{/each}</select
              ></label
            >
          </div>
          <label
            >Domínios permitidos<textarea
              class="form-control domains"
              bind:value={domains}
              rows="2"
              placeholder="macro.markets\nwww.macro.markets"
            ></textarea><small
              >Um domínio por linha. Subdomínios também serão aceitos.</small
            ></label
          >
          <div class="grid color-row">
            <label
              >Cor de destaque<input
                class="form-control"
                type="color"
                bind:value={selected.accent_color}
              /></label
            ><label
              >Agente inicial<select
                class="form-control"
                bind:value={selected.ai_agent_key}
                ><option value="">Fila sem atribuição</option
                >{#each agents as agent}<option
                    value={agent.key}
                    disabled={!agent.enabled}
                    >{agent.name}{agent.enabled ? "" : " · inativo"}</option
                  >{/each}</select
              ></label
            >
          </div>
          <label
            >Mensagem de boas-vindas<textarea
              class="form-control"
              bind:value={selected.greeting}
              rows="2"
              maxlength="500"
            ></textarea></label
          >
          <label
            >Mensagem quando estiver desconectado<textarea
              class="form-control"
              bind:value={selected.offline_message}
              rows="2"
              maxlength="500"
            ></textarea></label
          >
          <div class="checks">
            <label
              ><input type="checkbox" bind:checked={selected.require_name} /> Pedir
              nome</label
            ><label
              ><input type="checkbox" bind:checked={selected.require_email} /> Pedir
              e-mail</label
            >
          </div>
          {#if selected.id}<div class="install">
              <div>
                <strong>Instalação</strong><span
                  >Cole antes do fechamento de <code>&lt;/body&gt;</code>.</span
                >
              </div>
              <pre>{selected.embed}</pre>
              <div class="install-actions">
                <button class="btn btn-default" on:click={copy}
                  >Copiar código</button
                ><a
                  class="btn btn-default"
                  href={selected.demo_url}
                  target="_blank"
                  rel="noreferrer">Abrir demonstração</a
                >
              </div>
            </div>{/if}
          <div class="actions">
            <button class="btn btn-primary" disabled={saving} on:click={save}
              >{saving ? "Salvando…" : "Salvar widget"}</button
            >{#if selected.id}<button
                class="btn btn-default danger"
                on:click={disable}>Desativar</button
              >{/if}
          </div>
        </section>
        <aside class="preview-pane">
          <div class="preview-title">
            <strong>Prévia</strong><span>Desktop</span>
          </div>
          <div class="site-preview">
            <div class="site-lines"><i></i><i></i><i></i></div>
            <div
              class="preview-chat"
              style={`--accent:${selected.accent_color}`}
            >
              <header>
                <span>{previewInitial}</span>
                <div>
                  <strong>{selected.name || "Atendimento"}</strong><small
                    ><i></i> Conectado</small
                  >
                </div>
                <b>−</b>
              </header>
              <div class="preview-body">
                <small>Hoje</small>
                <article>
                  <em>{previewInitial}</em>
                  <p>{selected.greeting || "Olá! Como podemos ajudar?"}</p>
                </article>
                <article class="mine">
                  <p>Olá! Gostaria de conversar com a equipe.</p>
                  <small>14:32 · ✓✓ Lida</small>
                </article>
                <div class="typing">
                  <i></i><i></i><i></i> Atendimento está digitando…
                </div>
              </div>
              <footer>Escreva uma mensagem <button>➤</button></footer>
            </div>
          </div>
        </aside>
      </main>{/if}
  </div>{/if}

<style>
  :global(#app-content) {
    background: #f6f7fa;
  }
  .wc-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 0 0 18px;
    padding: 18px 20px;
    border: 1px solid var(--border, #dfe3eb);
    border-radius: 12px;
    background: #fff;
  }
  .wc-heading > div:first-child {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .wc-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #eef0fb;
    color: #4e5ba6;
    font-size: 26px;
  }
  .wc-heading h2 {
    margin: 0;
    font-size: 20px;
  }
  .wc-heading p {
    margin: 3px 0 0;
    color: #768094;
  }
  .health {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 10px;
    border-radius: 9px;
    background: #fff4dc;
    color: #7d5b1c;
    font-size: 12px;
    font-weight: 700;
  }
  .health i {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #e0a22e;
  }
  .health.ok {
    background: #eaf7f0;
    color: #28704b;
  }
  .health.ok i {
    background: #36a36d;
  }
  .wc-layout {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    min-height: 700px;
    border: 1px solid #dfe3eb;
    border-radius: 12px;
    background: white;
    overflow: hidden;
  }
  .wc-list {
    border-right: 1px solid #e3e6ed;
    background: #fbfcfd;
  }
  .wc-list-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid #e3e6ed;
  }
  .wc-list-item {
    position: relative;
    width: 100%;
    display: grid;
    grid-template-columns: 38px 1fr auto;
    gap: 10px;
    align-items: center;
    padding: 14px 13px;
    border: 0;
    border-bottom: 1px solid #eceef3;
    background: transparent;
    text-align: left;
    color: #252b3a;
    cursor: pointer;
  }
  .wc-list-item:hover,
  .wc-list-item.active {
    background: #f0f2fb;
  }
  .wc-list-item.active:before {
    content: "";
    position: absolute;
    left: 0;
    top: 8px;
    bottom: 8px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: #4e5ba6;
  }
  .list-mark {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 11px;
    background: color-mix(in srgb, var(--accent) 12%, white);
    color: var(--accent);
    font-size: 22px;
  }
  .wc-list-item strong,
  .wc-list-item small {
    display: block;
  }
  .wc-list-item small {
    margin-top: 3px;
    color: #7b8395;
    font-size: 11px;
  }
  .wc-list-item em {
    grid-column: 3;
    font-style: normal;
    font-size: 10px;
    color: #8d94a3;
  }
  .wc-list-item em.live {
    color: #2e875b;
  }
  .wc-list-item b {
    grid-column: 3;
    color: #979dac;
    font-size: 10px;
  }
  .wc-empty {
    padding: 28px 18px;
    color: #7b8395;
    text-align: center;
  }
  .wc-editor {
    display: grid;
    grid-template-columns: minmax(430px, 1fr) 390px;
    min-width: 0;
  }
  .form-pane {
    padding: 24px;
    overflow: auto;
  }
  .preview-pane {
    padding: 20px;
    border-left: 1px solid #e3e6ed;
    background: #f7f8fb;
  }
  .section-title,
  .preview-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 22px;
  }
  .section-title h3 {
    margin: 0;
    font-size: 19px;
  }
  .section-title p {
    margin: 4px 0 0;
    color: #7b8395;
  }
  .grid {
    display: grid;
    gap: 14px;
  }
  .grid.two {
    grid-template-columns: 1fr 1fr;
  }
  .grid.color-row {
    grid-template-columns: 130px 1fr;
  }
  label {
    display: block;
    margin-bottom: 15px;
    color: #4d5567;
    font-size: 12px;
    font-weight: 700;
  }
  label .form-control {
    margin-top: 6px;
  }
  .form-control {
    border-radius: 8px;
  }
  .form-control[type="color"] {
    height: 39px;
    padding: 4px;
  }
  .domains {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 12px;
  }
  label small {
    display: block;
    margin-top: 5px;
    color: #8b92a0;
    font-weight: 400;
  }
  .checks {
    display: flex;
    gap: 22px;
    padding: 4px 0 17px;
  }
  .checks label {
    margin: 0;
  }
  .switch {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
  }
  .switch input {
    position: absolute;
    opacity: 0;
  }
  .switch span {
    width: 38px;
    height: 22px;
    border-radius: 12px;
    background: #c5cad4;
    position: relative;
  }
  .switch span:after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    left: 3px;
    top: 3px;
    border-radius: 50%;
    background: white;
    transition: 0.18s;
  }
  .switch input:checked + span {
    background: #4e5ba6;
  }
  .switch input:checked + span:after {
    left: 19px;
  }
  .install {
    margin: 7px 0 20px;
    padding: 15px;
    border: 1px solid #dfe3eb;
    border-radius: 10px;
    background: #f9fafc;
  }
  .install > div:first-child {
    display: flex;
    justify-content: space-between;
  }
  .install span {
    color: #7b8395;
    font-size: 11px;
  }
  .install pre {
    margin: 12px 0;
    padding: 11px;
    border: 1px solid #e1e4eb;
    border-radius: 7px;
    background: white;
    white-space: pre-wrap;
    word-break: break-all;
    font-size: 11px;
  }
  .install-actions,
  .actions {
    display: flex;
    gap: 9px;
  }
  .actions {
    padding-top: 4px;
  }
  .danger {
    color: #a83245;
  }
  .preview-title span {
    color: #81899a;
    font-size: 11px;
  }
  .site-preview {
    position: relative;
    height: 610px;
    padding: 24px 14px;
    border: 1px solid #dfe3eb;
    border-radius: 12px;
    background: white;
    overflow: hidden;
  }
  .site-lines {
    display: grid;
    gap: 10px;
  }
  .site-lines i {
    height: 8px;
    border-radius: 4px;
    background: #eef0f4;
  }
  .site-lines i:nth-child(2) {
    width: 72%;
  }
  .site-lines i:nth-child(3) {
    width: 45%;
  }
  .preview-chat {
    position: absolute;
    right: 12px;
    bottom: 12px;
    width: 330px;
    height: 500px;
    display: grid;
    grid-template-rows: auto 1fr auto;
    border: 1px solid #dce0e9;
    border-radius: 18px;
    overflow: hidden;
    background: white;
    box-shadow: 0 17px 45px rgba(30, 39, 70, 0.18);
  }
  .preview-chat header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px;
    background: var(--accent);
    color: white;
  }
  .preview-chat header > span {
    display: grid;
    place-items: center;
    width: 35px;
    height: 35px;
    border-radius: 11px;
    background: white;
    color: var(--accent);
    font-weight: 800;
  }
  .preview-chat header div {
    flex: 1;
  }
  .preview-chat header strong,
  .preview-chat header small {
    display: block;
  }
  .preview-chat header small {
    margin-top: 2px;
    color: rgba(255, 255, 255, 0.8);
    font-size: 10px;
  }
  .preview-chat header small i {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #6de0a8;
  }
  .preview-chat header b {
    font-size: 20px;
  }
  .preview-body {
    padding: 17px 13px;
    background: #f7f8fb;
  }
  .preview-body > small {
    display: block;
    margin: auto;
    width: max-content;
    padding: 3px 7px;
    border-radius: 6px;
    background: #e8ebf2;
    color: #81899a;
  }
  .preview-body article {
    display: flex;
    gap: 7px;
    margin-top: 16px;
  }
  .preview-body article em {
    display: grid;
    place-items: center;
    width: 25px;
    height: 25px;
    border-radius: 8px;
    background: var(--accent);
    color: white;
    font-style: normal;
    font-size: 10px;
  }
  .preview-body article p {
    max-width: 220px;
    margin: 0;
    padding: 9px 10px;
    border-radius: 4px 12px 12px 12px;
    background: white;
    font-size: 12px;
  }
  .preview-body article.mine {
    display: block;
    margin-left: auto;
    text-align: right;
  }
  .preview-body article.mine p {
    display: inline-block;
    border-radius: 12px 4px 12px 12px;
    background: var(--accent);
    color: white;
    text-align: left;
  }
  .preview-body article.mine small {
    display: block;
    color: #777f90;
    font-size: 9px;
  }
  .typing {
    margin-top: 18px;
    color: #7b8395;
    font-size: 10px;
  }
  .typing i {
    display: inline-block;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #8f97a7;
  }
  .preview-chat footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px;
    color: #8a91a0;
    font-size: 11px;
  }
  .preview-chat footer button {
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 9px;
    background: var(--accent);
    color: white;
  }
  @media (max-width: 1100px) {
    .wc-editor {
      grid-template-columns: 1fr;
    }
    .preview-pane {
      display: none;
    }
  }
  @media (max-width: 760px) {
    .wc-heading {
      align-items: flex-start;
      gap: 12px;
    }
    .health {
      max-width: 160px;
    }
    .wc-layout {
      grid-template-columns: 1fr;
    }
    .wc-list {
      border-right: 0;
    }
    .wc-editor {
      display: block;
    }
    .grid.two,
    .grid.color-row {
      grid-template-columns: 1fr;
    }
    .form-pane {
      padding: 18px;
    }
  }
</style>

import type { ChatMessage, SessionData } from "./types";

export interface RealtimeHandlers {
  event(event: Record<string, unknown>): void;
  status(status: "connecting" | "online" | "offline"): void;
}

export class RealtimeClient {
  private socket: WebSocket | null = null;
  private retry = 0;
  private timer = 0;
  private heartbeat = 0;
  private lastPong = 0;
  constructor(
    private session: SessionData,
    private handlers: RealtimeHandlers,
  ) {}
  connect(): void {
    this.close();
    this.handlers.status("connecting");
    const separator = this.session.realtime.url.includes("?") ? "&" : "?";
    this.socket = new WebSocket(
      `${this.session.realtime.url}${separator}token=${encodeURIComponent(this.session.realtime.token)}`,
    );
    this.socket.onopen = () => {
      this.retry = 0;
      this.lastPong = Date.now();
      this.handlers.status("online");
      this.startHeartbeat();
    };
    this.socket.onmessage = (message) => {
      try {
        const event = JSON.parse(message.data);
        if (event.type === "pong" || event.type === "connection.ready") {
          this.lastPong = Date.now();
        }
        this.handlers.event(event);
      } catch {
        /* ignored */
      }
    };
    this.socket.onclose = () => {
      window.clearInterval(this.heartbeat);
      this.handlers.status("offline");
      this.timer = window.setTimeout(
        () => this.connect(),
        Math.min(1000 * 2 ** this.retry++, 15000),
      );
    };
    this.socket.onerror = () => this.socket?.close();
  }
  updateSession(session: SessionData): void {
    this.session = session;
  }
  send(event: Record<string, unknown>): boolean {
    if (this.socket?.readyState !== WebSocket.OPEN) return false;
    this.socket.send(JSON.stringify(event));
    return true;
  }
  typing(active: boolean, name: string): void {
    this.send({ type: active ? "typing.started" : "typing.stopped", name });
  }
  sendMessage(body: string, clientId: string): boolean {
    return this.send({
      type: "message.send",
      body,
      client_id: clientId,
      request_id: clientId,
    });
  }
  receipt(kind: "delivered" | "read", message: ChatMessage): void {
    this.send({
      type: `message.${kind}`,
      message_id: message.id,
      request_id: `${kind}-${message.id}`,
    });
  }
  close(): void {
    clearTimeout(this.timer);
    window.clearInterval(this.heartbeat);
    if (this.socket) {
      this.socket.onclose = null;
      this.socket.close();
    }
    this.socket = null;
  }

  private startHeartbeat(): void {
    window.clearInterval(this.heartbeat);
    this.heartbeat = window.setInterval(() => {
      if (!this.socket || this.socket.readyState !== WebSocket.OPEN) return;
      if (Date.now() - this.lastPong > 45_000) {
        this.socket.close();
        return;
      }
      this.socket.send(JSON.stringify({ type: "ping" }));
    }, 20_000);
  }
}

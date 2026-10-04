import type { ChatMessage, SessionData } from "./types";

export interface RealtimeHandlers {
  event(event: Record<string, unknown>): void;
  status(status: "connecting" | "online" | "offline"): void;
}

export class RealtimeClient {
  private socket: WebSocket | null = null;
  private retry = 0;
  private timer = 0;
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
      this.handlers.status("online");
    };
    this.socket.onmessage = (message) => {
      try {
        this.handlers.event(JSON.parse(message.data));
      } catch {
        /* ignored */
      }
    };
    this.socket.onclose = () => {
      this.handlers.status("offline");
      this.timer = window.setTimeout(
        () => this.connect(),
        Math.min(1000 * 2 ** this.retry++, 15000),
      );
    };
    this.socket.onerror = () => this.socket?.close();
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
    if (this.socket) {
      this.socket.onclose = null;
      this.socket.close();
    }
    this.socket = null;
  }
}

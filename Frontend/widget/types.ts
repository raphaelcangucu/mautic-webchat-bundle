export interface WidgetConfig {
  name: string;
  greeting: string;
  offline_message: string;
  accent_color: string;
  require_name: boolean;
  require_email: boolean;
}
export interface ChatMessage {
  id: number;
  client_id: string;
  direction: "visitor" | "agent" | "ai" | "system";
  body: string;
  status: "pending" | "sent" | "delivered" | "read" | "failed";
  author?: string;
  timestamp: string;
}
export interface SessionData {
  session: string;
  session_token: string;
  visitor_last_read_message_id?: number | null;
  realtime: { token: string; url: string; expires_at: string };
  widget: WidgetConfig;
  messages: ChatMessage[];
}
export interface Bootstrap {
  siteOrigin: string;
  pageUrl: string;
  referrer: string;
  utm: Record<string, string>;
  user?: { name?: string; email?: string };
  message?: string;
}

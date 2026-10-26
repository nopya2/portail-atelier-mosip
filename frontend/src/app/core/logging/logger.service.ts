import { Injectable, OnDestroy } from '@angular/core';
import { environment } from '../../../environments/environment';

export type LogLevel = 'debug' | 'info' | 'warning' | 'error' | 'critical';

export interface LogEntry {
  level: LogLevel;
  message: string;
  type?: 'js_error' | 'http_error' | 'unhandled_rejection' | 'manual';
  name?: string;
  stack?: string;
  url?: string;
  http?: { method?: string; url?: string; status?: number; request_id?: string | null };
  context?: Record<string, unknown>;
  timestamp?: string;
  session_id?: string;
  app_version?: string;
}

const LEVEL_ORDER: Record<LogLevel, number> = { debug: 0, info: 1, warning: 2, error: 3, critical: 4 };
const ENDPOINT = `${environment.apiUrl}/logs`;
const MAX_BATCH = 10;
const FLUSH_DELAY_MS = 2000;
const DEDUP_WINDOW_MS = 10_000;
const MAX_QUEUE = 100;

/**
 * Logger frontend : écrit dans la console et remonte les erreurs, par lots,
 * au journal centralisé du backend (POST /api/logs).
 *
 * L'envoi utilise fetch() (et non HttpClient) pour ne pas repasser par les
 * intercepteurs — une erreur d'envoi ne doit jamais générer un nouveau log.
 */
@Injectable({ providedIn: 'root' })
export class LoggerService implements OnDestroy {
  private queue: LogEntry[] = [];
  private timer: ReturnType<typeof setTimeout> | null = null;
  private recent = new Map<string, number>();
  private readonly sessionId = this.createSessionId();
  private readonly onPageHide = () => this.flush(true);

  constructor() {
    window.addEventListener('pagehide', this.onPageHide);
  }

  ngOnDestroy(): void {
    window.removeEventListener('pagehide', this.onPageHide);
    this.flush(true);
  }

  debug(message: string, context?: Record<string, unknown>): void {
    this.log({ level: 'debug', message, context, type: 'manual' });
  }

  info(message: string, context?: Record<string, unknown>): void {
    this.log({ level: 'info', message, context, type: 'manual' });
  }

  warn(message: string, context?: Record<string, unknown>): void {
    this.log({ level: 'warning', message, context, type: 'manual' });
  }

  error(message: string, error?: unknown, context?: Record<string, unknown>): void {
    const err = error instanceof Error ? error : undefined;
    this.log({
      level: 'error',
      message: err && !message ? err.message : message,
      type: 'manual',
      name: err?.name,
      stack: err?.stack,
      context: { ...context, ...(error && !err ? { error: this.safe(error) } : {}) },
    });
  }

  log(entry: LogEntry): void {
    const full: LogEntry = {
      ...entry,
      url: entry.url ?? location.pathname + location.search,
      timestamp: new Date().toISOString(),
      session_id: this.sessionId,
      app_version: environment.appVersion,
    };

    this.toConsole(full);

    if (!environment.remoteLogging || LEVEL_ORDER[full.level] < LEVEL_ORDER[environment.remoteLogLevel]) {
      return;
    }
    if (this.isDuplicate(full)) {
      return;
    }

    this.queue.push(full);
    if (this.queue.length > MAX_QUEUE) {
      this.queue.splice(0, this.queue.length - MAX_QUEUE);
    }

    if (this.queue.length >= MAX_BATCH || LEVEL_ORDER[full.level] >= LEVEL_ORDER.critical) {
      this.flush();
    } else if (!this.timer) {
      this.timer = setTimeout(() => this.flush(), FLUSH_DELAY_MS);
    }
  }

  /** Envoie la file d'attente. `unloading` : page en cours de fermeture → sendBeacon. */
  flush(unloading = false): void {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (!this.queue.length) {
      return;
    }

    const batch = this.queue.splice(0, 20);
    const body = JSON.stringify({ entries: batch });

    if (unloading && navigator.sendBeacon) {
      navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
    } else {
      const token = sessionStorage.getItem('portail.token');
      fetch(ENDPOINT, {
        method: 'POST',
        keepalive: true,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body,
      }).catch((e) => console.warn('[logger] envoi impossible, entrées perdues', e));
    }

    if (this.queue.length) {
      this.timer = setTimeout(() => this.flush(), FLUSH_DELAY_MS);
    }
  }

  private isDuplicate(entry: LogEntry): boolean {
    const key = `${entry.level}|${entry.message}|${entry.http?.url ?? ''}`;
    const now = Date.now();
    for (const [k, t] of this.recent) {
      if (now - t > DEDUP_WINDOW_MS) this.recent.delete(k);
    }
    if (this.recent.has(key)) {
      return true;
    }
    this.recent.set(key, now);
    return false;
  }

  private toConsole(entry: LogEntry): void {
    const args = [`[${entry.level}] ${entry.message}`, entry.context ?? '', entry.http ?? ''];
    switch (entry.level) {
      case 'debug':
      case 'info':
        console.info(...args);
        break;
      case 'warning':
        console.warn(...args);
        break;
      default:
        console.error(...args, entry.stack ?? '');
    }
  }

  private safe(value: unknown): unknown {
    try {
      return JSON.parse(JSON.stringify(value));
    } catch {
      return String(value);
    }
  }

  private createSessionId(): string {
    const existing = sessionStorage.getItem('portail.session');
    if (existing) return existing;
    const id = crypto.randomUUID();
    sessionStorage.setItem('portail.session', id);
    return id;
  }
}

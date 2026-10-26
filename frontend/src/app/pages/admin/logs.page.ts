import { DatePipe, JsonPipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { AppLog, Paginated } from '../../core/models';
import { LoggerService } from '../../core/logging/logger.service';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-logs',
  imports: [ReactiveFormsModule, DatePipe, JsonPipe],
  template: `
    <div class="page-header">
      <div>
        <h1>Journal des erreurs</h1>
        <p class="muted">Erreurs captées côté backend (Laravel) et frontend (Angular), corrélées par identifiant de requête.</p>
      </div>
      <div class="row">
        <button class="btn btn-secondary" type="button" (click)="testFrontend()">Tester une erreur frontend</button>
        <button class="btn btn-secondary" type="button" (click)="testBackend()">Tester une erreur backend</button>
      </div>
    </div>

    @if (stats(); as st) {
      <div class="stats">
        <div class="card"><strong>{{ st.total }}</strong><span class="muted small">entrées (24 h)</span></div>
        @for (src of ['backend', 'frontend']; track src) {
          <div class="card">
            <strong>{{ sum(st.par_source[src]) }}</strong>
            <span class="muted small">{{ src }} · {{ st.par_source[src]?.['error'] ?? 0 }} erreurs</span>
          </div>
        }
      </div>
    }

    <form class="card filters" [formGroup]="filters" (ngSubmit)="load(1)">
      <select formControlName="source">
        <option value="">Toutes sources</option>
        <option value="backend">Backend</option>
        <option value="frontend">Frontend</option>
      </select>
      <select formControlName="level">
        <option value="">Tous niveaux</option>
        <option value="warning">≥ warning</option>
        <option value="error">≥ error</option>
        <option value="critical">≥ critical</option>
      </select>
      <input formControlName="q" placeholder="Message, exception, URL…" />
      <input formControlName="request_id" placeholder="Identifiant de requête" class="mono" />
      <button class="btn btn-primary" type="submit">Filtrer</button>
      <button class="btn btn-ghost" type="button" (click)="reset()">Réinitialiser</button>
    </form>

    @if (error()) {
      <div class="alert error">{{ error() }}</div>
    }

    <div class="layout">
      <div class="card table-wrap">
        <table class="table">
          <thead><tr><th>Date</th><th>Source</th><th>Niveau</th><th>Message</th><th>Usager</th></tr></thead>
          <tbody>
            @for (l of page()?.data ?? []; track l.id) {
              <tr class="clickable" [class.selected]="selected()?.id === l.id" (click)="open(l.id)">
                <td class="small nowrap">{{ l.created_at | date: 'dd/MM HH:mm:ss' }}</td>
                <td><span class="badge" [class]="l.source">{{ l.source }}</span></td>
                <td><span class="badge" [class]="l.level">{{ l.level }}</span></td>
                <td>
                  <div class="msg">{{ l.message }}</div>
                  @if (l.url) {
                    <div class="small muted mono msg">{{ l.method }} {{ l.url }}</div>
                  }
                </td>
                <td class="small">{{ l.user?.nip ?? '—' }}</td>
              </tr>
            } @empty {
              <tr><td colspan="5" class="empty">Aucune entrée.</td></tr>
            }
          </tbody>
        </table>
        @if (page(); as p) {
          <div class="row pager">
            <button class="btn btn-ghost" type="button" [disabled]="p.current_page <= 1" (click)="load(p.current_page - 1)">←</button>
            <span class="small muted">Page {{ p.current_page }} / {{ p.last_page }} · {{ p.total }} entrées</span>
            <button class="btn btn-ghost" type="button" [disabled]="p.current_page >= p.last_page" (click)="load(p.current_page + 1)">→</button>
          </div>
        }
      </div>

      @if (selected(); as s) {
        <aside class="card detail">
          <div class="row">
            <span class="badge" [class]="s.source">{{ s.source }}</span>
            <span class="badge" [class]="s.level">{{ s.level }}</span>
            <button class="btn btn-ghost close" type="button" (click)="selected.set(null)">✕</button>
          </div>
          <h3>{{ s.message }}</h3>
          <dl class="kv small">
            <dt>Date</dt><dd>{{ s.created_at | date: 'dd/MM/yyyy HH:mm:ss' }}</dd>
            @if (s.exception_class) { <dt>Exception</dt><dd class="mono">{{ s.exception_class }}</dd> }
            @if (s.file) { <dt>Fichier</dt><dd class="mono">{{ s.file }}:{{ s.line }}</dd> }
            @if (s.url) { <dt>URL</dt><dd class="mono">{{ s.method }} {{ s.url }}</dd> }
            @if (s.status_code) { <dt>Statut HTTP</dt><dd>{{ s.status_code }}</dd> }
            @if (s.request_id) {
              <dt>Requête</dt>
              <dd><a href="" class="mono" (click)="$event.preventDefault(); filterRequest(s.request_id)">{{ s.request_id }}</a></dd>
            }
            <dt>Usager</dt><dd>{{ s.user ? s.user.name + ' (' + s.user.nip + ')' : '—' }}</dd>
            <dt>IP</dt><dd>{{ s.ip ?? '—' }}</dd>
          </dl>
          @if (s.context) {
            <h4>Contexte</h4>
            <pre class="code">{{ s.context | json }}</pre>
          }
          @if (s.trace) {
            <h4>Trace</h4>
            <pre class="code">{{ s.trace }}</pre>
          }
          @if (related().length) {
            <h4>Même requête</h4>
            <ul class="small">
              @for (r of related(); track r.id) {
                <li><a href="" (click)="$event.preventDefault(); open(r.id)">[{{ r.source }}/{{ r.level }}] {{ r.message }}</a></li>
              }
            </ul>
          }
        </aside>
      }
    </div>
  `,
  styles: `
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .stats .card { display: flex; flex-direction: column; padding: 0.9rem 1rem; }
    .stats strong { font-size: 1.6rem; }
    .filters { display: grid; grid-template-columns: 160px 150px 1fr 220px auto auto; gap: 0.5rem; margin-bottom: 1rem; padding: 0.75rem; }
    .layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 420px); gap: 1rem; align-items: start; }
    .layout:has(> :only-child) { grid-template-columns: 1fr; }
    .msg { max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .nowrap { white-space: nowrap; }
    tr.selected { background: var(--primary-soft); }
    .pager { justify-content: center; margin-top: 0.5rem; }
    .detail { position: sticky; top: 80px; max-height: calc(100vh - 100px); overflow: auto; }
    .detail h3 { margin-top: 0.75rem; word-break: break-word; }
    .close { margin-left: auto; padding: 0.2rem 0.5rem; }
    @media (max-width: 1000px) {
      .filters, .layout { grid-template-columns: 1fr; }
    }
  `,
})
export class LogsPage {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);
  private readonly logger = inject(LoggerService);

  protected readonly page = signal<Paginated<AppLog> | null>(null);
  protected readonly selected = signal<AppLog | null>(null);
  protected readonly related = signal<AppLog[]>([]);
  protected readonly stats = signal<{ total: number; par_source: Record<string, Record<string, number>> } | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly filters = inject(FormBuilder).nonNullable.group({
    source: [''],
    level: [''],
    q: [''],
    request_id: [''],
  });

  constructor() {
    this.load(1);
  }

  load(pageNumber: number): void {
    this.api.logs({ ...this.filters.getRawValue(), page: pageNumber }).subscribe({
      next: (p) => this.page.set(p),
      error: (e) => this.error.set(apiErrorMessage(e)),
    });
    this.api.logStats().subscribe((s) => this.stats.set(s));
  }

  open(id: number): void {
    this.api.log(id).subscribe(({ log, related }) => {
      this.selected.set(log);
      this.related.set(related);
    });
  }

  reset(): void {
    this.filters.reset();
    this.load(1);
  }

  filterRequest(requestId: string): void {
    this.filters.patchValue({ request_id: requestId, source: '', level: '', q: '' });
    this.load(1);
  }

  sum(values: Record<string, number> | undefined): number {
    return Object.values(values ?? {}).reduce((a, b) => a + Number(b), 0);
  }

  /** Erreur JS non gérée : captée par le GlobalErrorHandler. */
  testFrontend(): void {
    setTimeout(() => this.refreshSoon());
    throw new Error('Erreur de test déclenchée depuis le journal (frontend)');
  }

  /** Exception PHP : captée par le handler Laravel puis Monolog → app_logs. */
  testBackend(): void {
    this.http.post(`${environment.apiUrl}/admin/logs/test`, {}).subscribe({
      error: () => this.refreshSoon(),
    });
  }

  private refreshSoon(): void {
    this.logger.flush();
    setTimeout(() => this.load(1), 800);
  }
}

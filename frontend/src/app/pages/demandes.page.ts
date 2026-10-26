import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../core/api.service';
import { Demande } from '../core/models';

@Component({
  selector: 'app-demandes',
  imports: [DatePipe, RouterLink],
  template: `
    <div class="page-header">
      <div>
        <h1>Mes demandes</h1>
        <p class="muted">Suivi des demandes déposées sur le portail.</p>
      </div>
    </div>

    @if (error()) {
      <div class="alert error">{{ error() }}</div>
    }

    <div class="card table-wrap">
      <table class="table">
        <thead>
          <tr><th>Référence</th><th>Service</th><th>Administration</th><th>Date</th><th>Statut</th></tr>
        </thead>
        <tbody>
          @for (d of demandes(); track d.id) {
            <tr>
              <td class="mono">{{ d.reference }}</td>
              <td><a [routerLink]="['/services', d.service.slug]">{{ d.service.titre }}</a></td>
              <td>{{ d.service.administration }}</td>
              <td>{{ d.created_at | date: 'dd/MM/yyyy HH:mm' }}</td>
              <td><span class="badge" [class]="d.statut">{{ d.statut }}</span></td>
            </tr>
          } @empty {
            <tr><td colspan="5" class="empty">{{ loading() ? 'Chargement…' : 'Aucune demande pour le moment.' }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
})
export class DemandesPage {
  protected readonly demandes = signal<Demande[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  constructor() {
    inject(ApiService).mesDemandes().subscribe({
      next: (d) => {
        this.demandes.set(d);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(apiErrorMessage(e));
        this.loading.set(false);
      },
    });
  }
}

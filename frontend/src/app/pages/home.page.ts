import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../core/api.service';
import { AuthService } from '../core/auth/auth.service';
import { Service } from '../core/models';
import { INFO_LABELS } from '../core/labels';

@Component({
  selector: 'app-home',
  imports: [RouterLink],
  template: `
    <section class="hero card">
      <h1>Vos démarches administratives en ligne</h1>
      <p class="muted">
        Déposez vos demandes auprès des administrations. Votre <strong>NIP</strong>
        (Numéro d'Identification Personnel) sert d'identifiant unique entre les services.
      </p>
      @if (!auth.isAuthenticated()) {
        <div class="row">
          <a routerLink="/inscription" class="btn btn-primary">Créer un compte</a>
          <a routerLink="/connexion" class="btn btn-secondary">Se connecter</a>
        </div>
      }
    </section>

    <div class="page-header">
      <div>
        <h2>Catalogue des services</h2>
        <p class="muted">Chaque service indique les informations de votre profil reprises dans la demande.</p>
      </div>
      <input type="search" placeholder="Rechercher un service…" (input)="filtre.set($any($event.target).value)" style="max-width: 280px" />
    </div>

    @if (error()) {
      <div class="alert error">{{ error() }}</div>
    }

    <div class="grid">
      @for (s of servicesFiltres(); track s.id) {
        <a class="card service" [routerLink]="['/services', s.slug]">
          <span class="badge">{{ s.categorie }}</span>
          <h3>{{ s.titre }}</h3>
          <p class="muted small">{{ s.administration }} · {{ s.delai_jours }} j</p>
          <p class="small">{{ s.description }}</p>
          <p class="infos">
            @for (i of s.infos_requises; track i) {
              <span class="badge backend">{{ labels[i] ?? i }}</span>
            }
          </p>
        </a>
      } @empty {
        @if (!loading()) {
          <p class="empty">Aucun service.</p>
        }
      }
    </div>
  `,
  styles: `
    .hero { margin-bottom: 2rem; }
    .service { color: inherit; text-decoration: none; display: flex; flex-direction: column; gap: 0.25rem; }
    .service:hover { border-color: var(--primary); }
    .service h3 { margin-top: 0.5rem; }
    .infos { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: auto; }
  `,
})
export class HomePage {
  private readonly api = inject(ApiService);
  protected readonly auth = inject(AuthService);
  protected readonly labels = INFO_LABELS;

  protected readonly services = signal<Service[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly filtre = signal('');

  protected readonly servicesFiltres = computed(() => {
    const f = this.filtre().toLowerCase().trim();
    return f
      ? this.services().filter((s) => `${s.titre} ${s.categorie} ${s.administration}`.toLowerCase().includes(f))
      : this.services();
  });

  constructor() {
    this.api.services().subscribe({
      next: (s) => {
        this.services.set(s);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(apiErrorMessage(e));
        this.loading.set(false);
      },
    });
  }
}

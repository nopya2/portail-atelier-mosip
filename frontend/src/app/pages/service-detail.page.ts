import { Component, effect, inject, input, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService, apiErrorMessage } from '../core/api.service';
import { AuthService } from '../core/auth/auth.service';
import { INFO_LABELS, formatValue } from '../core/labels';
import { Demande, Service } from '../core/models';

@Component({
  selector: 'app-service-detail',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <a routerLink="/" class="small">← Retour au catalogue</a>

    @if (service(); as s) {
      <div class="page-header">
        <div>
          <span class="badge">{{ s.categorie }}</span>
          <h1>{{ s.titre }}</h1>
          <p class="muted">{{ s.administration }} · délai indicatif {{ s.delai_jours }} jours</p>
        </div>
      </div>

      <div class="grid-2">
        <section class="card">
          <h2>Description</h2>
          <p>{{ s.description }}</p>

          <h3>Informations reprises de votre profil</h3>
          <dl class="kv">
            <dt>NIP</dt>
            <dd class="mono">{{ auth.user()?.nip ?? '—' }}</dd>
            @for (i of s.infos_requises; track i) {
              <dt>{{ labels[i] ?? i }}</dt>
              <dd>{{ auth.isAuthenticated() ? format(profil(i)) : '—' }}</dd>
            }
          </dl>
        </section>

        <section class="card">
          <h2>Déposer une demande</h2>

          @if (!auth.isAuthenticated()) {
            <p>Vous devez être connecté pour déposer une demande.</p>
            <div class="row">
              <a class="btn btn-primary" routerLink="/connexion" [queryParams]="{ retour: '/services/' + s.slug }">Se connecter</a>
              <a class="btn btn-secondary" routerLink="/inscription">Créer un compte</a>
            </div>
          } @else if (demande(); as d) {
            <div class="alert success">
              Demande enregistrée sous la référence <strong class="mono">{{ d.reference }}</strong>.
              Un accusé de réception a été envoyé à {{ auth.user()?.email }}.
            </div>
            <a routerLink="/mes-demandes" class="btn btn-secondary">Voir mes demandes</a>
          } @else {
            @if (error()) {
              <div class="alert error">
                {{ error() }}
                @if (profilIncomplet()) {
                  <br /><a routerLink="/profil">Compléter mon profil</a>
                }
              </div>
            }
            <form [formGroup]="form" (ngSubmit)="submit()">
              @for (c of s.champs ?? []; track c.name) {
                <div class="field">
                  <label [for]="c.name">{{ c.label }}{{ c.required ? ' *' : '' }}</label>
                  @switch (c.type) {
                    @case ('select') {
                      <select [id]="c.name" [formControlName]="c.name">
                        <option value="">—</option>
                        @for (o of c.options ?? []; track o) {
                          <option [value]="o">{{ o }}</option>
                        }
                      </select>
                    }
                    @default {
                      <input [id]="c.name" [type]="c.type" [formControlName]="c.name" />
                    }
                  }
                </div>
              }
              <button class="btn btn-primary" type="submit" [disabled]="busy() || form.invalid">
                {{ busy() ? 'Envoi…' : 'Envoyer la demande' }}
              </button>
            </form>
          }
        </section>
      </div>
    } @else if (error()) {
      <div class="alert error">{{ error() }}</div>
    } @else {
      <div class="spinner"></div>
    }
  `,
})
export class ServiceDetailPage {
  private readonly api = inject(ApiService);
  protected readonly auth = inject(AuthService);
  protected readonly labels = INFO_LABELS;
  protected readonly format = formatValue;

  readonly slug = input.required<string>();

  protected readonly service = signal<Service | null>(null);
  protected readonly demande = signal<Demande | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly profilIncomplet = signal(false);
  protected readonly busy = signal(false);
  protected form = new FormGroup<Record<string, FormControl<string | null>>>({});

  constructor() {
    effect(() => {
      const slug = this.slug();
      this.api.service(slug).subscribe({
        next: (s) => {
          this.form = new FormGroup(
            Object.fromEntries(
              (s.champs ?? []).map((c) => [c.name, new FormControl<string | null>('', c.required ? Validators.required : [])]),
            ),
          );
          this.service.set(s);
        },
        error: (e) => this.error.set(apiErrorMessage(e)),
      });
    });
  }

  protected profil(info: string): unknown {
    return (this.auth.user() as unknown as Record<string, unknown> | null)?.[info];
  }

  async submit(): Promise<void> {
    const s = this.service();
    if (!s || this.form.invalid) return;
    this.busy.set(true);
    this.error.set(null);
    this.profilIncomplet.set(false);
    this.api.deposerDemande(s.slug, this.form.getRawValue()).subscribe({
      next: (d) => {
        this.demande.set(d);
        this.busy.set(false);
      },
      error: (e) => {
        this.profilIncomplet.set(!!e?.error?.infos_manquantes);
        this.error.set(apiErrorMessage(e));
        this.busy.set(false);
      },
    });
  }
}

import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { Session, User } from '../models';

const TOKEN_KEY = 'portail.token';

/**
 * Session de l'usager : jeton Sanctum (sessionStorage) et profil courant.
 * Création de compte et connexion : uniquement avec eSignet (aucun mot de passe).
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly api = environment.apiUrl;

  private readonly _user = signal<User | null>(null);
  readonly user = this._user.asReadonly();
  readonly isAuthenticated = computed(() => this._user() !== null);
  readonly isAdmin = computed(() => this._user()?.is_admin === true);

  get token(): string | null {
    return sessionStorage.getItem(TOKEN_KEY);
  }

  /** Recharge le profil au démarrage si un jeton est présent. */
  async restore(): Promise<void> {
    if (!this.token) return;
    try {
      const res = await firstValueFrom(this.http.get<{ user: User }>(`${this.api}/auth/me`));
      this._user.set(res.user);
    } catch {
      this.clear();
    }
  }

  async updateProfile(changes: Partial<User>): Promise<User> {
    const res = await firstValueFrom(this.http.put<{ user: User }>(`${this.api}/auth/profile`, changes));
    this._user.set(res.user);
    return res.user;
  }

  async logout(): Promise<void> {
    try {
      await firstValueFrom(this.http.post(`${this.api}/auth/logout`, {}));
    } catch {
      // jeton déjà expiré : on nettoie quand même
    }
    this.clear();
    await this.router.navigateByUrl('/');
  }

  clear(): void {
    sessionStorage.removeItem(TOKEN_KEY);
    this._user.set(null);
  }

  /** Ouvre la session à partir de la réponse de l’API (connexion, inscription, eSignet). */
  ouvrirSession(session: Session): User {
    sessionStorage.setItem(TOKEN_KEY, session.token);
    this._user.set(session.user);
    return session.user;
  }
}

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { environment } from '../../environments/environment';
import {
  ApiError,
  AppLog,
  Demande,
  Paginated,
  Service,
} from './models';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  nipFormat() {
    return this.http.get<{ pattern: string; format: string; example: string }>(`${this.base}/auth/nip-format`);
  }

  services() {
    return this.http.get<Service[]>(`${this.base}/services`);
  }

  service(slug: string) {
    return this.http.get<Service>(`${this.base}/services/${slug}`);
  }

  deposerDemande(slug: string, donnees: Record<string, unknown>) {
    return this.http.post<Demande>(`${this.base}/services/${slug}/demandes`, { donnees });
  }

  mesDemandes() {
    return this.http.get<Demande[]>(`${this.base}/demandes`);
  }

  logs(filters: Record<string, string | number | null | undefined>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filters)) {
      if (v !== null && v !== undefined && v !== '') params = params.set(k, String(v));
    }
    return this.http.get<Paginated<AppLog>>(`${this.base}/admin/logs`, { params });
  }

  log(id: number) {
    return this.http.get<{ log: AppLog; related: AppLog[] }>(`${this.base}/admin/logs/${id}`);
  }

  logStats() {
    return this.http.get<{ periode: string; total: number; par_source: Record<string, Record<string, number>> }>(
      `${this.base}/admin/logs/stats`,
    );
  }
}

/** Message lisible à partir d'une erreur d'API, avec l'identifiant de requête pour le support. */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 0) return 'Le serveur est injoignable. Vérifiez que le backend est démarré.';
    const body = error.error as ApiError | null;
    const firstValidation = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    const message = firstValidation ?? body?.message ?? error.statusText;
    return body?.request_id ? `${message} (réf. ${body.request_id.slice(0, 8)})` : message;
  }
  return error instanceof Error ? error.message : 'Erreur inattendue';
}

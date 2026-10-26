import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth/auth.service';
import { LoggerService } from './logging/logger.service';

/**
 * - ajoute le jeton Bearer et un identifiant de corrélation X-Request-Id
 *   (repris par le backend dans ses propres logs) ;
 * - journalise les erreurs HTTP (réseau et 5xx en « error », 4xx inattendues en « warning ») ;
 * - déconnecte l'usager si le jeton a expiré (401).
 */
export const apiInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const logger = inject(LoggerService);
  const router = inject(Router);

  const requestId = crypto.randomUUID();
  const token = auth.token;
  const request = req.clone({
    setHeaders: {
      Accept: 'application/json',
      'X-Request-Id': requestId,
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  return next(request).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse) {
        const status = error.status;
        const backendId = error.headers?.get('X-Request-Id') ?? requestId;
        const expected = status === 401 || status === 422 || status === 404;

        if (!expected) {
          logger.log({
            // 501 = étape eSignet pas encore implémentée (atelier) : simple avertissement
            level: (status === 0 || status >= 500) && status !== 501 ? 'error' : 'warning',
            type: 'http_error',
            message:
              status === 0
                ? `API injoignable : ${req.method} ${req.urlWithParams}`
                : `HTTP ${status} sur ${req.method} ${req.urlWithParams} : ${error.error?.message ?? error.statusText}`,
            http: { method: req.method, url: req.urlWithParams, status, request_id: backendId },
            context: { error_code: error.error?.error },
          });
        }

        if (status === 401 && token) {
          auth.clear();
          router.navigate(['/connexion'], { queryParams: { expire: 1 } });
        }
      }
      return throwError(() => error);
    }),
  );
};

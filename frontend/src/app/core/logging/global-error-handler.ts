import { ErrorHandler, Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { LoggerService } from './logger.service';

/**
 * Capte toutes les erreurs non gérées de l'application Angular (composants,
 * observables, et — via provideBrowserGlobalErrorListeners — window.onerror et
 * les promesses rejetées) et les transmet au logger.
 */
@Injectable()
export class GlobalErrorHandler implements ErrorHandler {
  private readonly logger = inject(LoggerService);

  handleError(error: unknown): void {
    // Les erreurs HTTP sont déjà journalisées par l'intercepteur
    if (error instanceof HttpErrorResponse) {
      return;
    }

    const err = this.unwrap(error);
    const isRejection = typeof error === 'object' && error !== null && 'rejection' in error;

    this.logger.log({
      level: 'error',
      type: isRejection ? 'unhandled_rejection' : 'js_error',
      message: err?.message || String(error) || 'Erreur inconnue',
      name: err?.name,
      stack: err?.stack,
    });
  }

  private unwrap(error: unknown): Error | null {
    if (error instanceof Error) return error;
    if (typeof error === 'object' && error !== null) {
      const inner = (error as { rejection?: unknown; error?: unknown }).rejection ?? (error as { error?: unknown }).error;
      if (inner instanceof Error) return inner;
    }
    return null;
  }
}

<!doctype html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5">
    <h2 style="color: #0b6b3a">Votre demande a bien été reçue</h2>
    <p>Bonjour {{ $demande->identite['name'] ?? '' }},</p>
    <p>
        Votre demande <strong>« {{ $demande->service->titre }} »</strong>
        auprès de {{ $demande->service->administration }} a été enregistrée.
    </p>
    <table cellpadding="6" style="border-collapse: collapse">
        <tr><td>Référence</td><td><strong>{{ $demande->reference }}</strong></td></tr>
        <tr><td>NIP</td><td>{{ $demande->nip }}</td></tr>
        <tr><td>Date</td><td>{{ $demande->created_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Délai indicatif</td><td>{{ $demande->service->delai_jours }} jours</td></tr>
    </table>
    <p style="font-size: 12px; color: #6b7280">
        Message envoyé par le portail des services — environnement de démonstration.
    </p>
</body>
</html>

<?php

/*
| Messages de validation en français (sous-ensemble des règles utilisées par le portail).
*/
return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'email' => 'Le champ :attribute doit être une adresse email valide.',
    'in' => 'La valeur du champ :attribute est invalide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'nullable' => 'Le champ :attribute peut être vide.',
    'prohibited_unless' => 'Le champ :attribute ne peut pas être modifié.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire.',
    'size' => [
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',

    // Règle Password (IremboGov : 8 caractères, 1 chiffre, 1 majuscule, 1 minuscule, 1 caractère spécial)
    'password' => [
        'letters' => 'Le mot de passe doit contenir au moins une lettre.',
        'mixed' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
        'symbols' => 'Le mot de passe doit contenir au moins un caractère spécial (. ! * / - + …).',
        'uncompromised' => 'Ce mot de passe est apparu dans une fuite de données : choisissez-en un autre.',
    ],

    'attributes' => [
        'nip' => 'NIP',
        'canal' => 'mode de vérification',
        'telephone' => 'téléphone',
        'phone_number' => 'téléphone',
        'email' => 'email',
        'password' => 'mot de passe',
        'code' => 'code',
        'identifiant' => 'identifiant',
        'given_name' => 'prénom',
        'family_name' => 'nom',
        'birthdate' => 'date de naissance',
        'gender' => 'genre',
    ],
];

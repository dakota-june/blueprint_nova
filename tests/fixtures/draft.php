<?php
// The draft as Blueprint's YAML parser would hand it to the lexers (shorthands already expanded).
return [
    'models' => [
        'Invoice' => [
            'number' => 'string:20 unique',
            'status' => 'enum:draft,issued,paid',
            'total' => 'decimal:8,2',
            'notes' => 'text nullable',
            'meta' => 'json nullable',
            'issued_at' => 'timestamp nullable',
            'softdeletes' => 'softDeletes',
            'relationships' => ['hasMany' => 'Payment'],
        ],
        'Payment' => [
            'invoice_id' => 'id foreign',
            'amount' => 'decimal:8,2',
            'reference' => 'string nullable',
            'paid' => 'boolean',
        ],
        'User' => [
            'name' => 'string',
            'email' => 'string unique',
            'password' => 'string',
            'website' => 'string nullable',
            'remember_token' => 'rememberToken',
        ],
    ],
    'nova' => [
        'Invoice' => null,
        'Payment' => ['group' => 'Billing', 'fields' => ['paid' => ['label' => 'Settled', 'hide' => ['index']]]],
        'User' => null,
    ],
];

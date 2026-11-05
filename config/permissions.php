<?php

return [
    // Register new abilities here before exposing them through routes or policies.
    'abilities' => ['dashboard.view', 'account.manage', 'profile.manage'],
    'roles' => [
        'super_admin', 'owner', 'operational_admin', 'clinical_admin', 'coordinator',
        'doctor', 'nurse', 'carer', 'physiotherapist', 'patient', 'proxy',
        'clinic_referrer', 'finance', 'pharmacy', 'laboratory', 'supplier',
        'ambulance', 'privacy', 'auditor', 'security',
    ],
];

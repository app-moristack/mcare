<?php

return [
    // Register new abilities here before exposing them through routes or policies.
    'abilities' => ['dashboard.view', 'account.manage', 'profile.manage', 'patients.manage', 'referrals.manage', 'appointments.manage', 'tasks.manage', 'team.manage', 'finance.manage', 'audit.view'],
    'grants' => [
        'super_admin' => ['*'],
        'owner' => ['dashboard.view', 'patients.manage', 'referrals.manage', 'appointments.manage', 'tasks.manage', 'team.manage', 'finance.manage', 'audit.view'],
        'operational_admin' => ['dashboard.view', 'patients.manage', 'referrals.manage', 'appointments.manage', 'tasks.manage', 'team.manage'],
        'coordinator' => ['dashboard.view', 'patients.manage', 'referrals.manage', 'appointments.manage', 'tasks.manage'],
        'clinical_admin' => ['dashboard.view', 'patients.manage', 'referrals.manage', 'appointments.manage', 'tasks.manage'],
        'finance' => ['dashboard.view', 'finance.manage'],
    ],
    'roles' => [
        'super_admin', 'owner', 'operational_admin', 'clinical_admin', 'coordinator',
        'doctor', 'nurse', 'carer', 'physiotherapist', 'patient', 'proxy',
        'clinic_referrer', 'finance', 'pharmacy', 'laboratory', 'supplier',
        'ambulance', 'privacy', 'auditor', 'security',
    ],
];

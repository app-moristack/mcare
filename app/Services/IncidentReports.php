<?php

namespace App\Services;

use App\Models\OperationalIncident;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IncidentReports
{
    public const RULES = [
        'request_id' => 'required|uuid',
        'category' => 'required|in:service_delivery,safety,privacy,other',
        'severity' => 'required|in:routine,urgent',
        'description' => 'required|string|max:4000',
    ];

    public static function ensureReviewer(int $organisationId, int $reviewerId, int $reporterId): void
    {
        $reviewer = Workspace::staff($organisationId)->whereKeyNot($reporterId)->find($reviewerId);
        if (! $reviewer || ! $reviewer->hasPermission('incidents.manage', $organisationId)) {
            throw ValidationException::withMessages(['reviewer_id' => 'Choose a different active incident reviewer in this organisation.']);
        }
    }

    // Callers hold the organisation lock so retries across both report entry points serialize.
    public static function record(Request $request, int $organisationId, array $data, ?int $visitId, ?int $reviewerId = null): void
    {
        $reporterId = $request->user()->id;
        $attributes = [
            'organisation_id' => $organisationId,
            'visit_id' => $visitId,
            'reporter_id' => $reporterId,
            'reviewer_id' => $reviewerId,
            'category' => $data['category'],
            'severity' => $data['severity'],
            'description' => $data['description'],
        ];
        $hash = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
        $existing = OperationalIncident::where('organisation_id', $organisationId)->where('reporter_id', $reporterId)
            ->where('request_id', $data['request_id'])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->content_hash, $hash), 409, 'This request already recorded a different incident.');

            return;
        }

        if ($reviewerId !== null) {
            self::ensureReviewer($organisationId, $reviewerId, $reporterId);
        }
        $incident = OperationalIncident::create([
            ...$attributes,
            'request_id' => $data['request_id'],
            'status' => $reviewerId === null ? 'reported' : 'assigned',
            'assigned_by' => $reviewerId === null ? null : $reporterId,
            'assigned_at' => $reviewerId === null ? null : now(),
            'reported_at' => now(),
            'content_hash' => $hash,
        ]);
        Workspace::audit($request, 'incident.reported', $organisationId, 'operational_incidents', $incident->id);
        if ($reviewerId !== null) {
            Workspace::audit($request, 'incident.assigned', $organisationId, 'operational_incidents', $incident->id);
        }
    }
}

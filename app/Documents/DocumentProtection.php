<?php

namespace App\Documents;

use App\Exceptions\DocumentProtectionConflict;
use App\Models\DocumentSeries;
use App\Models\DocumentVersion;
use App\Models\InsurancePlanVersion;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DocumentProtection
{
    public function version(PatientDocument $document): DocumentVersion
    {
        app(TenantContext::class)->requireId();

        return DocumentVersion::query()->where('document_id', $document->id)->firstOrFail();
    }

    public function register(PatientDocument $document, UploadedFile $file, string $classification = 'clinical', ?DocumentSeries $series = null, string $provenance = 'Clinical upload', ?string $command = null, ?string $requestHash = null): DocumentVersion
    {
        $scan = app(EvidenceScanner::class)->scan($file);
        if (! in_array($scan['status'] ?? null, ['pending', 'clean', 'quarantined'], true)
            || (($scan['status'] ?? null) === 'clean' && empty($scan['reference']))) {
            throw new \LogicException('A clean verdict requires an approved scanner/policy reference.');
        }
        if ($series === null) {
            $series = new DocumentSeries;
            $series->forceFill(['patient_id' => $document->patient_id, 'owner_scope' => $document->patient_id === null ? 'tenant' : 'patient', 'classification' => $classification])->save();
        }
        $previous = DocumentVersion::query()->where('series_id', $series->id)->orderByDesc('version')->first();
        $version = new DocumentVersion;
        $hash = hash_file('sha256', $file->getRealPath());
        $version->forceFill([
            'series_id' => $series->id, 'document_id' => $document->id, 'version' => ($previous?->version ?? 0) + 1,
            'predecessor_id' => $previous?->id, 'content_sha256' => $hash, 'scan_status' => $scan['status'],
            'scanner_reference' => $scan['reference'], 'provenance' => $provenance,
            'command_key' => $command ?? (string) Str::uuid(), 'request_hash' => $requestHash ?? $hash,
        ])->save();
        DB::table('document_protections')->insert([
            'tenant_id' => $document->tenant_id, 'version_id' => $version->id,
            'quarantined' => $scan['status'] === 'quarantined' || ($classification !== 'clinical' && $scan['status'] !== 'clean'),
        ]);

        return $version;
    }

    /** Common order: series -> stored document -> protection/link/hold rows. */
    public function lock(PatientDocument $document): PatientDocument
    {
        $version = $this->version($document);
        DocumentSeries::query()->lockForUpdate()->findOrFail($version->series_id);

        return PatientDocument::withTrashed()->lockForUpdate()->findOrFail($document->id);
    }

    public function clinicalRead(User $user): bool
    {
        return $user->hasAnyRole([Role::ADMIN, Role::DENTIST]) || $user->canAccessResource('patient_documents', 'view');
    }

    public function clinicalWrite(User $user): bool
    {
        return $user->hasRole(Role::ADMIN) || ($user->hasRole(Role::DENTIST) && ! $user->hasRole(Role::RECEPTIONIST));
    }

    public function purposeAllowed(User $user, PatientDocument $document): bool
    {
        if (! $user->isMemberOfCurrentTenant() || (int) $document->tenant_id !== app(TenantContext::class)->id()) {
            return false;
        }
        $v = $this->version($document);
        $series = DocumentSeries::query()->findOrFail($v->series_id);
        $linked = DB::table('document_evidence_links')->where('tenant_id', $document->tenant_id)->whereIn('version_id', DocumentVersion::query()->where('series_id', $v->series_id)->select('id'));
        if ($series->owner_scope === 'tenant' && ! $user->hasPermission('insurance_documents.tenant_view')) {
            return false;
        }
        $insurance = $series->classification !== 'clinical' || (clone $linked)->exists();
        if (($series->classification !== 'insurance' && ! $this->clinicalRead($user))
            || ($insurance && ! $user->hasPermission('insurance_documents.view'))) {
            return false;
        }
        if ($insurance && $document->patient_id !== null && ! Gate::forUser($user)->allows('view', Patient::findOrFail($document->patient_id))) {
            return false;
        }
        foreach ($linked->get() as $link) {
            if ($link->plan_version_id !== null && ! Gate::forUser($user)->allows('view', InsurancePlanVersion::findOrFail($link->plan_version_id))) {
                return false;
            }
        }

        return true;
    }

    public function visible(Builder $query, User $user, ?Patient $patient = null): Builder
    {
        $clinical = $this->clinicalRead($user);
        $insurance = $user->hasPermission('insurance_documents.view') && ($patient === null || Gate::forUser($user)->allows('view', $patient));
        $tenant = app(TenantContext::class)->requireId();

        return $query->whereHas('versionRecord', function ($q) use ($clinical, $insurance, $tenant, $user) {
            $q->whereHas('series', fn ($s) => $s->whereIn('classification', array_filter([
                $clinical ? 'clinical' : null, $insurance ? 'insurance' : null, ($clinical && $insurance) ? 'mixed' : null,
            ]))->when(! $user->hasPermission('insurance_documents.tenant_view'), fn ($scope) => $scope->where('owner_scope', 'patient')))->whereExists(fn ($p) => $p->selectRaw('1')->from('document_protections')->whereColumn('version_id', 'document_versions.id')->where('tenant_id', $tenant)->where('quarantined', false)->where('purge_state', 'retained'));
            if (! $insurance) {
                $q->whereNotExists(fn ($l) => $l->selectRaw('1')->from('document_evidence_links')->join('document_versions as linked_version', 'linked_version.id', '=', 'document_evidence_links.version_id')->whereColumn('linked_version.series_id', 'document_versions.series_id'));
            }
            if (! $user->hasPermission('insurance_catalog.view')) {
                $q->whereNotExists(fn ($l) => $l->selectRaw('1')->from('document_evidence_links')->join('document_versions as linked_version', 'linked_version.id', '=', 'document_evidence_links.version_id')->whereColumn('linked_version.series_id', 'document_versions.series_id')->whereNotNull('plan_version_id'));
            }
        });
    }

    public function assertReadable(PatientDocument $document): void
    {
        $v = $this->version($document);
        $state = DB::table('document_protections')->where('tenant_id', $document->tenant_id)->where('version_id', $v->id)->first();
        abort_if($document->trashed(), 404);
        if ($state === null || $state->quarantined || $state->purge_state !== 'retained') {
            throw new DocumentProtectionConflict('DOCUMENT_QUARANTINED');
        }
    }

    public function assertDeletable(PatientDocument $document): void
    {
        $v = $this->version($document);
        if (DB::selectOne('SELECT evidence_retained(?) AS blocked', [$v->id])->blocked) {
            throw new DocumentProtectionConflict;
        }
    }

    public function state(PatientDocument $document, string $state): void
    {
        $v = $this->version($document);
        DB::table('document_protections')->where('tenant_id', $document->tenant_id)->where('version_id', $v->id)
            ->update(['purge_state' => $state, 'lock_version' => DB::raw('lock_version+1')]);
    }

    /** Acquire before business/series/document row locks; retain across purge commits and storage I/O. */
    public function seriesFence(PatientDocument $document, Closure $callback): mixed
    {
        $version = $this->version($document);

        return $this->fence('document-series:'.$version->tenant_id.':'.$version->series_id, $callback);
    }

    /** Recheck the entire series after synchronization, including sibling versions. */
    public function assertReferenceable(PatientDocument $document): void
    {
        $version = $this->version($document);
        if (DB::table('document_versions as v')
            ->join('patient_documents as d', 'd.id', '=', 'v.document_id')
            ->join('document_protections as p', 'p.version_id', '=', 'v.id')
            ->where('v.tenant_id', $version->tenant_id)->where('v.series_id', $version->series_id)
            ->where(fn ($q) => $q->whereNotNull('d.deleted_at')->orWhere('p.purge_state', '<>', 'retained'))->exists()) {
            throw new DocumentProtectionConflict('DOCUMENT_PURGE_IN_PROGRESS');
        }
    }

    /** Session advisory fence survives transaction commits and releases on process/connection loss. */
    public function fence(string $key, Closure $callback): mixed
    {
        $connection = DB::connection();
        if (! $connection->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired', [$key])->acquired) {
            throw new DocumentProtectionConflict('DOCUMENT_PURGE_IN_PROGRESS');
        }
        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$key]);
        }
    }

    public function assertStoragePurge(PatientDocument $document): void
    {
        // Also protects direct adapter calls, including forged unsaved model instances.
        $stored = PatientDocument::withoutGlobalScopes()->where('storage_key', $document->storage_key)->first();
        if ($stored === null) {
            return; // Compensating cleanup of an object with no committed metadata.
        }
        $this->assertDeletable($stored);
        $state = DB::table('document_protections')->where('version_id', $this->version($stored)->id)->value('purge_state');
        if (! $stored->trashed() || $state !== 'purge_in_progress') {
            throw new DocumentProtectionConflict;
        }
    }

    /** Verify exact bytes before stream-open audit; never return corrupt evidence. */
    public function verifiedStream(PatientDocument $document, $stream)
    {
        $expected = $this->version($document)->content_sha256;
        if ($expected === null) {
            return $stream; // Historical clinical bytes were never hashed; cannot be linked.
        }
        $copy = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            $hash = hash_init('sha256');
            while (! feof($stream)) {
                $bytes = fread($stream, 65536);
                if ($bytes === false) {
                    throw new \RuntimeException('Private document stream unavailable.');
                }
                hash_update($hash, $bytes);
                if (fwrite($copy, $bytes) !== strlen($bytes) || ftell($copy) > $document->size_bytes) {
                    throw new DocumentProtectionConflict('DOCUMENT_QUARANTINED');
                }
            }
            if (! hash_equals($expected, hash_final($hash))) {
                throw new DocumentProtectionConflict('DOCUMENT_QUARANTINED');
            }
            rewind($copy);

            return $copy;
        } catch (\Throwable $e) {
            fclose($copy);
            throw $e;
        } finally {
            fclose($stream);
        }
    }

    public function quarantine(PatientDocument $document, User $actor): void
    {
        app(TenantContext::class)->requireId();
        Gate::forUser($actor)->authorize('quarantine', $document);
        $this->persistQuarantine($document, $actor, 'quarantine');
    }

    public function quarantineCorruptVersion(PatientDocument $document, User $actor): void
    {
        app(TenantContext::class)->requireId();
        Gate::forUser($actor)->authorize('view', $document);
        try {
            $verified = $this->verifiedStream($document, app(DocumentStorage::class)->read($document));
            fclose($verified);
        } catch (DocumentProtectionConflict $error) {
            if ($error->apiCode !== 'DOCUMENT_QUARANTINED') {
                throw $error;
            }
            $this->persistQuarantine($document, $actor, 'view');
        }
    }

    private function persistQuarantine(PatientDocument $document, User $actor, string $ability): void
    {
        DB::transaction(function () use ($document, $actor, $ability) {
            $document = $this->lock($document);
            Gate::forUser($actor)->authorize($ability, $document);
            if (DB::table('document_protections')->where('version_id', $this->version($document)->id)->value('quarantined')) {
                return;
            }
            DB::table('document_protections')->where('version_id', $this->version($document)->id)->update(['quarantined' => true, 'lock_version' => DB::raw('lock_version+1')]);
            app(AuditLogService::class)->record('quarantined', 'patient_documents', $document->id, $actor->id);
        });
    }
}

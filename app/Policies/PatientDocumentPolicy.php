<?php

namespace App\Policies;

use App\Documents\DocumentProtection;
use App\Models\PatientDocument;
use App\Models\User;

class PatientDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(DocumentProtection::class)->clinicalRead($user) || $user->hasPermission('insurance_documents.view');
    }

    public function view(User $user, PatientDocument $document): bool
    {
        return app(DocumentProtection::class)->purposeAllowed($user, $document);
    }

    public function download(User $user, PatientDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function create(User $user): bool
    {
        return app(DocumentProtection::class)->clinicalWrite($user);
    }

    public function archive(User $user, PatientDocument $document): bool
    {
        $protection = app(DocumentProtection::class);
        $classification = $protection->version($document)->series->classification;

        return $this->view($user, $document)
            && ($classification === 'insurance' ? $user->hasPermission('insurance_documents.upload') : $protection->clinicalWrite($user));
    }

    public function delete(User $user, PatientDocument $document): bool
    {
        return $this->archive($user, $document);
    }

    public function hold(User $user, PatientDocument $document): bool
    {
        return $user->hasPermission('document_holds.manage') && $this->view($user, $document);
    }

    public function quarantine(User $user, PatientDocument $document): bool
    {
        return $user->hasPermission('document_quarantine.manage') && $this->view($user, $document);
    }
}

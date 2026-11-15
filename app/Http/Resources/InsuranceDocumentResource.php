<?php

namespace App\Http\Resources;

use App\Documents\DocumentProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsuranceDocumentResource extends PatientDocumentResource
{
    public function toArray(Request $request): array
    {
        $v = app(DocumentProtection::class)->version($this->resource);
        $s = $v->series;
        $quarantined = (bool) DB::table('document_protections')->where('version_id', $v->id)->value('quarantined');

        return [...parent::toArray($request), 'series_id' => $s->id, 'owner_scope' => $s->owner_scope, 'classification' => $s->classification,
            'version_id' => $v->id, 'version' => $v->version, 'predecessor_id' => $v->predecessor_id,
            'content_sha256' => $v->content_sha256, 'scan_status' => $v->scan_status, 'quarantined' => $quarantined,
        ];
    }
}

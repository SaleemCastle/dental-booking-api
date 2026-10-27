<?php

return [
    'disk' => 'clinical_documents',
    'max_size_kb' => (int) env('PATIENT_DOCUMENT_MAX_SIZE_KB', 20480),
    'mime_extensions' => [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ],
];

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsurerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name,
            'active' => $this->active, 'lock_version' => $this->lock_version,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}

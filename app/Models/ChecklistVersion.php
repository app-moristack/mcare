<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'published_at' => 'datetime'];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([
            'checklist_template_id' => $this->checklist_template_id,
            'version' => $this->version,
            'steps' => $this->steps,
        ], JSON_THROW_ON_ERROR));
    }
}

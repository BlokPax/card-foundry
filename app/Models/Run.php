<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Run extends Model
{
    protected $guarded = [];

    protected $casts = [
        'art_dirs' => 'array',
        'treatments' => 'array',
        'proof' => 'boolean',
        'export_options' => 'array',
        'pipeline' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RunItem::class);
    }

    public function appendLog(string $line): void
    {
        $this->log = ($this->log ? $this->log."\n" : '').'['.now()->format('H:i:s').'] '.$line;
        $this->save();

        Log::channel('runs')->info("[run {$this->id}] {$line}");
    }

    public function progressPercent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) floor((($this->completed + $this->failed) / $this->total) * 100);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }
}

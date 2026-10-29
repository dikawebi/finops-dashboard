<?php

namespace App\Services;

use App\Models\GlImportBatch;
use App\Models\GlEntry;
use Illuminate\Support\Facades\DB;

class GlImportService
{
    public function import(array $rows, string $filename, ?string $note = null): GlImportBatch
    {
        return DB::transaction(function () use ($rows, $filename, $note) {
            $periods = collect($rows)->groupBy('period');
            $batch = GlImportBatch::create(['filename' => $filename, 'source' => 'seed', 'row_count' => count($rows), 'months_covered' => $periods->keys()->values()->all(), 'note' => $note, 'imported_at' => now()]);
            foreach ($periods as $period => $items) {
                GlEntry::where('period', $period)->delete();
                // Keep packets comfortably below MySQL max_allowed_packet; D365 descriptions can be long.
                foreach (array_chunk($items->all(), 100) as $chunk) {
                    $chunk = array_map(fn ($row) => array_merge($row, ['batch_id' => $batch->id]), $chunk);
                    GlEntry::insert($chunk);
                }
            }
            return $batch;
        });
    }
}

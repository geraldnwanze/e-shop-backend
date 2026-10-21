<?php

namespace App\Console\Commands;

use App\Services\Reviews\ReviewPhotoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reviews:prune-photos {--hours=24 : How long an unused upload is kept}')]
#[Description('Delete review photos that were uploaded but never added to a review')]
class PruneReviewPhotos extends Command
{
    public function handle(ReviewPhotoService $photos): int
    {
        $count = $photos->pruneOrphans(max(1, (int) $this->option('hours')));

        $this->info("Deleted {$count} unused review photo(s).");

        return self::SUCCESS;
    }
}

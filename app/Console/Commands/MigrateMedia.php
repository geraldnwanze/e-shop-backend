<?php

namespace App\Console\Commands;

use App\Enums\MediaProviderType;
use App\Models\Media;
use App\Services\Media\MediaMigrationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('media:migrate {from : The provider the images are on now (cloudinary or spaces)} {to : The provider to move them to} {--limit= : Move at most this many images in this run} {--delete-source : Remove each original once it has been moved} {--dry-run : Only count what would move}')]
#[Description('Move stored images to another media provider (e.g. Cloudinary to DigitalOcean Spaces). Safe to stop and run again.')]
class MigrateMedia extends Command
{
    public function handle(MediaMigrationService $migration): int
    {
        $from = MediaProviderType::tryFrom((string) $this->argument('from'));
        $to = MediaProviderType::tryFrom((string) $this->argument('to'));

        if ($from === null || $to === null || $from === $to) {
            $this->error('Give two different providers: cloudinary or spaces.');

            return self::INVALID;
        }

        $query = Media::withoutTenantScope()->where('provider', $from->value)->orderBy('id');
        $total = (clone $query)->count();
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        if ($this->option('dry-run')) {
            $this->info("{$total} image(s) are on {$from->value} and would move to {$to->value}.");

            return self::SUCCESS;
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $moved = 0;
        $failed = 0;

        $query->get()->each(function (Media $media) use ($migration, $to, &$moved, &$failed): void {
            try {
                $migration->move($media, $to, (bool) $this->option('delete-source'));
                $moved++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Skipped {$media->path}: {$e->getMessage()}");
            }
        });

        $this->info("Moved {$moved} of {$total} image(s) to {$to->value}".($failed > 0 ? ", {$failed} failed (run again to retry)." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

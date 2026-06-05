<?php

namespace App\Services;

use App\Models\ContentImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationFinalizer
{
    public function __construct(private readonly CreditWalletService $wallets)
    {
    }

    public function markContentImageFailedAndRefundIfNeeded(
        ContentImage $image,
        string $reason,
        ?string $errorMessage = null
    ): ?ContentImage {
        return DB::transaction(function () use ($image, $reason, $errorMessage): ?ContentImage {
            $locked = ContentImage::query()->whereKey($image->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            $locked->status = 'failed';
            if ($errorMessage !== null) {
                $locked->error_message = Str::limit($errorMessage, 5000, '');
            }
            $locked->save();

            if (! $locked->hasOutput()) {
                $this->wallets->ensureReleasedForContentImage($locked, $reason);
            }

            return $locked->fresh();
        });
    }
}

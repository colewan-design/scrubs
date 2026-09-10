<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'position' => 'integer'];
    }

    /**
     * Where a browser should actually fetch this image.
     *
     * Two shapes coexist and both are legitimate. A photo uploaded through the
     * admin is a path on the public disk ("products/abc.jpg"). Seeded
     * placeholder imagery is a root-relative URL the storefront serves itself
     * ("/placeholders/..."). Anything already absolute or already rooted is
     * passed through untouched; everything else is resolved against the disk.
     *
     * API resources must serialise this rather than `path`, or uploaded photos
     * 404 against the storefront's origin.
     */
    protected function url(): Attribute
    {
        return Attribute::get(function (): string {
            $path = (string) $this->path;

            if ($path === '' || str_starts_with($path, 'http') || str_starts_with($path, '/')) {
                return $path;
            }

            return Storage::disk('public')->url($path);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }
}
